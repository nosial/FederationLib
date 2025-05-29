<?php

    namespace FederationServer\Classes\Configuration;

    class FileStorageConfiguration
    {
        private string $path;
        private int $maxSize;

        /**
         * FileStorageConfiguration constructor.
         *
         * @param array $config Array with file storage configuration values.
         */
        public function __construct(array $config)
        {
            $this->path = $config['path'] ?? '/var/www/uploads';
            $this->maxSize = $config['max_size'] ?? 52428800;
        }

        /**
         * Get the file storage path.
         *
         * @return string
         */
        public function getPath(): string
        {
            return $this->path;
        }

        /**
         * Get the maximum file size allowed for uploads.
         *
         * @return int
         */
        public function getMaxSize(): int
        {
            return $this->maxSize;
        }
    }

