<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\RecordType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Interfaces\StandardObjectInterface;

    class SearchResult implements SerializableInterface, ObjectSpecificationInterface
    {
        private RecordType $type;
        private EntityRecord|EvidenceRecord|BlacklistRecord|ReportRecord|FileAttachmentRecord|AuditLog|OperatorRecord $record;

        /**
         * SearchResult constructor
         *
         * @param RecordType $type The record type
         * @param EntityRecord|EvidenceRecord|BlacklistRecord|ReportRecord|FileAttachmentRecord|AuditLog|OperatorRecord $record The record object
         */
        public function __construct(RecordType $type, EntityRecord|EvidenceRecord|BlacklistRecord|ReportRecord|FileAttachmentRecord|AuditLog|OperatorRecord $record)
        {
            $this->type = $type;
            $this->record = $record;
        }

        /**
         * Returns the record type
         *
         * @return RecordType
         */
        public function getType(): RecordType
        {
            return $this->type;
        }

        /**
         * Returns the record object
         *
         * @return EntityRecord|EvidenceRecord|BlacklistRecord|ReportRecord|FileAttachmentRecord|AuditLog|OperatorRecord
         */
        public function getRecord(): EntityRecord|EvidenceRecord|BlacklistRecord|ReportRecord|FileAttachmentRecord|AuditLog|OperatorRecord
        {
            return $this->record;
        }

        /**
         * @inheritDoc
         */
        public function toArray(bool $includeMetadata=false): array
        {
            return [
                'type' => $this->type->value,
                'record' => match(true)
                {
                    $this->record instanceof EntityRecord => $this->record->toArray($includeMetadata),
                    // Standard representation omits sensitive members such as operator access tokens
                    $this->record instanceof StandardObjectInterface => $this->record->toStandardArray(),
                    default => $this->record->toArray(),
                }
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SearchResult
        {
            return new self(RecordType::from($array['type']), match(RecordType::from($array['type']))
            {
                RecordType::ENTITY => EntityRecord::fromArray($array['record']),
                RecordType::EVIDENCE => EvidenceRecord::fromArray($array['record']),
                RecordType::BLACKLIST => BlackListRecord::fromArray($array['record']),
                RecordType::REPORT => ReportRecord::fromArray($array['record']),
                RecordType::ATTACHMENT => FileAttachmentRecord::fromArray($array['record']),
                RecordType::AUDIT_LOG => AuditLog::fromArray($array['record']),
                RecordType::OPERATOR => OperatorRecord::fromArray($array['record']),
            });
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
                'type' => [
                    'type' => 'string',
                    'enum' => array_map(fn(RecordType $type) => $type->value, RecordType::cases()),
                    'description' => 'The record type of the matching record',
                ],
                'record' => [
                    // anyOf rather than oneOf, record schemas are open so a record could match more than one of them
                    'anyOf' => [
                        ['$ref' => EntityRecord::getReference()],
                        ['$ref' => EvidenceRecord::getReference()],
                        ['$ref' => BlacklistRecord::getReference()],
                        ['$ref' => ReportRecord::getReference()],
                        ['$ref' => FileAttachmentRecord::getReference()],
                        ['$ref' => AuditLog::getReference()],
                        ['$ref' => OperatorRecord::getReference()],
                    ],
                    'description' => 'The matching record, serialized in the form defined for its record type',
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['type', 'record'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/SearchResult';
        }
    }