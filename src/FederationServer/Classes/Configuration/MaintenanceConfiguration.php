<?php

    namespace FederationServer\Classes\Configuration;

    class MaintenanceConfiguration
    {
        private bool $enabled;
        private bool $cleanAuditLogs;
        public int $cleanAuditLogsDays;
        private bool $cleanBlacklist;
        public int $cleanBlacklistDays;

        /**
         * MaintenanceConfiguration constructor.
         *
         * @param array $configuration
         */
        public function __construct(array $configuration)
        {
            $this->enabled = $configuration['enabled'] ?? false;
            $this->cleanAuditLogs = $configuration['cleanAuditLogs'] ?? false;
            $this->cleanAuditLogsDays = $configuration['cleanAuditLogsDays'] ?? 30; // Default to 30 days
            $this->cleanBlacklist = $configuration['cleanBlacklist'] ?? false;
            $this->cleanBlacklistDays = $configuration['cleanBlacklistDays'] ?? 730; // Default to 2 years
        }

        /**
         * Checks if maintenance mode is enabled.
         *
         * @return bool
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Checks if cleaning of audit logs is enabled.
         *
         * @return bool
         */
        public function isCleanAuditLogsEnabled(): bool
        {
            return $this->cleanAuditLogs;
        }

        /**
         * Gets the number of days after which audit logs will be cleaned.
         *
         * @return int
         */
        public function getCleanAuditLogsDays(): int
        {
            return $this->cleanAuditLogsDays;
        }

        /**
         * Checks if cleaning of blacklist is enabled.
         *
         * @return bool
         */
        public function isCleanBlacklistEnabled(): bool
        {
            return $this->cleanBlacklist;
        }

        /**
         * Gets the number of days after which blacklist entries will be cleaned.
         *
         * @return int
         */
        public function getCleanBlacklistDays(): int
        {
            return $this->cleanBlacklistDays;
        }
    }