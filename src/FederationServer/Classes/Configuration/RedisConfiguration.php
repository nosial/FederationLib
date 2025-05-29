<?php

    namespace FederationServer\Classes\Configuration;

    class RedisConfiguration
    {
        private bool $enabled;
        private string $host;
        private int $port;
        private ?string $password;
        private int $database;

        /**
         * RedisConfiguration constructor.
         *
         * @param array $configuration Array with Redis configuration values.
         */
        public function __construct(array $configuration)
        {
            $this->enabled = $configuration['enabled'] ?? true;
            $this->host = $configuration['host'] ?? '127.0.0.1';
            $this->port = $configuration['port'] ?? 6379;
            $this->password = $configuration['password'] ?? null;
            $this->database = $configuration['database'] ?? 0;
        }

        /**
         * Check if Redis is enabled.
         *
         * @return bool
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Get the Redis host.
         *
         * @return string
         */
        public function getHost(): string
        {
            return $this->host;
        }

        /**
         * Get the Redis port.
         *
         * @return int
         */
        public function getPort(): int
        {
            return $this->port;
        }

        /**
         * Get the Redis password.
         *
         * @return string|null
         */
        public function getPassword(): ?string
        {
            return $this->password;
        }

        /**
         * Get the Redis database index.
         *
         * @return int
         */
        public function getDatabase(): int
        {
            return $this->database;
        }
    }
