<?php

    namespace FederationServer\Objects;

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
         * Public constructor for the ServerInformation object
         *
         * @param array $config The configuration array containing server information
         */
        public function __construct(array $config)
        {
            $this->serverName = $config['server_name'] ?? 'Federation Server';
            $this->apiVersion = 'v1'; // ALWAYS 'v1' for now, as this is the version of the server API we are using.
            $this->publicAuditLogs = $config['public_audit_logs'] ?? true;
            $this->publicEvidence = $config['public_evidence'] ?? true;
            $this->publicBlacklist = $config['public_blacklist'] ?? true;
            $this->publicEntities = $config['public_entities'] ?? true;
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