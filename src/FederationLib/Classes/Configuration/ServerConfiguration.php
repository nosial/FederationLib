<?php

    namespace FederationLib\Classes\Configuration;

    use FederationLib\Enums\AuditLogType;

    class ServerConfiguration
    {
        private string $baseUrl;
        private string $name;
        private ?string $apiKey;
        private int $maxUploadSize;
        private string $storagePath;
        private int $listAuditLogsMaxItems;
        private int $listEntitiesMaxItems;
        private int $listOperatorsMaxItems;
        private int $listEvidenceMaxItems;
        private int $listBlacklistMaxItems;
        private bool $publicAuditLogs;
        /**
         * @var AuditLogType[]
         */
        private array $publicAuditEntries;
        private bool $publicEvidence;
        private bool $publicBlacklist;
        private bool $publicEntities = true;
        private int $minBlacklistTime;

        /**
         * ServerConfiguration constructor.
         *
         * @param array $config Configuration array containing server settings.
         */
        public function __construct(array $config)
        {
            $this->baseUrl = $config['base_url'] ?? 'http://127.0.0.1:6161';
            $this->name = $config['name'] ?? 'Federation Server';
            $this->apiKey = $config['api_key'] ?? null;
            $this->maxUploadSize = $config['max_upload_size'] ?? 52428800; // 50MB default
            $this->storagePath = $config['storage_path'] ?? '/var/www/uploads';
            $this->listAuditLogsMaxItems = $config['list_audit_logs_max_items'] ?? 100;
            $this->listEntitiesMaxItems = $config['list_entities_max_items'] ?? 100;
            $this->listOperatorsMaxItems = $config['list_operators_max_items'] ?? 100;
            $this->listEvidenceMaxItems = $config['list_evidence_max_items'] ?? 100;
            $this->listBlacklistMaxItems = $config['list_blacklist_max_items'] ?? 100;
            $this->publicAuditLogs = $config['public_audit_logs'] ?? true;
            // TODO: Why not make operators a public record thing to see? could be implemented by default disabled.
            $this->publicAuditEntries = array_map(fn($type) => AuditLogType::from($type), $config['public_audit_entries'] ?? []);
            $this->publicEvidence = $config['public_evidence'] ?? true;
            $this->publicBlacklist = $config['public_blacklist'] ?? true;
            $this->publicEntities = $config['public_entities'] ?? true;
            $this->minBlacklistTime = $config['min_blacklist_time'] ?? 1800;
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
         * Get the master API key for the server.
         *
         * @return string|null The API key, or null if not set.
         */
        public function getApiKey(): ?string
        {
            return $this->apiKey;
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
         * Get the maximum number of items to return when listing audit logs.
         *
         * @return int The maximum number of audit log items to return.
         */
        public function getListAuditLogsMaxItems(): int
        {
            return $this->listAuditLogsMaxItems;
        }

        /**
         * Get the maximum number of items to return when listing entities.
         *
         * @return int The maximum number of entity items to return.
         */
        public function getListEntitiesMaxItems(): int
        {
            return $this->listEntitiesMaxItems;
        }

        /**
         * Get the maximum number of items to return when listing operators.
         *
         * @return int The maximum number of operator items to return.
         */
        public function getListOperatorsMaxItems(): int
        {
            return $this->listOperatorsMaxItems;
        }

        /**
         * Get the maximum number of items to return when listing evidence.
         *
         * @return int The maximum number of evidence items to return.
         */
        public function getListEvidenceMaxItems(): int
        {
            return $this->listEvidenceMaxItems;
        }

        /**
         * Get the maximum number of items to return when listing blacklists.
         *
         * @return int The maximum number of blacklist items to return.
         */
        public function getListBlacklistMaxItems(): int
        {
            return $this->listBlacklistMaxItems;
        }

        /**
         * Check if audit logs are publicly accessible.
         *
         * @return bool True if public audit logs are enabled, false otherwise.
         */
        public function isAuditLogsPublic(): bool
        {
            return $this->publicAuditLogs;
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
            return $this->publicEvidence;
        }

        /**
         * Checks if blacklist records is publicly accessible
         *
         * @return bool True if public blacklist is enabled, false otherwise
         */
        public function isBlacklistPublic(): bool
        {
            return $this->publicBlacklist;
        }

        /**
         * Checks if entities are publicly accessible
         *
         * @return bool True if public entities is enabled, false otherwise
         */
        public function isEntitiesPublic(): bool
        {
            return $this->publicEntities;
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
    }
