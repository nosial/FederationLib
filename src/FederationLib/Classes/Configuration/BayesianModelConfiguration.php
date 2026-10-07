<?php

    namespace FederationLib\Classes\Configuration;

    class BayesianModelConfiguration
    {
        private bool $enabled;
        private string $startScript;
        private string $stopScript;
        private string $modelPath;
        private string $archivePath;
        private string $backupPath;
        private int $minimumEvidence;
        private int $learningTimeout;

        /**
         * BayesianModelConfiguration constructor.
         *
         * @param array $configuration Array with the Bayesian model configuration values
         */
        public function __construct(array $configuration)
        {
            $this->enabled = filter_var($configuration['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            $this->startScript = (string)($configuration['start_script'] ?? '/usr/local/bin/temporary_start_bayesian.sh');
            $this->stopScript = (string)($configuration['stop_script'] ?? '/usr/local/bin/stop_temporary_bayesian.sh');
            $this->modelPath = rtrim((string)($configuration['model_path'] ?? '/var/www/bayesian_model/model'), '/');
            $this->archivePath = (string)($configuration['archive_path'] ?? '/var/www/bayesian_model/archive.csv');
            $this->backupPath = rtrim((string)($configuration['backup_path'] ?? '/var/www/bayesian_model/backups'), '/');
            $this->minimumEvidence = max(1, (int)($configuration['minimum_evidence'] ?? 20));
            $this->learningTimeout = max(1, (int)($configuration['learning_timeout'] ?? 600));
        }

        /**
         * Returns True if the Bayesian model is checked (and rebuilt if necessary) by `federationlib init`
         *
         * @return bool True if the Bayesian model check is enabled
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Returns the path of the script that starts BayesianServer temporarily, the script only exits once the server
         * is reachable and leaves it running
         *
         * @return string The path of the start script
         */
        public function getStartScript(): string
        {
            return $this->startScript;
        }

        /**
         * Returns the path of the script that stops the temporarily started BayesianServer
         *
         * @return string The path of the stop script
         */
        public function getStopScript(): string
        {
            return $this->stopScript;
        }

        /**
         * Returns the model directory of BayesianServer (its --model option)
         *
         * @return string The model directory
         */
        public function getModelPath(): string
        {
            return $this->modelPath;
        }

        /**
         * Returns the training archive of BayesianServer (its --archive option)
         *
         * @return string The path of the archive
         */
        public function getArchivePath(): string
        {
            return $this->archivePath;
        }

        /**
         * Returns the directory where the model directory and the archive are moved to (in a timestamped directory)
         * before the model is rebuilt
         *
         * @return string The backup directory
         */
        public function getBackupPath(): string
        {
            return $this->backupPath;
        }

        /**
         * Returns the minimum number of classified evidence records with text content required to train a new model,
         * an empty model is only rebuilt if at least this many records are available
         *
         * @return int The minimum number of classified evidence records
         */
        public function getMinimumEvidence(): int
        {
            return $this->minimumEvidence;
        }

        /**
         * Returns the maximum number of seconds to wait for BayesianServer to accept and process the training of the
         * rebuilt model
         *
         * @return int The learning timeout in seconds
         */
        public function getLearningTimeout(): int
        {
            return $this->learningTimeout;
        }
    }
