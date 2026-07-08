<?php

    namespace FederationLib\Classes\Configuration;

    class MaintenanceConfiguration
    {
        private bool $enabled;
        private bool $cleanAuditLogs;
        public int $cleanAuditLogsTtl;
        private bool $cleanBlacklist;
        public int $cleanBlacklistTtl;
        private bool $cleanEvidence;
        public int $cleanEvidenceTtl;
        private bool $cleanReports;
        public int $cleanReportsTtl;
        private bool $cleanFileAttachments;
        public int $cleanFileAttachmentsTtl;
        private bool $cleanEntities;
        public int $cleanEntitiesTtl;

        /**
         * MaintenanceConfiguration constructor.
         *
         * @param array $configuration
         */
        public function __construct(array $configuration)
        {
            $this->enabled = $configuration['enabled'] ?? true;
            $this->cleanAuditLogs = $configuration['clean_audit_logs'] ?? true;
            $this->cleanAuditLogsTtl = $configuration['clean_audit_logs_ttl'] ?? 63072000;
            $this->cleanBlacklist = $configuration['clean_blacklist'] ?? true;
            $this->cleanBlacklistTtl = $configuration['clean_blacklist_ttl'] ?? 31536000;
            $this->cleanEvidence = $configuration['clean_evidence'] ?? true;
            $this->cleanEvidenceTtl = $configuration['clean_evidence_ttl'] ?? 63072000;
            $this->cleanReports = $configuration['clean_reports'] ?? true;
            $this->cleanReportsTtl = $configuration['clean_reports_ttl'] ?? 63072000;
            $this->cleanFileAttachments = $configuration['clean_file_attachments'] ?? true;
            $this->cleanFileAttachmentsTtl = $configuration['clean_file_attachments_ttl'] ?? 63072000;
            $this->cleanEntities = $configuration['clean_entities'] ?? false;
            $this->cleanEntitiesTtl = $configuration['clean_entities_ttl'] ?? 63072000;
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
         * Gets the TTL in seconds after which audit logs will be cleaned.
         *
         * @return int
         */
        public function getCleanAuditLogsTtl(): int
        {
            return $this->cleanAuditLogsTtl;
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
         * Gets the TTL in seconds after which blacklist entries will be cleaned.
         *
         * @return int
         */
        public function getCleanBlacklistTtl(): int
        {
            return $this->cleanBlacklistTtl;
        }

        /**
         * Checks if cleaning of evidence records is enabled.
         *
         * @return bool
         */
        public function isCleanEvidenceEnabled(): bool
        {
            return $this->cleanEvidence;
        }

        /**
         * Gets the TTL in seconds after which evidence records will be cleaned.
         *
         * @return int
         */
        public function getCleanEvidenceTtl(): int
        {
            return $this->cleanEvidenceTtl;
        }

        /**
         * Checks if cleaning of reports is enabled.
         *
         * @return bool
         */
        public function isCleanReportsEnabled(): bool
        {
            return $this->cleanReports;
        }

        /**
         * Gets the TTL in seconds after which reports will be cleaned.
         *
         * @return int
         */
        public function getCleanReportsTtl(): int
        {
            return $this->cleanReportsTtl;
        }

        /**
         * Checks if cleaning of file attachments is enabled.
         *
         * @return bool
         */
        public function isCleanFileAttachmentsEnabled(): bool
        {
            return $this->cleanFileAttachments;
        }

        /**
         * Gets the TTL in seconds after which file attachments will be cleaned.
         *
         * @return int
         */
        public function getCleanFileAttachmentsTtl(): int
        {
            return $this->cleanFileAttachmentsTtl;
        }

        /**
         * Checks if cleaning of entity records is enabled.
         *
         * @return bool
         */
        public function isCleanEntitiesEnabled(): bool
        {
            return $this->cleanEntities;
        }

        /**
         * Gets the TTL in seconds after which entity records will be cleaned.
         *
         * @return int
         */
        public function getCleanEntitiesTtl(): int
        {
            return $this->cleanEntitiesTtl;
        }
    }
