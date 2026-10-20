<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Classes\DatabaseConnection;

    class Startup
    {
        private int $startedAt;
        private bool $databaseCreated;
        private ?string $migratedFrom;

        /**
         * Startup constructor.
         *
         * @param int $startedAt The Unix timestamp at which the server started
         * @param bool $databaseCreated Whether the database tables were created by this start
         * @param string|null $migratedFrom The schema version the database was migrated from, null if not migrated
         */
        public function __construct(int $startedAt, bool $databaseCreated, ?string $migratedFrom)
        {
            $this->startedAt = $startedAt;
            $this->databaseCreated = $databaseCreated;
            $this->migratedFrom = $migratedFrom;
        }

        /**
         * Returns the Unix timestamp at which the server started
         *
         * @return int The timestamp
         */
        public function getStartedAt(): int
        {
            return $this->startedAt;
        }

        /**
         * Returns whether this is the first start of the server: its database tables were created by this start,
         * eg; for a plugin to create the records it needs once
         *
         * @return bool True if the database was created by this start
         */
        public function isDatabaseCreated(): bool
        {
            return $this->databaseCreated;
        }

        /**
         * Returns the schema version the database was migrated from by this start, eg; after an upgrade
         *
         * @return string|null The previous schema version, null if the database was not migrated
         */
        public function getMigratedFrom(): ?string
        {
            return $this->migratedFrom;
        }

        /**
         * Returns the schema version of the database
         *
         * @return string The schema version
         */
        public function getSchemaVersion(): string
        {
            return DatabaseConnection::SCHEMA_VERSION;
        }
    }
