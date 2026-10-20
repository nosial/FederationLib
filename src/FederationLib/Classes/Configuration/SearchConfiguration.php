<?php

    namespace FederationLib\Classes\Configuration;

    use FederationLib\Enums\RecordType;

    class SearchConfiguration
    {
        public const array DEFAULT_TYPES = ['ENTITY', 'EVIDENCE', 'BLACKLIST', 'REPORT', 'AUDIT_LOG', 'OPERATOR'];

        /**
         * @var string[]
         */
        private array $types;
        private bool $publicSearch;
        private int $maxLimit;
        private bool $extendedSearch;

        /**
         * SearchConfiguration constructor.
         *
         * @param array $configuration Array with search configuration values
         * @param bool $publicSearch Whether the cross-collection search is public (SEARCH in server.public)
         */
        public function __construct(array $configuration, bool $publicSearch=false)
        {
            // The record types whose search is enabled, search is disabled when none are
            $this->types = ConfigurationValues::parseList($configuration['types'] ?? null, array_map(fn(RecordType $type) => $type->value, RecordType::cases()), self::DEFAULT_TYPES);
            $this->publicSearch = $publicSearch;
            $this->maxLimit = (int)($configuration['max_limit'] ?? 50);
            $this->extendedSearch = ConfigurationValues::parseBool($configuration['extended_search'] ?? null, true);
        }

        /**
         * Checks if the search functionality is enabled, which is the case when any record type is searchable.
         *
         * @return bool True if search is enabled, false otherwise
         */
        public function isEnabled(): bool
        {
            return count($this->types) > 0;
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
         * Returns the maximum number of results a search returns per record type.
         *
         * @return int The maximum limit
         */
        public function getMaxLimit(): int
        {
            return $this->maxLimit;
        }

        /**
         * Checks if the extended search, which matches columns that are not indexed, is enabled for authenticated
         * operators.
         *
         * @return bool True if extended search is enabled, false otherwise
         */
        public function isExtendedSearchEnabled(): bool
        {
            return $this->extendedSearch;
        }

        /**
         * Checks if the given record type is searchable
         *
         * @param RecordType $type The record type
         * @return bool True if the record type is searchable
         */
        public function isTypeEnabled(RecordType $type): bool
        {
            return in_array($type->value, $this->types, true);
        }

        /**
         * Checks if entity search is enabled.
         *
         * @return bool True if entity search is enabled, false otherwise
         */
        public function isEntitiesEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::ENTITY);
        }

        /**
         * Checks if evidence search is enabled.
         *
         * @return bool True if evidence search is enabled, false otherwise
         */
        public function isEvidenceEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::EVIDENCE);
        }

        /**
         * Checks if blacklist search is enabled.
         *
         * @return bool True if blacklist search is enabled, false otherwise
         */
        public function isBlacklistEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::BLACKLIST);
        }

        /**
         * Checks if report search is enabled.
         *
         * @return bool True if report search is enabled, false otherwise
         */
        public function isReportsEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::REPORT);
        }

        /**
         * Checks if attachment search is enabled.
         *
         * @return bool True if attachment search is enabled, false otherwise
         */
        public function isAttachmentsEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::ATTACHMENT);
        }

        /**
         * Checks if audit log search is enabled.
         *
         * @return bool True if audit log search is enabled, false otherwise
         */
        public function isAuditLogsEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::AUDIT_LOG);
        }

        /**
         * Checks if operator search is enabled.
         *
         * @return bool True if operator search is enabled, false otherwise
         */
        public function isOperatorsEnabled(): bool
        {
            return $this->isTypeEnabled(RecordType::OPERATOR);
        }
    }
