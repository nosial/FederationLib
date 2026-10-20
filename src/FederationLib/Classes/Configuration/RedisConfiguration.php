<?php

    namespace FederationLib\Classes\Configuration;

    class RedisConfiguration
    {
        public const array DEFAULT_RECORD_CACHE = [
            'OPERATOR' => ['limit' => 1000, 'ttl' => 600],
            'ENTITY' => ['limit' => 5000, 'ttl' => 600],
            'FILE_ATTACHMENT' => ['limit' => 2000, 'ttl' => 600],
            'EVIDENCE' => ['limit' => 3000, 'ttl' => 600],
            'AUDIT_LOG' => ['limit' => 1000, 'ttl' => 600],
            'BLACKLIST' => ['limit' => 3000, 'ttl' => 600],
            'REPORT' => ['limit' => 1000, 'ttl' => 600],
        ];

        private bool $enabled;
        private string $host;
        private int $port;
        private ?string $password;
        private int $database;
        private bool $throwOnErrors;
        private bool $preCacheEnabled;
        /**
         * @var array<string, array{limit: int, ttl: int}>
         */
        private array $recordCache;
        private int $searchCacheTtl;

        /**
         * RedisConfiguration constructor.
         *
         * @param array $configuration The redis configuration values
         */
        public function __construct(array $configuration)
        {
            $this->enabled = ConfigurationValues::parseBool($configuration['enabled'] ?? null, false);
            $this->host = $configuration['host'] ?? '127.0.0.1';
            $this->port = (int)($configuration['port'] ?? 6379);
            $this->password = $configuration['password'] ?? null;
            $this->database = (int)($configuration['database'] ?? 0);
            $this->throwOnErrors = ConfigurationValues::parseBool($configuration['throw_on_errors'] ?? null, true);
            $this->preCacheEnabled = ConfigurationValues::parseBool($configuration['pre_cache_enabled'] ?? null, true);
            $this->searchCacheTtl = ConfigurationValues::parseDuration($configuration['search_cache_ttl'] ?? 60, 60) ?? 0;
            $configured = ConfigurationValues::parseMap($configuration['record_cache'] ?? []);
            $this->recordCache = [];
            foreach(self::DEFAULT_RECORD_CACHE as $type => $default)
            {
                $value = $configured[$type] ?? null;
                if(is_string($value))
                {
                    $parts = explode('/', $value, 2);
                    $value = ['limit' => $parts[0], 'ttl' => $parts[1] ?? $default['ttl']];
                }

                $value = is_array($value) ? $value : [];
                $this->recordCache[$type] = [
                    'limit' => max(0, (int)($value['limit'] ?? $default['limit'])),
                    'ttl' => ConfigurationValues::parseDuration($value['ttl'] ?? $default['ttl'], $default['ttl']) ?? 0,
                ];
            }
        }

        /**
         * Checks if the cache layer is enabled.
         *
         * @return bool True if enabled
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Returns the host of the Redis server.
         *
         * @return string The host
         */
        public function getHost(): string
        {
            return $this->host;
        }

        /**
         * Returns the port of the Redis server.
         *
         * @return int The port
         */
        public function getPort(): int
        {
            return $this->port;
        }

        /**
         * Returns the password of the Redis server.
         *
         * @return string|null The password, null if none is required
         */
        public function getPassword(): ?string
        {
            return $this->password;
        }

        /**
         * Returns the Redis database index.
         *
         * @return int The database index
         */
        public function getDatabase(): int
        {
            return $this->database;
        }

        /**
         * Checks if cache errors are thrown rather than logged.
         *
         * @return bool True if cache errors are thrown
         */
        public function shouldThrowOnErrors(): bool
        {
            return $this->throwOnErrors;
        }

        /**
         * Checks if records are cached before they are requested (eg; the records of a listing).
         *
         * @return bool True if pre-caching is enabled
         */
        public function isPreCacheEnabled(): bool
        {
            return $this->preCacheEnabled;
        }

        /**
         * Returns the maximum number of cached records of a record type, 0 when its cache is disabled
         *
         * @param string $type The record type, one of the keys of DEFAULT_RECORD_CACHE
         * @return int The maximum number of cached records
         */
        public function getRecordCacheLimit(string $type): int
        {
            return $this->recordCache[$type]['limit'] ?? 0;
        }

        /**
         * Returns the TTL of cached records of a record type in seconds, 0 for no expiry
         *
         * @param string $type The record type, one of the keys of DEFAULT_RECORD_CACHE
         * @return int The TTL in seconds
         */
        public function getRecordCacheTtl(string $type): int
        {
            return $this->recordCache[$type]['ttl'] ?? 0;
        }

        /**
         * Checks if records of a record type are cached
         *
         * @param string $type The record type, one of the keys of DEFAULT_RECORD_CACHE
         * @return bool True if the cache of the record type is enabled
         */
        public function isRecordCacheEnabled(string $type): bool
        {
            return $this->getRecordCacheLimit($type) > 0;
        }

        /**
         * Determines if the operator cache is enabled.
         *
         * @return bool True if the operator cache is enabled, false otherwise.
         */
        public function isOperatorCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('OPERATOR');
        }

        /**
         * Retrieves the cache limit for operators.
         *
         * @return int The cache limit value for operators.
         */
        public function getOperatorCacheLimit(): int
        {
            return $this->getRecordCacheLimit('OPERATOR');
        }

        /**
         * Retrieves the cache time-to-live (TTL) value for the operator cache.
         *
         * @return int The TTL value for the operator cache, in seconds.
         */
        public function getOperatorCacheTtl(): int
        {
            return $this->getRecordCacheTtl('OPERATOR');
        }

        /**
         * Determines if the entities cache is enabled.
         *
         * @return bool True if the entities cache is enabled, false otherwise.
         */
        public function isEntitiesCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('ENTITY');
        }

        /**
         * Retrieves the cache limit for entities.
         *
         * @return int The maximum number of entities allowed in the cache.
         */
        public function getEntitiesCacheLimit(): int
        {
            return $this->getRecordCacheLimit('ENTITY');
        }

        /**
         * Retrieves the cache time-to-live (TTL) for entities.
         *
         * @return int The TTL value for the entities cache, in seconds.
         */
        public function getEntitiesCacheTtl(): int
        {
            return $this->getRecordCacheTtl('ENTITY');
        }

        /**
         * Determines if the file attachment cache is enabled.
         *
         * @return bool True if the file attachment cache is enabled, false otherwise.
         */
        public function isFileAttachmentCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('FILE_ATTACHMENT');
        }

        /**
         * Retrieves the cache limit for file attachments.
         *
         * @return int The cache limit for file attachments.
         */
        public function getFileAttachmentCacheLimit(): int
        {
            return $this->getRecordCacheLimit('FILE_ATTACHMENT');
        }

        /**
         * Retrieves the cache time-to-live (TTL) for file attachments.
         *
         * @return int The cache TTL in seconds for file attachments.
         */
        public function getFileAttachmentCacheTtl(): int
        {
            return $this->getRecordCacheTtl('FILE_ATTACHMENT');
        }

        /**
         * Determines if the evidence cache is enabled.
         *
         * @return bool True if the evidence cache is enabled, false otherwise.
         */
        public function isEvidenceCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('EVIDENCE');
        }

        /**
         * Retrieves the cache limit for evidence records.
         *
         * @return int The maximum number of evidence records allowed in the cache.
         */
        public function getEvidenceCacheLimit(): int
        {
            return $this->getRecordCacheLimit('EVIDENCE');
        }

        /**
         * Retrieves the time-to-live (TTL) value for the evidence cache.
         *
         * @return int The TTL value for the evidence cache in seconds.
         */
        public function getEvidenceCacheTtl(): int
        {
            return $this->getRecordCacheTtl('EVIDENCE');
        }

        /**
         * Determines if the audit log cache is enabled.
         *
         * @return bool True if the audit log cache is enabled, false otherwise.
         */
        public function isAuditLogCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('AUDIT_LOG');
        }

        /**
         * Retrieves the cache limit for audit logs.
         *
         * @return int The maximum number of audit log entries that can be cached.
         */
        public function getAuditLogCacheLimit(): int
        {
            return $this->getRecordCacheLimit('AUDIT_LOG');
        }

        /**
         * Retrieves the time-to-live (TTL) value for the audit log cache.
         *
         * @return int The TTL value for the audit log cache, in seconds.
         */
        public function getAuditLogCacheTtl(): int
        {
            return $this->getRecordCacheTtl('AUDIT_LOG');
        }

        /**
         * Determines if the blacklist cache is enabled.
         *
         * @return bool True if the blacklist cache is enabled, false otherwise.
         */
        public function isBlacklistCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('BLACKLIST');
        }

        /**
         * Retrieves the cache limit for the blacklist.
         *
         * @return int The maximum number of blacklist records that can be stored in the cache.
         */
        public function getBlacklistCacheLimit(): int
        {
            return $this->getRecordCacheLimit('BLACKLIST');
        }

        /**
         * Retrieves the time-to-live (TTL) value for the blacklist cache.
         *
         * @return int The TTL value for the blacklist cache in seconds.
         */
        public function getBlacklistCacheTtl(): int
        {
            return $this->getRecordCacheTtl('BLACKLIST');
        }

        /**
         * Determines if the report cache is enabled.
         *
         * @return bool True if the report cache is enabled, false otherwise.
         */
        public function isReportCacheEnabled(): bool
        {
            return $this->isRecordCacheEnabled('REPORT');
        }

        /**
         * Retrieves the cache limit for reports.
         *
         * @return int The cache limit for reports
         */
        public function getReportCacheLimit(): int
        {
            return $this->getRecordCacheLimit('REPORT');
        }

        /**
         * Retrieves the cache TTL (Time to Live) for report records.
         *
         * @return int The cache TTL for reports in seconds
         */
        public function getReportCacheTtl(): int
        {
            return $this->getRecordCacheTtl('REPORT');
        }

        /**
         * Returns the TTL of cached search and listing results in seconds.
         *
         * @return int The TTL in seconds
         */
        public function getSearchCacheTtl(): int
        {
            return $this->searchCacheTtl;
        }
    }
