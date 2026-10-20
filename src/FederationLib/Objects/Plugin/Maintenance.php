<?php

    namespace FederationLib\Objects\Plugin;

    class Maintenance
    {
        private int $startedAt;
        private bool $cleaningEnabled;
        private ?string $archivePath;
        /** @var array<string, int> */
        private array $removed;

        /**
         * Maintenance constructor.
         *
         * @param int $startedAt The Unix timestamp at which the maintenance run started
         * @param bool $cleaningEnabled Whether FederationLib cleaned its records in this run (maintenance.enabled)
         * @param string|null $archivePath The directory removed records are archived to (--archive), null if none
         * @param array<string, int> $removed The number of records FederationLib removed, keyed by record type
         */
        public function __construct(int $startedAt, bool $cleaningEnabled, ?string $archivePath, array $removed)
        {
            $this->startedAt = $startedAt;
            $this->cleaningEnabled = $cleaningEnabled;
            $this->archivePath = $archivePath;
            $this->removed = $removed;
        }

        /**
         * Returns the Unix timestamp at which the maintenance run started
         *
         * @return int The timestamp
         */
        public function getStartedAt(): int
        {
            return $this->startedAt;
        }

        /**
         * Returns whether FederationLib cleaned its records in this run, a plugin may follow the same setting for its
         * own records
         *
         * @return bool True if cleaning is enabled (maintenance.enabled)
         */
        public function isCleaningEnabled(): bool
        {
            return $this->cleaningEnabled;
        }

        /**
         * Returns the directory removed records are archived to, a plugin removing its own records may archive them
         * there too
         *
         * @return string|null The archive directory, null if removed records are not archived
         */
        public function getArchivePath(): ?string
        {
            return $this->archivePath;
        }

        /**
         * Returns the number of records FederationLib removed in this run
         *
         * @return array<string, int> The number of removed records keyed by record type (eg; evidence, entity)
         */
        public function getRemoved(): array
        {
            return $this->removed;
        }
    }
