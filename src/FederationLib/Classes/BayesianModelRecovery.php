<?php

    namespace FederationLib\Classes;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use BayesianPlugin\Objects\BayesianServer\ModelStatistics;
    use FederationLib\Classes\Configuration\BayesianModelConfiguration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Exceptions\DatabaseOperationException;
    use RuntimeException;
    use Throwable;

    class BayesianModelRecovery
    {
        public const string PLUGIN_PACKAGE = 'net.nosial.bayesian_plugin';
        private const string PROBE_TEXT = 'FederationLib Bayesian model health check';
        private const int TRAINING_PAGE_SIZE = 100;
        private const int LEARN_ATTEMPTS = 5;

        /**
         * Runs the Bayesian model stage, the stage is skipped if it is disabled, if BayesianPlugin is not enabled or
         * if the start/stop scripts are not available (eg; outside the docker image). The temporarily started server
         * is always stopped before returning.
         *
         * @return bool True if the model can be used (or the stage was skipped), false if the model is broken and
         *              could not be repaired
         */
        public static function run(): bool
        {
            $configuration = Configuration::getBayesianModelConfiguration();
            if(!$configuration->isEnabled())
            {
                Logger::log()->info('Bayesian model check is disabled, skipping');
                return true;
            }

            if(!in_array(self::PLUGIN_PACKAGE, Configuration::getPluginsConfiguration()->getPlugins(), true))
            {
                Logger::log()->info('BayesianPlugin is not enabled, skipping the Bayesian model check');
                return true;
            }

            // The plugin's package is imported when the plugins are validated
            if(!class_exists(BayesianPlugin::class))
            {
                Logger::log()->warning('BayesianPlugin is enabled but not available, skipping the Bayesian model check');
                return true;
            }

            foreach([$configuration->getStartScript(), $configuration->getStopScript()] as $script)
            {
                if(!is_file($script) || !is_executable($script))
                {
                    Logger::log()->info(sprintf('%s is not available (BayesianServer is not bundled), skipping the Bayesian model check', $script));
                    return true;
                }
            }

            try
            {
                $result = self::checkModel($configuration);
            }
            catch(Throwable $e)
            {
                Logger::log()->critical('Bayesian model check failed: ' . $e->getMessage(), $e);
                $result = false;
            }

            // The services start BayesianServer themselves, the temporary server must not be left running
            if(!self::stopServer($configuration))
            {
                Logger::log()->critical('Failed to stop the temporary BayesianServer');
                return false;
            }

            return $result;
        }

        /**
         * Starts BayesianServer temporarily and checks its model, the model is rebuilt if it is broken or if it is
         * empty while there's enough training data
         *
         * @param BayesianModelConfiguration $configuration The configuration
         * @return bool True if the model can be used
         * @throws DatabaseOperationException If the classified evidence records could not be retrieved
         * @throws RuntimeException If the model directory could not be backed up or created
         */
        private static function checkModel(BayesianModelConfiguration $configuration): bool
        {
            Logger::log()->info('Starting BayesianServer temporarily to check the Bayesian model');
            if(!self::startServer($configuration))
            {
                // A model that can't be loaded prevents BayesianServer from starting
                Logger::log()->warning('BayesianServer did not become reachable, the Bayesian model is considered broken');
                return self::rebuildModel($configuration);
            }

            $diagnosis = self::diagnoseModel(BayesianPlugin::getClient());
            if($diagnosis['problem'] !== null)
            {
                Logger::log()->warning('The Bayesian model is broken: ' . $diagnosis['problem']);
                return self::rebuildModel($configuration);
            }

            $model = $diagnosis['model'];
            if($model->getTotalDocuments() > 0)
            {
                Logger::log()->info(sprintf('Bayesian model OK (%d document(s), %d label(s))', $model->getTotalDocuments(), $model->getLabelCount()));
                return true;
            }

            $classifiedEvidence = EvidenceManager::countClassifiedEvidence();
            if($classifiedEvidence < $configuration->getMinimumEvidence())
            {
                Logger::log()->info(sprintf('The Bayesian model is empty, %d classified evidence record(s) available and at least %d are required to train it', $classifiedEvidence, $configuration->getMinimumEvidence()));
                return true;
            }

            Logger::log()->info(sprintf('The Bayesian model is empty, %d classified evidence record(s) are available to train it', $classifiedEvidence));
            return self::rebuildModel($configuration);
        }

        /**
         * Replaces the model with a new model, BayesianServer is stopped while the model directory is moved to the
         * backup directory and started again with the new (empty) model, which is then trained with the classified
         * evidence records if there are enough of them
         *
         * @param BayesianModelConfiguration $configuration The configuration
         * @return bool True if the new model can be used
         * @throws DatabaseOperationException If the classified evidence records could not be retrieved
         * @throws RuntimeException If the model directory could not be backed up or created
         */
        private static function rebuildModel(BayesianModelConfiguration $configuration): bool
        {
            if(!self::stopServer($configuration))
            {
                Logger::log()->critical('Failed to stop BayesianServer to rebuild the Bayesian model');
                return false;
            }

            $archivePath = self::backupModel($configuration);

            Logger::log()->info('Starting BayesianServer with a new Bayesian model');
            if(!self::startServer($configuration))
            {
                Logger::log()->critical('BayesianServer did not become reachable with a new Bayesian model');
                return false;
            }

            $client = BayesianPlugin::getClient();
            $classifiedEvidence = EvidenceManager::countClassifiedEvidence();
            $archivedDocuments = $archivePath === null ? [] : self::readArchive($archivePath);
            if($classifiedEvidence + count($archivedDocuments) < $configuration->getMinimumEvidence())
            {
                Logger::log()->info(sprintf('Created a new Bayesian model, %d classified evidence record(s) and %d archived document(s) available and at least %d are required to train it',
                    $classifiedEvidence, count($archivedDocuments), $configuration->getMinimumEvidence()));
                return self::verifyModel($client);
            }

            Logger::log()->info(sprintf('Training the new Bayesian model with %d archived document(s) and %d classified evidence record(s)', count($archivedDocuments), $classifiedEvidence));
            if(!self::trainModel($client, $archivedDocuments, $classifiedEvidence, $configuration->getLearningTimeout()))
            {
                return false;
            }

            return self::verifyModel($client);
        }

        /**
         * Checks the health of the model, the model is broken if BayesianServer does not respond correctly, if its
         * statistics are invalid or if it contains labels that are not classification flags (the model is not used
         * by BayesianPlugin in that case, see BayesianPlugin's Classifier)
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @return array{model: ModelStatistics|null, problem: string|null} The model statistics and the reason the
         *               model is broken, the problem is null if the model can be used
         */
        private static function diagnoseModel(BayesianClientInterface $client): array
        {
            try
            {
                $model = $client->getStatus()->getModel();
            }
            catch(Throwable $e)
            {
                return ['model' => null, 'problem' => 'failed to retrieve the model status: ' . $e->getMessage()];
            }

            if($model->getTotalDocuments() < 0)
            {
                return ['model' => $model, 'problem' => sprintf('invalid number of documents %d', $model->getTotalDocuments())];
            }

            foreach($model->getLabels() as $label)
            {
                if(ClassificationFlag::tryFrom($label->getLabel()) === null)
                {
                    return ['model' => $model, 'problem' => sprintf('unknown label "%s"', $label->getLabel())];
                }

                if($label->getDocumentCount() < 0)
                {
                    return ['model' => $model, 'problem' => sprintf('invalid number of documents %d for the label %s', $label->getDocumentCount(), $label->getLabel())];
                }
            }

            if($model->getTotalDocuments() === 0)
            {
                return ['model' => $model, 'problem' => null];
            }

            // Every document has at least one label
            if(count($model->getLabels()) === 0)
            {
                return ['model' => $model, 'problem' => sprintf('%d document(s) without any label', $model->getTotalDocuments())];
            }

            try
            {
                $client->classify(self::PROBE_TEXT);
            }
            catch(Throwable $e)
            {
                return ['model' => $model, 'problem' => 'classification failed: ' . $e->getMessage()];
            }

            return ['model' => $model, 'problem' => null];
        }

        /**
         * Checks the health of the rebuilt model
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @return bool True if the model can be used
         */
        private static function verifyModel(BayesianClientInterface $client): bool
        {
            $diagnosis = self::diagnoseModel($client);
            if($diagnosis['problem'] !== null)
            {
                Logger::log()->critical('The rebuilt Bayesian model is broken: ' . $diagnosis['problem']);
                return false;
            }

            Logger::log()->info(sprintf('Bayesian model rebuilt (%d document(s), %d label(s))', $diagnosis['model']->getTotalDocuments(), $diagnosis['model']->getLabelCount()));
            return true;
        }

        /**
         * Trains the model with the documents of the backed up archive, which hold everything the previous model
         * learned (including evidence that has since been deleted, eg; by the retention cleanup), then with every
         * classified evidence record in the order it was classified, so the latest classification of a text wins.
         * Waits for BayesianServer to process the training, and records the last classification so the next
         * `federationlib bayesian-sync` doesn't learn them again.
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param array<string, array{0: string[], 1: float}> $archivedDocuments The archived documents by text
         * @param int $total The number of classified evidence records, used to report the progress
         * @param int $timeout The maximum number of seconds to wait for BayesianServer to accept or process training
         * @return bool True if the model was trained
         * @throws DatabaseOperationException If the classified evidence records could not be retrieved
         */
        private static function trainModel(BayesianClientInterface $client, array $archivedDocuments, int $total, int $timeout): bool
        {
            $submitted = 0;
            $maximumReached = false;
            try
            {
                foreach($archivedDocuments as $text => [$labels, $weight])
                {
                    if(!self::learn($client, (string)$text, $labels, $weight, $timeout))
                    {
                        $maximumReached = true;
                        break;
                    }

                    if(++$submitted % 1000 === 0)
                    {
                        Logger::log()->info(sprintf('Submitted %d of %d archived document(s) for training', $submitted, count($archivedDocuments)));
                    }
                }

                $replayed = 0;
                $result = $maximumReached ? null : BayesianSync::replay(null, function(string $text, string $label) use ($client, $timeout, $total, &$replayed, &$maximumReached): bool
                {
                    if(!self::learn($client, $text, [$label], 1.0, $timeout))
                    {
                        $maximumReached = true;
                        return false;
                    }

                    if(++$replayed % self::TRAINING_PAGE_SIZE === 0)
                    {
                        Logger::log()->info(sprintf('Submitted %d of %d classified evidence record(s) for training', $replayed, $total));
                    }

                    return true;
                });
            }
            catch(Throwable $e)
            {
                Logger::log()->critical('Failed to train the Bayesian model: ' . $e->getMessage(), $e);
                return false;
            }

            if($maximumReached)
            {
                Logger::log()->warning('BayesianServer reached its maximum number of documents, the remaining documents were not submitted');
            }

            if($result !== null)
            {
                BayesianSync::setPosition($result['position']);
            }

            return self::awaitLearning($client, $timeout);
        }

        /**
         * Submits a document for training, if the learning queue of BayesianServer is full the document is submitted
         * again once there is room. Request failures are retried a few times.
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param string $text The text of the document
         * @param string[] $labels The labels of the document
         * @param float $weight The weight of the document
         * @param int $timeout The maximum number of seconds to wait for room in the learning queue
         * @return bool True if the document was accepted, false if BayesianServer reached its maximum number of documents
         * @throws BayesianException If the document could not be submitted
         * @throws RuntimeException If the learning queue remained full for too long
         */
        private static function learn(BayesianClientInterface $client, string $text, array $labels, float $weight, int $timeout): bool
        {
            $deadline = time() + $timeout;
            $attempt = 0;

            while(true)
            {
                try
                {
                    // A weight of 1 is the server's default, older servers don't know the parameter
                    $result = $client->learn($text, $labels, $weight === 1.0 ? null : $weight);
                }
                catch(BayesianException $e)
                {
                    if(++$attempt >= self::LEARN_ATTEMPTS)
                    {
                        throw $e;
                    }

                    Logger::log()->warning(sprintf('Bayesian learn failed (attempt %d of %d): %s', $attempt, self::LEARN_ATTEMPTS, $e->getMessage()));
                    sleep(1);
                    continue;
                }

                if($result->isAccepted())
                {
                    return true;
                }

                if($result->getRejectedMaxDocs() > 0)
                {
                    return false;
                }

                // The learning queue is full
                if(time() >= $deadline)
                {
                    throw new RuntimeException(sprintf('The learning queue of BayesianServer remained full for %d seconds', $timeout));
                }

                usleep(500000);
            }
        }

        /**
         * Reads the documents of a training archive as BayesianServer wrote it (labels,content[,weight]): the
         * latest labels and weight of every text, a row without labels (a forgotten text) removes the text
         *
         * @param string $archivePath The path of the archive
         * @return array<string, array{0: string[], 1: float}> The labels and weight of every text, in the order texts
         *                                                     were last learned
         */
        private static function readArchive(string $archivePath): array
        {
            $handle = @fopen($archivePath, 'r');
            if($handle === false)
            {
                Logger::log()->warning(sprintf('Unable to read the Bayesian archive %s, the model is trained without it', $archivePath));
                return [];
            }

            $documents = [];
            try
            {
                $header = fgetcsv($handle, escape: '');
                $labelsColumn = $header === false ? false : array_search('labels', $header, true);
                $contentColumn = $header === false ? false : array_search('content', $header, true);
                if($labelsColumn === false || $contentColumn === false)
                {
                    Logger::log()->warning(sprintf('The Bayesian archive %s has no labels and content columns, the model is trained without it', $archivePath));
                    return [];
                }

                // Archives written before weights existed have no weight column, their rows may still have a weight
                $weightColumn = array_search('weight', $header, true);
                $weightColumn = $weightColumn === false ? max($labelsColumn, $contentColumn) + 1 : $weightColumn;
                while(($row = fgetcsv($handle, escape: '')) !== false)
                {
                    $content = $row[$contentColumn] ?? null;
                    if(!is_string($content) || $content === '')
                    {
                        continue;
                    }

                    // Re-inserting moves the text to the end, so the documents stay in the order texts were last learned
                    unset($documents[$content]);
                    $labels = array_values(array_filter(explode(';', $row[$labelsColumn] ?? ''), fn(string $label) => $label !== ''));
                    if(count($labels) === 0)
                    {
                        continue;
                    }

                    $weight = (float)($row[$weightColumn] ?? 1.0);
                    $documents[$content] = [$labels, $weight > 0.0 ? $weight : 1.0];
                }
            }
            finally
            {
                fclose($handle);
            }

            return $documents;
        }

        /**
         * Waits for BayesianServer to process every submitted training document
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param int $timeout The maximum number of seconds to wait
         * @return bool True if the training was processed
         */
        private static function awaitLearning(BayesianClientInterface $client, int $timeout): bool
        {
            $deadline = time() + $timeout;

            while(true)
            {
                try
                {
                    $learning = $client->getStatus()->getLearning();
                    if($learning->getPending() === 0 && $learning->getProcessed() + $learning->getFailed() >= $learning->getSubmitted())
                    {
                        if($learning->getFailed() > 0)
                        {
                            Logger::log()->warning(sprintf('BayesianServer failed to process %d training document(s)', $learning->getFailed()));
                        }

                        Logger::log()->info(sprintf('BayesianServer processed %d training document(s)', $learning->getProcessed()));
                        return true;
                    }
                }
                catch(BayesianException $e)
                {
                    Logger::log()->warning('Failed to retrieve the learning status of BayesianServer: ' . $e->getMessage());
                }

                if(time() >= $deadline)
                {
                    Logger::log()->critical(sprintf('BayesianServer did not process the training within %d seconds', $timeout));
                    return false;
                }

                sleep(1);
            }
        }

        /**
         * Moves the model directory and the archive to a timestamped directory in the backup directory and creates an
         * empty model directory in its place. The new model is trained with the backed up archive and the evidence
         * records. An empty model directory and an archive without any documents are not backed up.
         *
         * @param BayesianModelConfiguration $configuration The configuration
         * @return string|null The path of the backed up archive, null if there was no archive with documents
         * @throws RuntimeException If the model directory or the archive could not be backed up, or if the model
         *                          directory could not be created
         */
        private static function backupModel(BayesianModelConfiguration $configuration): ?string
        {
            $backedUpArchive = null;
            $modelPath = $configuration->getModelPath();
            $archivePath = $configuration->getArchivePath();
            $backupModel = is_dir($modelPath) && count(array_diff(scandir($modelPath) ?: [], ['.', '..'])) > 0;
            $backupArchive = self::hasArchivedDocuments($archivePath);

            if($backupModel || $backupArchive)
            {
                $backupPath = $configuration->getBackupPath();
                $destination = $backupPath . DIRECTORY_SEPARATOR . date('Y-m-d_H-i-s');
                for($i = 1; file_exists($destination); $i++)
                {
                    $destination = sprintf('%s%s%s_%d', $backupPath, DIRECTORY_SEPARATOR, date('Y-m-d_H-i-s'), $i);
                }

                if(!mkdir($destination, 0755, true) && !is_dir($destination))
                {
                    throw new RuntimeException(sprintf('Failed to create the backup directory %s', $destination));
                }

                if($backupModel && !rename($modelPath, $destination . DIRECTORY_SEPARATOR . basename($modelPath)))
                {
                    throw new RuntimeException(sprintf('Failed to move the Bayesian model %s to %s', $modelPath, $destination));
                }

                // BayesianServer creates a new archive when it's started, the new model is trained with the backed up one
                if($backupArchive)
                {
                    $backedUpArchive = $destination . DIRECTORY_SEPARATOR . basename($archivePath);
                    if(!rename($archivePath, $backedUpArchive))
                    {
                        throw new RuntimeException(sprintf('Failed to move the Bayesian archive %s to %s', $archivePath, $destination));
                    }
                }

                Logger::log()->info(sprintf('Backed up the Bayesian %s to %s', $backupModel && $backupArchive ? 'model and archive' : ($backupModel ? 'model' : 'archive'), $destination));
            }

            if(!is_dir($modelPath) && !mkdir($modelPath, 0755, true) && !is_dir($modelPath))
            {
                throw new RuntimeException(sprintf('Failed to create the Bayesian model directory %s', $modelPath));
            }

            return $backedUpArchive;
        }

        /**
         * Returns True if the archive contains at least one document, an archive that only contains the header row
         * (labels,content) is empty
         *
         * @param string $archivePath The path of the archive
         * @return bool True if the archive contains documents
         */
        private static function hasArchivedDocuments(string $archivePath): bool
        {
            if(!is_file($archivePath))
            {
                return false;
            }

            $handle = fopen($archivePath, 'r');
            if($handle === false)
            {
                // An archive that can't be read is backed up all the same
                return true;
            }

            // The header row, followed by the first document
            fgets($handle);
            $document = fgets($handle);
            fclose($handle);

            return $document !== false && trim($document) !== '';
        }

        /**
         * Starts BayesianServer temporarily with the start script, which only exits once the server is reachable
         *
         * @param BayesianModelConfiguration $configuration The configuration
         * @return bool True if the server is reachable
         */
        private static function startServer(BayesianModelConfiguration $configuration): bool
        {
            return self::executeScript($configuration->getStartScript()) === 0;
        }

        /**
         * Stops the temporarily started BayesianServer with the stop script, the server saves the model when it stops
         *
         * @param BayesianModelConfiguration $configuration The configuration
         * @return bool True if the server is stopped
         */
        private static function stopServer(BayesianModelConfiguration $configuration): bool
        {
            return self::executeScript($configuration->getStopScript()) === 0;
        }

        /**
         * Executes a script without a shell, the script writes to the same output as FederationLib so that its output
         * and the output of the processes it starts are visible in the container's logs. The output is not captured,
         * a captured output would keep the script running for as long as the processes it starts in the background.
         *
         * @param string $script The path of the script
         * @return int The exit code of the script, -1 if it could not be executed
         */
        private static function executeScript(string $script): int
        {
            Logger::log()->debug(sprintf('Executing %s', $script));
            $process = proc_open([$script], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
            if($process === false)
            {
                Logger::log()->error(sprintf('Failed to execute %s', $script));
                return -1;
            }

            $exitCode = proc_close($process);
            if($exitCode !== 0)
            {
                Logger::log()->warning(sprintf('%s exited with code %d', $script, $exitCode));
            }

            return $exitCode;
        }
    }
