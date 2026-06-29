<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class AuditLog implements SerializableInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private ?string $operatorUuid;
        private ?string $entityUuid;
        private ?string $blacklistUuid;
        private ?string $evidenceUuid;
        private ?string $fileAttachmentUuid;
        private AuditLogType $type;
        private string $message;
        private int $timestamp;

        /**
         * AuditLogRecord constructor.
         *
         * @param array $data Associative array of audit log data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->operatorUuid = $data['operator'] ?? null;
            $this->entityUuid = $data['entity'] ?? null;
            $this->blacklistUuid = $data['blacklist'] ?? null;
            $this->evidenceUuid = $data['evidence'] ?? null;
            $this->fileAttachmentUuid = $data['file_attachment'] ?? null;
            if(isset($data['type']))
            {
                if($data['type'] instanceof AuditLogType)
                {
                    $this->type = $data['type'];
                }
                else
                {
                    $this->type = AuditLogType::tryFrom($data['type']) ?? AuditLogType::OTHER;
                }
            }

            $this->message = $data['message'] ?? '';

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['timestamp']) && is_string($data['timestamp']))
            {
                // Numeric strings come from the Redis cache (hGetAll returns all hash values as strings)
                $data['timestamp'] = is_numeric($data['timestamp']) ? (int)$data['timestamp'] : strtotime($data['timestamp']);
            }
            elseif (isset($data['timestamp']) && $data['timestamp'] instanceof DateTime)
            {
                $data['timestamp'] = $data['timestamp']->getTimestamp();
            }
            else
            {
                $data['timestamp'] = $data['timestamp'] ?? time();
            }

            $this->timestamp = (int)($data['timestamp'] ?? time());
        }

        /**
         * Get the UUID of the audit log record.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the operator UUID associated with the audit log record.
         *
         * @return string|null
         */
        public function getOperatorUuid(): ?string
        {
            return $this->operatorUuid;
        }

        /**
         * Get the entity associated with the audit log record.
         *
         * @return string|null
         */
        public function getEntityUuid(): ?string
        {
            return $this->entityUuid;
        }

        /**
         * Get the blacklist UUID associated with the audit log record.
         *
         * @return string|null
         */
        public function getBlacklistUuid(): ?string
        {
            return $this->blacklistUuid;
        }

        /**
         * Get the evidence UUID associated with the audit log record.
         *
         * @return string|null
         */
        public function getEvidenceUuid(): ?string
        {
            return $this->evidenceUuid;
        }

        /**
         * Get the file attachment UUID associated with the audit log record.
         *
         * @return string|null
         */
        public function getFileAttachmentUuid(): ?string
        {
            return $this->fileAttachmentUuid;
        }

        /**
         * Get the type of the audit log record.
         *
         * @return AuditLogType
         */
        public function getType(): AuditLogType
        {
            return $this->type;
        }

        /**
         * Get the message of the audit log record.
         *
         * @return string
         */
        public function getMessage(): string
        {
            return $this->message;
        }

        /**
         * Get the timestamp of the audit log record.
         *
         * @return int
         */
        public function getTimestamp(): int
        {
            return $this->timestamp;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'operator' => $this->operatorUuid,
                'entity' => $this->entityUuid,
                'blacklist' => $this->blacklistUuid,
                'evidence' => $this->evidenceUuid,
                'file_attachment' => $this->fileAttachmentUuid,
                'type' => $this->type->value,
                'message' => $this->message,
                'timestamp' => $this->timestamp,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): AuditLog
        {
            if(isset($array['timestamp']))
            {
                if(is_string($array['timestamp']))
                {
                    $array['timestamp'] = is_numeric($array['timestamp']) ? (int)$array['timestamp'] : strtotime($array['timestamp']);
                }
                elseif($array['timestamp'] instanceof DateTime)
                {
                    $array['timestamp'] = $array['timestamp']->getTimestamp();
                }
            }

            if(isset($array['type']) && is_string($array['type']))
            {
                $array['type'] = AuditLogType::from($array['type']);
            }

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
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Unique identifier for the audit log entry'],
                'operator' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the operator who performed the action', 'nullable' => true],
                'entity' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the related entity', 'nullable' => true],
                'blacklist' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the related blacklist record', 'nullable' => true],
                'evidence' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the related evidence', 'nullable' => true],
                'file_attachment' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the related file attachment', 'nullable' => true],
                'type' => ['type' => 'string', 'description' => 'Type of audit log entry'],
                'message' => ['type' => 'string', 'description' => 'Human-readable description of the action'],
                'timestamp' => ['type' => 'integer', 'description' => 'Unix timestamp when the entry was created'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'type', 'message', 'timestamp'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/AuditLog';
        }
    }