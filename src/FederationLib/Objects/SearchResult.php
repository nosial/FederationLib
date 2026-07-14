<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\RecordType;
    use FederationLib\Interfaces\SerializableInterface;

    class SearchResult implements SerializableInterface
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
        public function toArray(): array
        {
            return [
                'type' => $this->type->value,
                'record' => $this->record->toArray()
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SearchResult
        {
            return new self(RecordType::from($array['record_type']), match(RecordType::from($array['record_type']))
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
        public static function getReference(): string
        {
            return '#/components/schemas/SearchResult';
        }
    }