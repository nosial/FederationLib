<?php

    namespace FederationServer\Classes\Configuration;

    class LoggingConfiguration
    {
        private bool $logUnauthorizedRequests;

        public function __construct(array $config)
        {
            $this->logUnauthorizedRequests = $config['log_unauthorized_requests'] ?? false;
        }

        /**
         * Check if unauthorized requests should be logged.
         *
         * @return bool True if unauthorized requests should be logged, false otherwise.
         */
        public function shouldLogUnauthorizedRequests(): bool
        {
            return $this->logUnauthorizedRequests;
        }
    }