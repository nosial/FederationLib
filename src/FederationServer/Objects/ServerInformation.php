<?php

    namespace FederationServer\Objects;

    use FederationServer\Enums\AuditLogType;
    use FederationServer\Interfaces\SerializableInterface;

    class ServerInformation implements SerializableInterface
    {
        private string $serverName;
        private string $apiVersion;
        private bool $publicAuditLogs;
        private bool $publicEvidence;
        private bool $publicBlacklist;
        private bool $publicEntities;
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

        /**
         * Public constructor for the ServerInformation object
         *
         * @param array $config The configuration array containing server information
         */
        public function __construct(array $config)
        {
            $this->serverName = $config['server_name'] ?? 'Federation Server';
            $this->apiVersion = '2025.01'; // ALWAYS '2025.01' for now, as this is the version of the server API we are using.
            $this->publicAuditLogs = $config['public_audit_logs'] ?? true;
            $this->publicEvidence = $config['public_evidence'] ?? true;
            $this->publicBlacklist = $config['public_blacklist'] ?? true;
            $this->publicEntities = $config['public_entities'] ?? true;
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
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ServerInformation
        {
            return new self($array);
        }
    }