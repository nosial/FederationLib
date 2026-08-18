<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class ServerInformation implements SerializableInterface, ObjectSpecificationInterface
    {
        private string $serverName;
        private string $apiVersion;
        private bool $publicAuditLogs;
        private bool $publicEvidence;
        private bool $publicBlacklist;
        private bool $publicEntities;
        private bool $publicReports;
        private bool $publicEntityMetadata;
        private bool $publicScanContent;
        private bool $publicQueryEntity;
        private bool $searchEnabled;
        private bool $publicSearch;
        /**
         * @var RecordType[]
         */
        private array $searchTypes;
        /**
         * @var RecordType[]
         */
        private array $publicSearchTypes;
        /**
         * @var AuditLogType[]
         */
        private array $publicAuditLogsVisibility;
        private int $auditLogRecords;
        private int $blacklistRecords;
        private int $knownEntities;
        private int $evidenceRecords;
        private int $fileAttachmentRecords;
        private int $operators;
        private int $reports;

        /**
         * Public constructor for the ServerInformation object
         *
         * @param array $config The configuration array containing server information
         */
        public function __construct(array $config)
        {
            $this->serverName = $config['server_name'] ?? 'Federation Server';
            $this->apiVersion = '1.0'; // ALWAYS '1.0' for now, as this is the version of the server API we are using.
            $this->publicAuditLogs = $config['public_audit_logs'] ?? true;
            $this->publicEvidence = $config['public_evidence'] ?? true;
            $this->publicBlacklist = $config['public_blacklist'] ?? true;
            $this->publicEntities = $config['public_entities'] ?? true;
            $this->publicEntityMetadata = $config['public_entity_metadata'] ?? false;
            $this->publicScanContent = $config['public_scan_content'] ?? false;
            $this->publicQueryEntity = $config['public_query_entity'] ?? true;
            $this->searchEnabled = $config['search_enabled'] ?? true;
            $this->publicSearch = $config['public_search'] ?? false;
            $this->searchTypes = isset($config['search_types']) ? array_map(
                fn($type) => RecordType::from($type),
                $config['search_types']
            ) : [];
            $this->publicSearchTypes = isset($config['public_search_types']) ? array_map(
                fn($type) => RecordType::from($type),
                $config['public_search_types']
            ) : [];
            $this->publicReports = $config['public_reports'] ?? true;
            $this->publicAuditLogsVisibility = isset($config['public_audit_logs_visibility']) ? array_map(
                fn($type) => AuditLogType::from($type),
                $config['public_audit_logs_visibility']
            ) : [];
            $this->auditLogRecords = $config['audit_log_records'] ?? 0;
            $this->blacklistRecords = $config['blacklist_records'] ?? 0;
            $this->knownEntities = $config['known_entities'] ?? 0;
            $this->evidenceRecords = $config['evidence_records'] ?? 0;
            $this->fileAttachmentRecords = $config['file_attachment_records'] ?? 0;
            $this->operators = $config['operators'] ?? 0;
            $this->reports = $config['reports'] ?? 0;
        }

        /**
         * Returns the server name
         *
         * @return string The name of the server
         */
        public function getServerName(): string
        {
            return $this->serverName;
        }

        /**
         * Returns the API version of the server
         *
         * @return string The API version
         */
        public function getApiVersion(): string
        {
            return $this->apiVersion;
        }

        /**
         * Returns whether the audit logs are public
         *
         * @return bool True if public, false otherwise
         */
        public function isPublicAuditLogs(): bool
        {
            return $this->publicAuditLogs;
        }

        /**
         * Returns whether the evidence is public
         *
         * @return bool True if public, false otherwise
         */
        public function isPublicEvidence(): bool
        {
            return $this->publicEvidence;
        }

        /**
         * Returns whether the blacklist is public
         *
         * @return bool True if public, false otherwise
         */
        public function isPublicBlacklist(): bool
        {
            return $this->publicBlacklist;
        }

        /**
         * Returns whether the entities are public
         *
         * @return bool True if public, false otherwise
         */
        public function isPublicEntities(): bool
        {
            return $this->publicEntities;
        }
        /**
         * Returns whether entity metadata is publicly included in responses.
         *
         * @return bool True if entity metadata is publicly accessible, false otherwise.
         */
        public function isPublicEntityMetadata(): bool
        {
            return $this->publicEntityMetadata;
        }

        /**
         * Returns whether content scanning is publicly accessible.
         *
         * @return bool True if content scanning is available without authentication, false otherwise.
         */
        public function isPublicScanContent(): bool
        {
            return $this->publicScanContent;
        }

        /**
         * Returns whether entity relationship queries are publicly accessible.
         *
         * @return bool True if entity relationship queries are available without authentication, false otherwise.
         */
        public function isPublicQueryEntity(): bool
        {
            return $this->publicQueryEntity;
        }

        /**
         * Returns whether search functionality is enabled.
         *
         * @return bool True if at least one search endpoint may be available, false otherwise.
         */
        public function isSearchEnabled(): bool
        {
            return $this->searchEnabled;
        }

        /**
         * Returns whether the global search endpoint is publicly accessible.
         *
         * @return bool True if global search is available without authentication, false otherwise.
         */
        public function isPublicSearch(): bool
        {
            return $this->publicSearch;
        }

        /**
         * Returns record types with enabled dedicated search endpoints.
         *
         * @return RecordType[]
         */
        public function getSearchTypes(): array
        {
            return $this->searchTypes;
        }

        /**
         * Returns record types whose dedicated search endpoints are publicly accessible.
         *
         * @return RecordType[]
         */
        public function getPublicSearchTypes(): array
        {
            return $this->publicSearchTypes;
        }


        /**
         * Returns an array of AuditLogType enums representing the visibility of public audit logs that
         * can be viewed without authentication.
         *
         * @return AuditLogType[]
         */
        public function getPublicAuditLogsVisibility(): array
        {
            return $this->publicAuditLogsVisibility;
        }

        /**
         * Returns the number of audit log records
         *
         * @return int The number of audit log records
         */
        public function getAuditLogRecords(): int
        {
            return $this->auditLogRecords;
        }

        /**
         * Returns the number of blacklist records
         *
         * @return int The number of blacklist records
         */
        public function getBlacklistRecords(): int
        {
            return $this->blacklistRecords;
        }

        /**
         * Returns the number of known entities
         *
         * @return int The number of known entities
         */
        public function getKnownEntities(): int
        {
            return $this->knownEntities;
        }

        /**
         * Returns the number of evidence records
         *
         * @return int The number of evidence records
         */
        public function getEvidenceRecords(): int
        {
            return $this->evidenceRecords;
        }

        /**
         * Returns the number of file attachment records
         *
         * @return int The number of file attachment records
         */
        public function getFileAttachmentRecords(): int
        {
            return $this->fileAttachmentRecords;
        }

        /**
         * Returns the number of operators
         *
         * @return int The number of operators
         */
        public function getOperators(): int
        {
            return $this->operators;
        }

        /**
         * Returns whether the reports are public
         *
         * @return bool True if public, false otherwise
         */
        public function isPublicReports(): bool
        {
            return $this->publicReports;
        }

        /**
         * Returns the number of reports
         *
         * @return int The number of reports
         */
        public function getReports(): int
        {
            return $this->reports;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'name' => $this->serverName,
                'api_version' => $this->apiVersion,
                'public_audit_logs' => $this->publicAuditLogs,
                'public_evidence' => $this->publicEvidence,
                'public_blacklist' => $this->publicBlacklist,
                'public_entities' => $this->publicEntities,
                'public_reports' => $this->publicReports,
                'public_entity_metadata' => $this->publicEntityMetadata,
                'public_scan_content' => $this->publicScanContent,
                'public_query_entity' => $this->publicQueryEntity,
                'search_enabled' => $this->searchEnabled,
                'public_search' => $this->publicSearch,
                'search_types' => array_map(fn(RecordType $type) => $type->value, $this->searchTypes),
                'public_search_types' => array_map(fn(RecordType $type) => $type->value, $this->publicSearchTypes),
                'public_audit_logs_visibility' => array_map(
                    fn(AuditLogType $type) => $type->value,
                    $this->publicAuditLogsVisibility
                ),
                'audit_log_records' => $this->auditLogRecords,
                'blacklist_records' => $this->blacklistRecords,
                'known_entities' => $this->knownEntities,
                'evidence_records' => $this->evidenceRecords,
                'file_attachment_records' => $this->fileAttachmentRecords,
                'operators' => $this->operators,
                'reports' => $this->reports,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ServerInformation
        {
            return new self($array);
        }

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'name' => ['type' => 'string', 'description' => 'Name of the federation server'],
                'api_version' => ['type' => 'string', 'description' => 'Version of the API'],
                'public_audit_logs' => ['type' => 'boolean', 'description' => 'Whether audit logs are publicly accessible'],
                'public_evidence' => ['type' => 'boolean', 'description' => 'Whether evidence is publicly accessible'],
                'public_blacklist' => ['type' => 'boolean', 'description' => 'Whether the blacklist is publicly accessible'],
                'public_entities' => ['type' => 'boolean', 'description' => 'Whether entities are publicly accessible'],
                'public_reports' => ['type' => 'boolean', 'description' => 'Whether reports are publicly accessible'],
                'public_entity_metadata' => ['type' => 'boolean', 'description' => 'Whether entity metadata is publicly included in responses'],
                'public_scan_content' => ['type' => 'boolean', 'description' => 'Whether content scanning is publicly accessible'],
                'public_query_entity' => ['type' => 'boolean', 'description' => 'Whether entity relationship queries are publicly accessible'],
                'search_enabled' => ['type' => 'boolean', 'description' => 'Whether search functionality is enabled'],
                'public_search' => ['type' => 'boolean', 'description' => 'Whether the global search endpoint is publicly accessible'],
                'search_types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Record types with enabled dedicated search endpoints',
                ],
                'public_search_types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Record types whose dedicated search endpoints are publicly accessible',
                ],
                'public_audit_logs_visibility' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Types of audit log entries that are publicly visible',
                ],
                'audit_log_records' => ['type' => 'integer', 'description' => 'Total number of audit log records'],
                'blacklist_records' => ['type' => 'integer', 'description' => 'Total number of blacklist records'],
                'known_entities' => ['type' => 'integer', 'description' => 'Total number of known entities'],
                'evidence_records' => ['type' => 'integer', 'description' => 'Total number of evidence records'],
                'file_attachment_records' => ['type' => 'integer', 'description' => 'Total number of file attachments'],
                'operators' => ['type' => 'integer', 'description' => 'Total number of operators'],
                'reports' => ['type' => 'integer', 'description' => 'Total number of reports'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return [
                'name',
                'api_version',
                'public_audit_logs',
                'public_evidence',
                'public_blacklist',
                'public_entities',
                'public_reports',
                'public_entity_metadata',
                'public_scan_content',
                'public_query_entity',
                'search_enabled',
                'public_search',
                'search_types',
                'public_search_types',
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ServerInformation';
        }
    }