<?php

    namespace FederationServer\Classes\Configuration;

    class ServerConfiguration
    {
        private string $baseUrl;
        private string $name;
        private ?string $apiKey;
        private int $maxUploadSize;
        private string $storagePath;

        /**
         * ServerConfiguration constructor.
         *
         * @param array $config Configuration array containing server settings.
         */
        public function __construct(array $config)
        {
            $this->baseUrl = $config['server.base_url'] ?? 'http://127.0.0.1:6161';
            $this->name = $config['server.name'] ?? 'Federation Server';
            $this->apiKey = $config['server.api_key'] ?? null;
            $this->maxUploadSize = $config['max_upload_size'] ?? 52428800; // 50MB default
            $this->storagePath = $config['server.storage_path'] ?? '/var/www/uploads';
        }

        /**
         * Get the base URL of the server.
         *
         * @return string The base URL of the server.
         */
        public function getBaseUrl(): string
        {
            return $this->baseUrl;
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

        /**
         * Get the maximum allowed upload size in bytes.
         *
         * @return int Maximum upload size in bytes
         */
        public function getMaxUploadSize(): int
        {
            return $this->maxUploadSize;
        }

        /**
         * Get the path where files are stored.
         *
         * @return string The storage path for uploaded files.
         */
        public function getStoragePath(): string
        {
            return $this->storagePath;
        }
    }
