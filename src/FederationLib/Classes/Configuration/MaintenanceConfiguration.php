<?php

    namespace FederationLib\Classes\Configuration;

    class MaintenanceConfiguration
    {
        /**
         * The record types maintenance can clean and their default retention, null keeps the records forever
         */
        public const array DEFAULT_RETENTION = [
            'AUDIT_LOGS' => '2y',
            'BLACKLIST' => '1y',
            'EVIDENCE' => '2y',
            'REPORTS' => '2y',
            'FILE_ATTACHMENTS' => '2y',
            'ENTITIES' => 'off',
        ];

        /**
         * How long the DNS details of a domain entity are kept before maintenance resolves the name again
         */
        public const string DEFAULT_DNS_REFRESH = '90d';
        private bool $enabled;
        private ?int $dnsRefresh;
        /**
         * @var array<string, int|null>
         */
        private array $retention;

        /**
         * MaintenanceConfiguration constructor.
         *
         * @param array $configuration
         */
        public function __construct(array $configuration)
        {
            $this->enabled = ConfigurationValues::parseBool($configuration['enabled'] ?? null, true);

            // How long records of each type are kept, a type that is off is never cleaned; types that are not
            // configured keep their default retention
            $configured = array_merge(self::DEFAULT_RETENTION, ConfigurationValues::parseMap($configuration['retention'] ?? []));
            $this->retention = [];
            foreach(array_keys(self::DEFAULT_RETENTION) as $type)
            {
                $retention = array_key_exists($type, $configured) ? ConfigurationValues::parseDuration($configured[$type], null) : null;
                $this->retention[$type] = $retention !== null && $retention > 0 ? $retention : null;
            }

            // A refresh interval that is off, or not positive, disables the DNS details of domain entities
            // (an interval that cannot be parsed keeps the default)
            $dnsRefresh = ConfigurationValues::parseDuration(
                array_key_exists('dns_refresh', $configuration) ? $configuration['dns_refresh'] : self::DEFAULT_DNS_REFRESH,
                ConfigurationValues::parseDuration(self::DEFAULT_DNS_REFRESH, null)
            );
            $this->dnsRefresh = $dnsRefresh !== null && $dnsRefresh > 0 ? $dnsRefresh : null;
        }

        /**
         * Returns how long the DNS details of a domain entity are kept before maintenance resolves the name again
         *
         * @return int|null The interval in seconds, null if maintenance does not resolve domain entities
         */
        public function getDnsRefresh(): ?int
        {
            return $this->dnsRefresh;
        }

        /**
         * Checks if maintenance resolves domain entities and keeps their DNS details in their metadata
         *
         * @return bool True if enabled
         */
        public function isDnsRefreshEnabled(): bool
        {
            return $this->dnsRefresh !== null;
        }

        /**
         * Checks if maintenance is enabled.
         *
         * @return bool True if maintenance is enabled, false otherwise
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Returns how long records of a type are kept
         *
         * @param string $type The record type, one of the keys of DEFAULT_RETENTION
         * @return int|null The retention in seconds, null if the records are kept forever
         */
        public function getRetention(string $type): ?int
        {
            return $this->retention[$type] ?? null;
        }

        /**
         * Checks if cleaning audit logs is enabled.
         *
         * @return bool True if enabled
         */
        public function isCleanAuditLogsEnabled(): bool
        {
            return $this->getRetention('AUDIT_LOGS') !== null;
        }

        /**
         * Returns how long audit log entries are kept, in seconds.
         *
         * @return int The retention in seconds, 0 if they are kept forever
         */
        public function getCleanAuditLogsTtl(): int
        {
            return $this->getRetention('AUDIT_LOGS') ?? 0;
        }

        /**
         * Checks if cleaning blacklist records is enabled.
         *
         * @return bool True if enabled
         */
        public function isCleanBlacklistEnabled(): bool
        {
            return $this->getRetention('BLACKLIST') !== null;
        }

        /**
         * Returns how long blacklist records are kept, in seconds.
         *
         * @return int The retention in seconds, 0 if they are kept forever
         */
        public function getCleanBlacklistTtl(): int
        {
            return $this->getRetention('BLACKLIST') ?? 0;
        }

        /**
         * Checks if cleaning evidence is enabled.
         *
         * @return bool True if enabled
         */
        public function isCleanEvidenceEnabled(): bool
        {
            return $this->getRetention('EVIDENCE') !== null;
        }

        /**
         * Returns how long evidence records are kept, in seconds.
         *
         * @return int The retention in seconds, 0 if they are kept forever
         */
        public function getCleanEvidenceTtl(): int
        {
            return $this->getRetention('EVIDENCE') ?? 0;
        }

        /**
         * Checks if cleaning reports is enabled.
         *
         * @return bool True if enabled
         */
        public function isCleanReportsEnabled(): bool
        {
            return $this->getRetention('REPORTS') !== null;
        }

        /**
         * Returns how long reports are kept, in seconds.
         *
         * @return int The retention in seconds, 0 if they are kept forever
         */
        public function getCleanReportsTtl(): int
        {
            return $this->getRetention('REPORTS') ?? 0;
        }

        /**
         * Checks if cleaning file attachments is enabled.
         *
         * @return bool True if enabled
         */
        public function isCleanFileAttachmentsEnabled(): bool
        {
            return $this->getRetention('FILE_ATTACHMENTS') !== null;
        }

        /**
         * Returns how long file attachments are kept, in seconds.
         *
         * @return int The retention in seconds, 0 if they are kept forever
         */
        public function getCleanFileAttachmentsTtl(): int
        {
            return $this->getRetention('FILE_ATTACHMENTS') ?? 0;
        }

        /**
         * Checks if cleaning entities is enabled.
         *
         * @return bool True if enabled
         */
        public function isCleanEntitiesEnabled(): bool
        {
            return $this->getRetention('ENTITIES') !== null;
        }

        /**
         * Returns how long entities are kept, in seconds.
         *
         * @return int The retention in seconds, 0 if they are kept forever
         */
        public function getCleanEntitiesTtl(): int
        {
            return $this->getRetention('ENTITIES') ?? 0;
        }
    }
