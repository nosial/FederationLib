<?php

    namespace FederationLib\Classes\Configuration;

    use FederationLib\Enums\AuditLogType;

    class ServerConfiguration
    {
        /**
         * What may be made accessible without authentication, the items of server.public
         */
        public const array PUBLIC_ITEMS = [
            'AUDIT_LOGS', 'EVIDENCE', 'BLACKLIST', 'ENTITIES', 'ENTITY_METADATA', 'REPORTS', 'SCAN_CONTENT',
            'SCAN_FILES', 'QUERY_ENTITY', 'SEARCH', 'TELEPHONE_ENTITIES',
        ];

        /**
         * What is accessible without authentication by default
         */
        public const array DEFAULT_PUBLIC = ['AUDIT_LOGS', 'EVIDENCE', 'BLACKLIST', 'ENTITIES', 'REPORTS', 'SCAN_CONTENT', 'QUERY_ENTITY'];

        private string $baseUrl;
        private string $name;
        private ?string $accessToken;
        private int $maxUploadSize;
        private string $storagePath;
        private int $maxPageSize;
        /**
         * @var string[]
         */
        private array $public;
        /**
         * @var AuditLogType[]
         */
        private array $publicAuditEntries;
        private int $minBlacklistTime;
        private int $topThreatsLimit;
        private bool $allowIllegalContent;
        private bool $linkSubdomainEntities;

        /**
         * ServerConfiguration constructor.
         *
         * @param array $config Configuration array containing server settings.
         */
        public function __construct(array $config)
        {
            $this->baseUrl = $config['base_url'] ?? 'http://127.0.0.1:7000';
            $this->name = $config['name'] ?? 'Federation Server';
            $this->accessToken = $config['access_token'] ?? null;
            $this->maxUploadSize = $config['max_upload_size'] ?? 52428800; // 50MB default
            $this->storagePath = $config['storage_path'] ?? '/var/www/uploads';
            // The maximum number of records a list method returns, and the default page size of lists
            $this->maxPageSize = max(1, (int)($config['max_page_size'] ?? 100));
            // What is accessible without authentication (OFD "Public Accessibility")
            $this->public = ConfigurationValues::parseList($config['public'] ?? null, self::PUBLIC_ITEMS, self::DEFAULT_PUBLIC);
            $this->publicAuditEntries = array_values(array_filter(array_map(
                fn($type) => is_string($type) ? AuditLogType::tryFrom(strtoupper(trim($type))) : null,
                is_string($config['public_audit_entries'] ?? null) ? explode(',', $config['public_audit_entries']) : ($config['public_audit_entries'] ?? [])
            )));
            $this->minBlacklistTime = $config['min_blacklist_time'] ?? 1800;
            $this->topThreatsLimit = $config['top_threats_limit'] ?? 25;
            $this->allowIllegalContent = $config['allow_illegal_content'] ?? true;
            $this->linkSubdomainEntities = $config['link_subdomain_entities'] ?? true;
        }

        /**
         * Get the base URL of the server.
         *
         * @return string The base URL of the server.
         */
        public function getBaseUrl(): string
        {
            return $this->baseUrl;
        }

        /**
         * Get the name of the server.
         *
         * @return string The name of the server.
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Get the master Access Token for the server.
         *
         * @return string|null The Access Token, or null if not set.
         */
        public function getAccessToken(): ?string
        {
            return $this->accessToken;
        }

        /**
         * Get the maximum allowed upload size in bytes.
         *
         * @return int Maximum upload size in bytes
         */
        public function getMaxUploadSize(): int
        {
            return $this->maxUploadSize;
        }

        /**
         * Get the path where files are stored.
         *
         * @return string The storage path for uploaded files.
         */
        public function getStoragePath(): string
        {
            return $this->storagePath;
        }

        /**
         * Returns the maximum number of records a list method returns, also its default page size.
         *
         * @return int The maximum page size
         */
        public function getMaxPageSize(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Checks if an item is accessible without authentication.
         *
         * @param string $item One of PUBLIC_ITEMS, eg; EVIDENCE
         * @return bool True if the item is public
         */
        public function isPublic(string $item): bool
        {
            return in_array($item, $this->public, true);
        }




        /**
         * Get the maximum number of items to return when listing audit logs.
         *
         * @return int The maximum number of audit log items to return.
         */
        public function getListAuditLogsMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Get the maximum number of items to return when listing entities.
         *
         * @return int The maximum number of entity items to return.
         */
        public function getListEntitiesMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Get the maximum number of items to return when listing operators.
         *
         * @return int The maximum number of operator items to return.
         */
        public function getListOperatorsMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Get the maximum number of items to return when listing evidence.
         *
         * @return int The maximum number of evidence items to return.
         */
        public function getListEvidenceMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Get the maximum number of items to return when listing blacklists.
         *
         * @return int The maximum number of blacklist items to return.
         */
        public function getListBlacklistMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Get the maximum number of items to return when listing file attachments.
         *
         * @return int The maximum number of file attachment items to return.
         */
        public function getListAttachmentsMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Get the maximum number of items to return when listing reports
         *
         * @return int The maximum number of reports to return
         */
        public function getListReportsMaxItems(): int
        {
            return $this->maxPageSize;
        }

        /**
         * Check if audit logs are publicly accessible.
         *
         * @return bool True if public audit logs are enabled, false otherwise.
         */
        public function isAuditLogsPublic(): bool
        {
            return $this->isPublic('AUDIT_LOGS');
        }

        /**
         * Get the list of public audit entries.
         *
         * @return AuditLogType[] The list of public audit entries.
         */
        public function getPublicAuditEntries(): array
        {
            return $this->publicAuditEntries;
        }

        /**
         * Check if evidence is publicly accessible.
         *
         * @return bool True if public evidence is enabled, false otherwise.
         */
        public function isEvidencePublic(): bool
        {
            return $this->isPublic('EVIDENCE');
        }

        /**
         * Checks if blacklist records is publicly accessible
         *
         * @return bool True if public blacklist is enabled, false otherwise
         */
        public function isBlacklistPublic(): bool
        {
            return $this->isPublic('BLACKLIST');
        }

        /**
         * Checks if entities are publicly accessible
         *
         * @return bool True if public entities is enabled, false otherwise
         */
        public function isEntitiesPublic(): bool
        {
            return $this->isPublic('ENTITIES');
        }

        /**
         * Checks if entity metadata is publicly accessible to unauthenticated users
         *
         * @return bool True if entity metadata is public, false otherwise
         */
        public function isEntityMetadataPublic(): bool
        {
            return $this->isPublic('ENTITY_METADATA');
        }

        /**
         * Checks if reports are public accessible
         *
         * @return bool True if public reports rae enabled, false otherwise
         */
        public function isReportsPublic(): bool
        {
            return $this->isPublic('REPORTS');
        }

        /**
         * Checks if scan content is publicly accessible
         *
         * @return bool True if public scan content is enabled, false otherwise
         */
        public function isScanContentPublic(): bool
        {
            return $this->isPublic('SCAN_CONTENT');
        }

        /**
         * Checks if files are scanned for requests without client permissions, which also requires scan content to be
         * public; otherwise the files of such requests are ignored (OFD "Scanned files")
         *
         * @return bool True if the files of unauthenticated scan requests are scanned
         */
        public function isScanFilesPublic(): bool
        {
            return $this->isPublic('SCAN_FILES') && $this->isScanContentPublic();
        }

        /**
         * Checks if entity relationship queries are publicly accessible.
         *
         * @return bool True if public entity queries are enabled, false otherwise.
         */
        public function isQueryEntityPublic(): bool
        {
            return $this->isPublic('QUERY_ENTITY');
        }

        /**
         * Returns the minimum allowed time that a blacklist could be set to expire, for example
         * 1800 = 30 Minutes, if a blacklist is set to expire within 30 minutes or more, it's valid, otherwise
         * anything less than that if it isn't null would be considered invalid.
         *
         * @return int The number of seconds allowed
         */
        public function getMinBlacklistTime(): int
        {
            return $this->minBlacklistTime;
        }

        /**
         * Get the maximum number of top threats to return.
         *
         * @return int The maximum number of top threats to return.
         */
        public function getTopThreatsLimit(): int
        {
            return $this->topThreatsLimit;
        }

        /**
         * Checks if reports with the ILLEGAL_CONTENT incident type may be submitted to this server. Server hosts
         * who do not want to handle illegal content can disable this, causing such submissions to be rejected.
         *
         * @return bool True if ILLEGAL_CONTENT reports are accepted, false otherwise
         */
        public function isIllegalContentAllowed(): bool
        {
            return $this->allowIllegalContent;
        }

        /**
         * Checks if newly registered subdomain host entities (e.g. sub.example.com) are automatically linked to the
         * entity of their registrable domain (example.com) as a CHILD, registering that domain entity if needed.
         *
         * @return bool True if subdomain entities are linked automatically, false otherwise
         */
        public function isLinkSubdomainEntitiesEnabled(): bool
        {
            return $this->linkSubdomainEntities;
        }

        /**
         * Checks if telephone entities (eg; 15550123456@e164.arpa) are exposed to unauthenticated requests like other
         * entities. When false (the default, as OFD recommends) they are hidden from unauthenticated requests that do
         * not already know the number: omitted from lists and searches, not resolvable by UUID, and without metadata.
         *
         * @return bool True if telephone entities are public, false otherwise
         */
        public function isTelephoneEntitiesPublic(): bool
        {
            return $this->isPublic('TELEPHONE_ENTITIES');
        }
    }
