<?php

    namespace FederationServer\Classes\Configuration;

    class ServerConfiguration
    {
        private string $name;
        private ?string $apiKey;

        /**
         * ServerConfiguration constructor.
         *
         * @param array $config Configuration array containing server settings.
         */
        public function __construct(array $config)
        {
            $this->name = $config['server.name'] ?? 'Federation Server';
            $this->apiKey = $config['server.api_key'] ?? null;
        }

        /**
         * Get the name of the server.
         *
         * @return string The name of the server.
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Get the master API key for the server.
         *
         * @return string|null The API key, or null if not set.
         */
        public function getApiKey(): ?string
        {
            return $this->apiKey;
        }
    }