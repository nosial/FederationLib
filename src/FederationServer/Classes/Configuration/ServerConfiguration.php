<?php

    namespace FederationServer\Classes\Configuration;

    class ServerConfiguration
    {
        private string $baseUrl;
        private string $name;
        private ?string $apiKey;
        private int $maxUploadSize;
        private string $storagePath;
        private int $listAuditLogsMaxItems;

        /**
         * ServerConfiguration constructor.
         *
         * @param array $config Configuration array containing server settings.
         */
        public function __construct(array $config)
        {
            $this->baseUrl = $config['base_url'] ?? 'http://127.0.0.1:6161';
            $this->name = $config['name'] ?? 'Federation Server';
            $this->apiKey = $config['api_key'] ?? null;
            $this->maxUploadSize = $config['max_upload_size'] ?? 52428800; // 50MB default
            $this->storagePath = $config['storage_path'] ?? '/var/www/uploads';
            $this->listAuditLogsMaxItems = $config['list_audit_logs_max_items'] ?? 100;
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

        /**
         * Get the maximum number of items to return when listing audit logs.
         *
         * @return int The maximum number of audit log items to return.
         */
        public function getListAuditLogsMaxItems(): int
        {
            return $this->listAuditLogsMaxItems;
        }
    }
