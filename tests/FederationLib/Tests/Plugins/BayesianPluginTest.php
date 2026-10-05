<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Plugins;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TextGenerator;
    use FederationLib\Objects\ContentInput;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;
    use Throwable;

    /**
     * Integration tests of BayesianPlugin (net.nosial.bayesian_plugin) as it is shipped in the docker image: installed
     * at build time and injected as the first plugin of FEDERATION_PLUGINS by docker-entrypoint.sh. The server must be
     * the test environment (make test-env, see docker-compose.test.yml) and BAYESIAN_SERVER_ENDPOINT must be the
     * BayesianServer bundled with it, which the tests train.
     *
     * The model is trained through FederationLib only (classified evidence), so the plugin has to handle both of its
     * events for the scans to be classified. FederationLib has no classification of its own and the TestPlugin only
     * classifies content containing its trigger words, so a classification of the scanned samples can only come from
     * BayesianPlugin.
     */
    class BayesianPluginTest extends TestCase
    {
        /** The plugin's default minimum_documents, required in total and per label before content is classified */
        private const int MINIMUM_DOCUMENTS = 10;
        private const int LEARNING_TIMEOUT = 120;
        private const int LEARN_REQUEST_TIMEOUT = 30;

        private static bool $trained = false;
        /** @var string[] */
        private static array $trainingEvidence = [];
        /** @var string[] */
        private static array $trainingEntities = [];

        private FederationClient $client;
        /** @var string[] */
        private array $createdEntities = [];
        /** @var string[] */
        private array $createdEvidence = [];
        /** @var string[] */
        private array $createdReports = [];

        protected function setUp(): void
        {
            $this->client = self::createClient();

            try
            {
                $this->client->getServerInformation();
            }
            catch(RequestException $e)
            {
                $this->fail(sprintf('FederationLib is not reachable at %s, start the test environment with "make test-env": %s', self::getServerEndpoint(), $e->getMessage()));
            }

            $health = $this->bayesianRequest('GET', '/health');
            if($health['code'] !== 200)
            {
                $this->fail(sprintf('BayesianServer is not healthy at %s (HTTP %d, set BAYESIAN_SERVER_ENDPOINT), start the test environment with "make test-env"', self::getBayesianEndpoint(), $health['code']));
            }
        }

        protected function tearDown(): void
        {
            foreach($this->createdReports as $reportUuid)
            {
                try { $this->client->deleteReport($reportUuid); } catch(Throwable) {}
            }

            foreach($this->createdEvidence as $evidenceUuid)
            {
                try { $this->client->deleteEvidence($evidenceUuid); } catch(Throwable) {}
            }

            foreach($this->createdEntities as $entityUuid)
            {
                try { $this->client->deleteEntity($entityUuid); } catch(Throwable) {}
            }

            Logger::unregisterHandlers();
        }

        public static function tearDownAfterClass(): void
        {
            // Deleting the training evidence does not untrain the model
            $client = self::createClient();
            foreach(self::$trainingEvidence as $evidenceUuid)
            {
                try { $client->deleteEvidence($evidenceUuid); } catch(Throwable) {}
            }

            foreach(self::$trainingEntities as $entityUuid)
            {
                try { $client->deleteEntity($entityUuid); } catch(Throwable) {}
            }

            self::$trainingEvidence = [];
            self::$trainingEntities = [];
            Logger::unregisterHandlers();
        }

        // ---------------------------------------------------------------------------------------------------------
        // RECORD_CHANGE (EVIDENCE_CLASSIFIED), learning
        // ---------------------------------------------------------------------------------------------------------

        public function testEvidenceSubmittedWithAClassificationTrainsBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $before = $this->getLearnRequests();

            $this->createdEvidence[] = $this->client->submitEvidence($entityUuid, $this->uniqueText(ClassificationFlag::MALICIOUS), classification: ClassificationFlag::MALICIOUS);

            $this->waitForLearnRequests($before + 1);
        }

        public function testEvidenceWithoutAClassificationDoesNotTrainBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $before = $this->getLearnRequests();

            $this->createdEvidence[] = $this->client->submitEvidence($entityUuid, $this->uniqueText(ClassificationFlag::NORMAL));

            // The plugin makes the learn request during FederationLib's request, nothing can arrive after the response
            $this->assertSame($before, $this->getLearnRequests());
        }

        public function testClassifyingEvidenceTrainsBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $this->uniqueText(ClassificationFlag::SUSPICIOUS));
            $this->createdEvidence[] = $evidenceUuid;
            $before = $this->getLearnRequests();

            $this->client->classifyEvidence($evidenceUuid, ClassificationFlag::SUSPICIOUS);

            $this->waitForLearnRequests($before + 1);
        }

        public function testClosingAReportWithAClassificationTrainsBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $submission = $this->client->submitReport($entityUuid, new ContentInput($this->uniqueText(ClassificationFlag::MALICIOUS)), IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();
            foreach($submission->getEvidence() as $evidence)
            {
                $this->createdEvidence[] = $evidence->getUuid();
            }

            // Only the operator assigned to the report can close it
            $this->client->assignOperatorToReport($submission->getReport()->getUuid(), $this->client->getSelf()->getUuid());

            $before = $this->getLearnRequests();
            $this->client->closeReport($submission->getReport()->getUuid(), ClassificationFlag::MALICIOUS);

            $this->waitForLearnRequests($before + count($submission->getEvidence()));
        }

        public function testTrainingThroughFederationLibMakesTheModelReady(): void
        {
            $this->ensureTrained();

            $model = $this->getBayesianStatus()['model'];
            $this->assertGreaterThanOrEqual(self::MINIMUM_DOCUMENTS, (int)$model['total_documents']);

            $labels = $this->getLabelDocumentCounts($model);
            foreach(ClassificationFlag::cases() as $flag)
            {
                $this->assertArrayHasKey($flag->value, $labels, sprintf('The model does not know the %s label', $flag->value));
                $this->assertGreaterThanOrEqual(self::MINIMUM_DOCUMENTS, $labels[$flag->value], sprintf('The model was not trained with enough %s documents', $flag->value));
            }
        }

        // ---------------------------------------------------------------------------------------------------------
        // CONTENT_SCAN, classification
        // ---------------------------------------------------------------------------------------------------------

        public function testScanIsClassifiedByBayesianServer(): void
        {
            $this->ensureTrained();

            foreach(ClassificationFlag::cases() as $flag)
            {
                foreach(TextGenerator::trainingSamples($flag) as $sample)
                {
                    $expected = $this->classifyDirectly($sample);
                    $scanned = $this->client->scanContent($sample);

                    // Trained content is made of known tokens, so the plugin always classifies it once the model is ready
                    $classification = $scanned->getClassification();
                    $this->assertNotNull($classification, sprintf('The plugin did not classify the %s sample: %s', $flag->value, $sample));
                    $this->assertSame($expected['top_label'], $classification->getClassificationFlag()->value, sprintf('The scan classification differs from BayesianServer for: %s', $sample));
                    $this->assertEqualsWithDelta(max(0.0, min(1.0, (float)$expected['top_probability'])), $classification->getConfidence(), 0.0001);

                    // FederationLib applies the plugin's classification through its CLASSIFICATION_* scanning rules
                    $this->assertNotEquals(0.0, $scanned->getScanResults()['CLASSIFICATION_' . $classification->getClassificationFlag()->value]);
                }
            }
        }

        public function testScanDistinguishesTrainedContent(): void
        {
            $this->ensureTrained();

            // Other test units also train the model, so only the majority of the samples is required to be right
            foreach(ClassificationFlag::cases() as $flag)
            {
                $samples = TextGenerator::trainingSamples($flag);
                $correct = 0;
                foreach($samples as $sample)
                {
                    if($this->client->scanContent($sample)->getClassification()?->getClassificationFlag() === $flag)
                    {
                        $correct++;
                    }
                }

                $this->assertGreaterThan(count($samples) / 2, $correct, sprintf('Only %d of %d trained %s samples were classified as %s', $correct, count($samples), $flag->value, $flag->value));
            }
        }

        public function testScanClassifiesEveryEvidenceItemWithText(): void
        {
            $this->ensureTrained();

            $normal = TextGenerator::trainingText(ClassificationFlag::NORMAL, 1);
            $malicious = TextGenerator::trainingText(ClassificationFlag::MALICIOUS, 1);
            $this->assertSame(ClassificationFlag::NORMAL->value, $this->classifyDirectly($normal)['top_label'], 'BayesianServer does not classify the NORMAL sample as NORMAL');
            $this->assertSame(ClassificationFlag::MALICIOUS->value, $this->classifyDirectly($malicious)['top_label'], 'BayesianServer does not classify the MALICIOUS sample as MALICIOUS');

            // The aggregate classification is the worst classification of the evidence items, it's only MALICIOUS if
            // the plugin also classified the item after the NORMAL one and the one without text content
            $scanned = $this->client->scanContent([
                new ContentInput($normal),
                new ContentInput(null, 'Evidence without text content'),
                new ContentInput($malicious),
            ]);

            $this->assertNotNull($scanned->getClassification(), 'The plugin did not classify the content');
            $this->assertSame(ClassificationFlag::MALICIOUS, $scanned->getClassification()->getClassificationFlag());
        }

        public function testScanOfUnknownContentIsNotClassified(): void
        {
            $this->ensureTrained();

            // classify_known_tokens (enabled by default) skips content that is mostly unknown to the model
            $unknown = implode(' ', array_map(fn() => bin2hex(random_bytes(6)), range(1, 12)));
            $scanned = $this->client->scanContent($unknown);
            $this->assertNull($scanned->getClassification());
        }

        // ---------------------------------------------------------------------------------------------------------
        // Helpers
        // ---------------------------------------------------------------------------------------------------------

        private static function getServerEndpoint(): string
        {
            return getenv('SERVER_ENDPOINT') ?: 'http://172.17.0.1:7000';
        }

        private static function getBayesianEndpoint(): string
        {
            return getenv('BAYESIAN_SERVER_ENDPOINT') ?: 'http://172.17.0.1:6380';
        }

        private static function createClient(): FederationClient
        {
            return new FederationClient(self::getServerEndpoint(), getenv('SERVER_ACCESS_TOKEN') ?: null);
        }

        /**
         * Trains the model through FederationLib once per test run, by submitting the training samples as classified
         * evidence, and waits until the model is ready to classify content
         */
        private function ensureTrained(): void
        {
            if(self::$trained)
            {
                return;
            }

            $entityUuid = $this->client->pushEntity(uniqid('bayesiantraining') . '.example.com');
            self::$trainingEntities[] = $entityUuid;

            $before = $this->getLearnRequests();
            $submitted = 0;
            foreach(ClassificationFlag::cases() as $flag)
            {
                foreach(TextGenerator::trainingSamples($flag) as $sample)
                {
                    self::$trainingEvidence[] = $this->client->submitEvidence($entityUuid, $sample, classification: $flag);
                    $submitted++;
                }
            }

            // A BayesianServer reused between test runs rejects the samples it already knows, the requests still count
            $this->waitForLearnRequests($before + $submitted);

            $labels = $this->getLabelDocumentCounts($this->getBayesianStatus()['model']);
            foreach(ClassificationFlag::cases() as $flag)
            {
                $this->assertGreaterThanOrEqual(self::MINIMUM_DOCUMENTS, $labels[$flag->value] ?? 0, sprintf('The model is not ready after training, not enough %s documents', $flag->value));
            }

            self::$trained = true;
        }

        private function createEntity(): string
        {
            $entityUuid = $this->client->pushEntity(uniqid('bayesianplugin') . '.example.com');
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        /**
         * Returns a training sample made unique, so BayesianServer never rejects it as a known document
         */
        private function uniqueText(ClassificationFlag $flag): string
        {
            return TextGenerator::trainingText($flag, 4) . ' ' . uniqid('ref');
        }

        /**
         * Classifies content with BayesianServer directly, the way the plugin classifies it with its default
         * configuration (no top_k or threshold)
         *
         * @return array The classification response of BayesianServer
         */
        private function classifyDirectly(string $content): array
        {
            $response = $this->bayesianRequest('POST', '/', ['text' => $content]);
            $this->assertSame(200, $response['code'], 'BayesianServer failed to classify the content: ' . $response['raw']);
            $this->assertArrayHasKey('top_label', $response['body']);
            return $response['body'];
        }

        private function getBayesianStatus(): array
        {
            $response = $this->bayesianRequest('GET', '/');
            $this->assertSame(200, $response['code'], 'Failed to get the status of BayesianServer: ' . $response['raw']);
            return $response['body'];
        }

        /**
         * @return array<string, int> The number of training documents of each label of the model
         */
        private function getLabelDocumentCounts(array $model): array
        {
            $labels = $model['labels'] ?? [];
            if(is_string($labels))
            {
                $labels = json_decode($labels, true) ?? [];
            }

            $counts = [];
            foreach($labels as $label)
            {
                $counts[$label['label']] = (int)$label['document_count'];
            }

            return $counts;
        }

        /**
         * Returns the number of learn requests BayesianServer received, including the ones it rejected (eg; a known
         * document), so a learn request made by the plugin is counted regardless of what the model does with it
         */
        private function getLearnRequests(): int
        {
            $learning = $this->getBayesianStatus()['learning'];
            return (int)$learning['submitted'] + (int)$learning['rejected'] + (int)$learning['rejected_max_docs'];
        }

        /**
         * Waits until BayesianServer received the expected number of learn requests, then until it processed them,
         * learning is asynchronous
         */
        private function waitForLearnRequests(int $expected): void
        {
            $deadline = time() + self::LEARN_REQUEST_TIMEOUT;
            while($this->getLearnRequests() < $expected)
            {
                if(time() >= $deadline)
                {
                    $this->fail(sprintf('BayesianServer received %d learn requests, expected at least %d, BayesianPlugin did not train it (is it enabled?)', $this->getLearnRequests(), $expected));
                }

                usleep(250000);
            }

            $this->assertGreaterThanOrEqual($expected, $this->getLearnRequests());

            $deadline = time() + self::LEARNING_TIMEOUT;
            $idlePolls = 0;
            while(time() < $deadline)
            {
                // Require two consecutive idle polls, a document may be in progress while the queue is empty
                $idlePolls = (int)$this->getBayesianStatus()['learning']['pending'] === 0 ? $idlePolls + 1 : 0;
                if($idlePolls >= 2)
                {
                    return;
                }

                usleep(250000);
            }

            $this->fail(sprintf('BayesianServer did not finish learning within %d seconds', self::LEARNING_TIMEOUT));
        }

        private function bayesianRequest(string $method, string $path, ?array $json=null): array
        {
            $url = rtrim(self::getBayesianEndpoint(), '/') . $path;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);

            if($json !== null)
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
            }

            $raw = curl_exec($ch);
            if($raw === false)
            {
                $this->fail(sprintf('Request to BayesianServer at %s failed (set BAYESIAN_SERVER_ENDPOINT): %s', $url, curl_error($ch)));
            }

            return [
                'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => json_decode($raw, true),
                'raw' => $raw,
            ];
        }
    }
