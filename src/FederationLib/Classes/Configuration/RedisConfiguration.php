<?php

    namespace FederationLib\Classes\Configuration;

    class RedisConfiguration
    {
        private bool $enabled;
        private string $host;
        private int $port;
        private ?string $password;
        private int $database;
        private bool $throwOnErrors;
        private bool $preCacheEnabled;
        private bool $systemCachingEnabled;
        private bool $operatorCacheEnabled;
        private int $operatorCacheLimit;
        private int $operatorCacheTtl;
        private bool $entitiesCacheEnabled;
        private int $entitiesCacheLimit;
        private int $entitiesCacheTtl;
        private bool $fileAttachmentCacheEnabled;
        private int $fileAttachmentCacheLimit;
        private int $fileAttachmentCacheTtl;
        private bool $evidenceCacheEnabled;
        private int $evidenceCacheLimit;
        private int $evidenceCacheTtl;
        private bool $auditLogCacheEnabled;
        private int $auditLogCacheLimit;
        private int $auditLogCacheTtl;
        private bool $blacklistCacheEnabled;
        private int $blacklistCacheLimit;
        private int $blacklistCacheTtl;

        /**
         * RedisConfiguration constructor.
         *
         * @param array $configuration Array with Redis configuration values.
         */
        public function __construct(array $configuration)
        {
            $this->enabled = $configuration['enabled'] ?? false;
            $this->host = $configuration['host'] ?? '127.0.0.1';
            $this->port = $configuration['port'] ?? 6379;
            $this->password = $configuration['password'] ?? null;
            $this->database = $configuration['database'] ?? 0;
            $this->throwOnErrors = $configuration['throw_on_errors'] ?? true;
            $this->preCacheEnabled = $configuration['pre_cache_enabled'] ?? true;
            $this->systemCachingEnabled = $configuration['system_caching_enabled'] ?? true;
            $this->operatorCacheEnabled = $configuration['operator_cache_enabled'] ?? true;
            $this->operatorCacheLimit = $configuration['operator_cache_limit'] ?? 1000;
            $this->operatorCacheTtl = $configuration['operator_cache_ttl'] ?? 600;
            $this->entitiesCacheEnabled = $configuration['entity_cache_enabled'] ?? true;
            $this->entitiesCacheLimit = $configuration['entity_cache_limit'] ?? 5000;
            $this->entitiesCacheTtl = $configuration['entity_cache_ttl'] ?? 600;
            $this->fileAttachmentCacheEnabled = $configuration['file_attachment_cache_enabled'] ?? true;
            $this->fileAttachmentCacheLimit = $configuration['file_attachment_cache_limit'] ?? 2000;
            $this->fileAttachmentCacheTtl = $configuration['file_attachment_cache_ttl'] ?? 600;
            $this->evidenceCacheEnabled = $configuration['evidence_cache_enabled'] ?? true;
            $this->evidenceCacheLimit = $configuration['evidence_cache_limit'] ?? 3000;
            $this->evidenceCacheTtl = $configuration['evidence_cache_ttl'] ?? 600;
            $this->auditLogCacheEnabled = $configuration['audit_log_cache_enabled'] ?? true;
            $this->auditLogCacheLimit = $configuration['audit_log_cache_limit'] ?? 1000;
            $this->auditLogCacheTtl = $configuration['audit_log_cache_ttl'] ?? 600;
            $this->blacklistCacheEnabled = $configuration['blacklist_cache_enabled'] ?? true;
            $this->blacklistCacheLimit = $configuration['blacklist_cache_limit'] ?? 3000;
            $this->blacklistCacheTtl = $configuration['blacklist_cache_ttl'] ?? 600;
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

        /**
         * Returns True if errors should be thrown when Redis operations fail, otherwise False.
         *
         * @return bool True if errors should be thrown, False otherwise
         */
        public function shouldThrowOnErrors(): bool
        {
            return $this->throwOnErrors;
        }

        /**
         * Returns True if pre-caching is enabled, when enabled some database functions will attempt to pre-cache
         * objects before they are fully retrieved from the database, in such operations such as creating new objects
         * or updating existing objects, this may worsen the performance on some systems.
         *
         * @return bool True if pre-caching is enabled, False otherwise
         */
        public function isPreCacheEnabled(): bool
        {
            return $this->preCacheEnabled;
        }

        /**
         * Returns True if system caching is enabled, when enabled very specific system objects and variables are cached
         * for a slight performance increase, not necessarily required to have on but useful to take advantage of
         * caching where possible.
         *
         * @return bool
         */
        public function isSystemCachingEnabled(): bool
        {
            return $this->systemCachingEnabled;
        }

        /**
         * Returns True if operator caching is enabled
         *
         * @return bool True if operator caching is Enabled, False otherwise
         */
        public function isOperatorCacheEnabled(): bool
        {
            return $this->operatorCacheEnabled;
        }

        /**
         * Returns the operator cache limit for the cache
         *
         * @return int The maximum number of operators, anything 0 or less=no limit
         */
        public function getOperatorCacheLimit(): int
        {
            return $this->operatorCacheLimit;
        }

        /**
         * Returns the operator cache TTL in seconds
         *
         * @return int The time to live in seconds for the operator cache
         */
        public function getOperatorCacheTtl(): int
        {
            return $this->operatorCacheTtl;
        }

        /**
         * Returns True if entities caching is enabled
         *
         * @return bool True if entities caching is Enabled, False otherwise
         */
        public function isEntitiesCacheEnabled(): bool
        {
            return $this->entitiesCacheEnabled;
        }

        /**
         * Returns the entities cache limit for the cache
         *
         * @return int The maximum number of entities, anything 0 or less=no limit
         */
        public function getEntitiesCacheLimit(): int
        {
            return $this->entitiesCacheLimit;
        }

        /**
         * Returns the entities cache TTL in seconds
         *
         * @return int The time to live in seconds for the entities cache
         */
        public function getEntitiesCacheTtl(): int
        {
            return $this->entitiesCacheTtl;
        }

        /**
         * Returns True if file attachment caching is enabled
         *
         * @return bool True if file attachment caching is Enabled, False otherwise
         */
        public function isFileAttachmentCacheEnabled(): bool
        {
            return $this->fileAttachmentCacheEnabled;
        }

        /**
         * Returns the file attachment cache limit for the cache
         *
         * @return int The maximum number of file attachments, anything 0 or less=no limit
         */
        public function getFileAttachmentCacheLimit(): int
        {
            return $this->fileAttachmentCacheLimit;
        }

        /**
         * Returns the file attachment cache TTL in seconds
         *
         * @return int The time to live in seconds for the file attachment cache
         */
        public function getFileAttachmentCacheTtl(): int
        {
            return $this->fileAttachmentCacheTtl;
        }

        /**
         * Returns True if evidence caching is enabled
         *
         * @return bool True if evidence caching is Enabled, False otherwise
         */
        public function isEvidenceCacheEnabled(): bool
        {
            return $this->evidenceCacheEnabled;
        }

        /**
         * Returns the evidence cache limit for the cache
         *
         * @return int The maximum number of evidence records, anything 0 or less=no limit
         */
        public function getEvidenceCacheLimit(): int
        {
            return $this->evidenceCacheLimit;
        }

        /**
         * Returns the evidence cache TTL in seconds
         *
         * @return int The time to live in seconds for the evidence cache
         */
        public function getEvidenceCacheTtl(): int
        {
            return $this->evidenceCacheTtl;
        }

        /**
         * Returns True if audit log caching is enabled
         *
         * @return bool True if audit log caching is Enabled, False otherwise
         */
        public function isAuditLogCacheEnabled(): bool
        {
            return $this->auditLogCacheEnabled;
        }

        /**
         * Returns the audit log cache limit for the cache
         *
         * @return int The maximum number of audit log records, anything 0 or less=no limit
         */
        public function getAuditLogCacheLimit(): int
        {
            return $this->auditLogCacheLimit;
        }

        /**
         * Returns the audit log cache TTL in seconds
         *
         * @return int The time to live in seconds for the audit log cache
         */
        public function getAuditLogCacheTtl(): int
        {
            return $this->auditLogCacheTtl;
        }

        /**
         * Returns True if caching for blacklist records are enabled
         *
         * @return bool True if caching blacklists is Enabled, False otherwise.
         */
        public function isBlacklistCacheEnabled(): bool
        {
            return $this->blacklistCacheEnabled;
        }

        /**
         * Returns the limit of the amount of records that are allowed to be cached at once
         *
         * @return int The limit of the blacklist cache records
         */
        public function getBlacklistCacheLimit(): int
        {
            return $this->blacklistCacheLimit;
        }

        /**
         * Returns the Time-To-Live (TTL) of the Blacklist cache record.
         *
         * @return int The Time-To-Live for the cache entry
         */
        public function getBlacklistCacheTtl(): int
        {
            return $this->blacklistCacheTtl;
        }
    }
