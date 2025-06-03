<?php

    namespace FederationServer\Objects;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Interfaces\SerializableInterface;
    use InvalidArgumentException;

    class PublicAuditRecord implements SerializableInterface
    {
        private string $uuid;
        private ?PublicOperatorRecord $operator;
        private ?EntityRecord $entity;
        private AuditLogType $type;
        private string $message;
        private int $timestamp;

        public function __construct(AuditLogRecord $auditLogRecord, OperatorRecord|PublicOperatorRecord|null $operator=null, ?EntityRecord $entity=null)
        {
            if($operator instanceof OperatorRecord)
            {
                $operator = $operator->toPublicRecord();
            }

            $this->uuid = $auditLogRecord->getUuid();
            $this->operator = $operator;
            $this->entity = $entity;
            $this->type = $auditLogRecord->getType();
            $this->message = $auditLogRecord->getMessage();
            $this->timestamp = $auditLogRecord->getTimestamp();
        }

        public function getUuid(): string
        {
            return $this->uuid;
        }

        public function getOperator(): ?PublicOperatorRecord
        {
            return $this->operator;
        }

        public function getEntity(): ?EntityRecord
        {
            return $this->entity;
        }

        public function getType(): AuditLogType
        {
            return $this->type;
        }

        public function getMessage(): string
        {
            return $this->message;
        }

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
                'operator' => $this->operator?->toArray(),
                'entity' => $this->entity?->toArray(),
                'type' => $this->type->value,
                'message' => $this->message,
                'timestamp' => $this->timestamp
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SerializableInterface
        {
            throw new InvalidArgumentException('fromArray() is not implemented in PublicAuditRecord');
        }
    }