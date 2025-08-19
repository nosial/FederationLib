<?php

    namespace FederationLib\Classes\Configuration;

    /**
     * Class DatabaseConfiguration
     *
     * Handles the configuration for database connection.
     */
    class DatabaseConfiguration
    {
        private string $host;
        private int $port;
        private string $username;
        private string $password;
        private string $name;
        private string $charset;
        private string $collation;

        /**
         * DatabaseConfiguration constructor.
         *
         * @param array $configuration Array with database configuration values.
         */
        public function __construct(array $configuration)
        {
            $this->host = $configuration['host'] ?? '127.0.0.1';
            $this->port = $configuration['port'] ?? 3306;
            $this->username = $configuration['username'] ?? 'root';
            $this->password = $configuration['password'] ?? 'root';
            $this->name = $configuration['name'] ?? 'federation';
            $this->charset = $configuration['charset'] ?? 'utf8mb4';
            $this->collation = $configuration['collation'] ?? 'utf8mb4_unicode_ci';
        }

        /**
         * Get the database host.
         *
         * @return string
         */
        public function getHost(): string
        {
            return $this->host;
        }

        /**
         * Get the database port.
         *
         * @return int
         */
        public function getPort(): int
        {
            return $this->port;
        }

        /**
         * Get the database username.
         *
         * @return string
         */
        public function getUsername(): string
        {
            return $this->username;
        }

        /**
         * Get the database password.
         *
         * @return string
         */
        public function getPassword(): string
        {
            return $this->password;
        }

        /**
         * Get the database name.
         *
         * @return string
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Get the database charset.
         *
         * @return string
         */
        public function getCharset(): string
        {
            return $this->charset;
        }

        /**
         * Get the database collation.
         *
         * @return string
         */
        public function getCollation(): string
        {
            return $this->collation;
        }

        /**
         * Get the Data Source Name (DSN) for the database connection.
         *
         * @return string
         */
        public function getDsn(): string
        {
            return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->host, $this->port, $this->name, $this->charset
            );
        }
    }

