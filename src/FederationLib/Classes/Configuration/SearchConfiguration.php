<?php

    namespace FederationLib\Classes\Configuration;

    class SearchConfiguration
    {
        private bool $enabled;
        private bool $publicSearch;
        private int $maxLimit;
        private bool $enableEntities;
        private bool $enableEvidence;
        private bool $enableBlacklist;
        private bool $enableReports;
        private bool $enableAttachments;
        private bool $enableAuditLogs;
        private bool $enableOperators;

        /**
         * SearchConfiguration constructor.
         *
         * @param array $configuration Array with search configuration values
         */
        public function __construct(array $configuration)
        {
            $this->enabled = $configuration['enabled'] ?? true;
            $this->publicSearch = $configuration['public_search'] ?? false;
            $this->maxLimit = $configuration['max_limit'] ?? 50;
            $this->enableEntities = $configuration['enable_entities'] ?? true;
            $this->enableEvidence = $configuration['enable_evidence'] ?? true;
            $this->enableBlacklist = $configuration['enable_blacklist'] ?? true;
            $this->enableReports = $configuration['enable_reports'] ?? true;
            $this->enableAttachments = $configuration['enable_attachments'] ?? true;
            $this->enableAuditLogs = $configuration['enable_audit_logs'] ?? true;
            $this->enableOperators = $configuration['enable_operators'] ?? true;
        }

        /**
         * Checks if the search functionality is enabled.
         *
         * @return bool True if search is enabled, false otherwise
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Checks if search is publicly accessible without authentication.
         * When false, all search requests require a valid operator token.
         *
         * @return bool True if public search is enabled, false otherwise
         */
        public function isPublicSearch(): bool
        {
            return $this->publicSearch;
        }

        /**
         * Gets the maximum number of results that can be returned per resource type.
         *
         * @return int The maximum limit per type
         */
        public function getMaxLimit(): int
        {
            return $this->maxLimit;
        }

        /**
         * Checks if entity records are included in search results.
         *
         * @return bool True if entities are searchable, false otherwise
         */
        public function isEntitiesEnabled(): bool
        {
            return $this->enableEntities;
        }

        /**
         * Checks if evidence records are included in search results.
         *
         * @return bool True if evidence is searchable, false otherwise
         */
        public function isEvidenceEnabled(): bool
        {
            return $this->enableEvidence;
        }

        /**
         * Checks if blacklist records are included in search results.
         *
         * @return bool True if blacklist is searchable, false otherwise
         */
        public function isBlacklistEnabled(): bool
        {
            return $this->enableBlacklist;
        }

        /**
         * Checks if report records are included in search results.
         *
         * @return bool True if reports are searchable, false otherwise
         */
        public function isReportsEnabled(): bool
        {
            return $this->enableReports;
        }

        /**
         * Checks if file attachment records are included in search results.
         *
         * @return bool True if attachments are searchable, false otherwise
         */
        public function isAttachmentsEnabled(): bool
        {
            return $this->enableAttachments;
        }

        /**
         * Checks if audit log entries are included in search results.
         *
         * @return bool True if audit logs are searchable, false otherwise
         */
        public function isAuditLogsEnabled(): bool
        {
            return $this->enableAuditLogs;
        }

        /**
         * Checks if operator records are included in search results.
         *
         * @return bool True if operators are searchable, false otherwise
         */
        public function isOperatorsEnabled(): bool
        {
            return $this->enableOperators;
        }
    }
