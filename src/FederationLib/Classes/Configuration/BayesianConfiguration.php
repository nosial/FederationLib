<?php

    namespace FederationLib\Classes\Configuration;

    class BayesianConfiguration
    {
        private bool $enabled;
        private bool $ssl;
        private string $host;
        private int $port;
        private bool $classifyKnownTokens;

        /**
         * Public Constructor
         *
         * @param array $configuration Array with Bayesian configuration values
         */
        public function __construct(array $configuration)
        {
            $this->enabled = $configuration['enabled'] ?? false;
            $this->ssl = $configuration['ssl'] ?? false;
            $this->host = $configuration['host'] ?? '127.0.0.1';
            $this->port = $configuration['port'] ?? 6380;
            $this->classifyKnownTokens = $configuration['classify_known_tokens'] ?? true;
        }

        /**
         * Returns True if Bayesian filtering is enabled.
         *
         * @return bool Returns True if the feature is enabled
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Returns True if SSL is used, False otherwise
         *
         * @return bool Returns True if SSL is used
         */
        public function useSsl(): bool
        {
            return $this->ssl;
        }

        /**
         * The host of the BayesianServer to connect to
         *
         * @return string The BayesianServer host
         */
        public function getHost(): string
        {
            return $this->host;
        }

        /**
         * The port of the BayesianServer to connect to
         *
         * @return int The BayesianServer port
         */
        public function getPort(): int
        {
            return $this->port;
        }

        /**
         * Returns True if classifications should be skipped if the majority of the detected tokens are unknown
         *
         * @return bool True to only classify known tokens, False otherwise.
         */
        public function classifyKnownTokens(): bool
        {
            return $this->classifyKnownTokens;
        }
    }