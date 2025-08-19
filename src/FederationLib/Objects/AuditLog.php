<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Interfaces\SerializableInterface;

    class AuditLog implements SerializableInterface
    {
        private string $uuid;
        private ?string $operator;
        private ?string $entity;
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
            $this->operator = $data['operator'] ?? null;
            $this->entity = $data['entity'] ?? null;
            $this->type = isset($data['type']) ? AuditLogType::from($data['type']) : AuditLogType::OTHER;
            $this->message = $data['message'] ?? '';
            $this->timestamp = isset($data['timestamp']) ? (int)$data['timestamp'] : time();
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
        public function getOperator(): ?string
        {
            return $this->operator;
        }

        /**
         * Get the entity associated with the audit log record.
         *
         * @return string|null
         */
        public function getEntity(): ?string
        {
            return $this->entity;
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
                'operator' => $this->operator,
                'entity' => $this->entity,
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
                    $array['timestamp'] = strtotime($array['timestamp']);
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
    }