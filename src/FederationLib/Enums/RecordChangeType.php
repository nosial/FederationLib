<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum RecordChangeType : string implements CaseSensitiveInterface
    {
        case OPERATOR_CREATED = 'OPERATOR_CREATED';
        case OPERATOR_UPDATED = 'OPERATOR_UPDATED';
        case OPERATOR_DISABLED = 'OPERATOR_DISABLED';
        case OPERATOR_ENABLED = 'OPERATOR_ENABLED';
        case OPERATOR_DELETED = 'OPERATOR_DELETED';

        case ENTITY_CREATED = 'ENTITY_CREATED';
        case ENTITY_UPDATED = 'ENTITY_UPDATED';
        case ENTITY_REPUTATION_UPDATED = 'ENTITY_REPUTATION_UPDATED';
        case ENTITY_DELETED = 'ENTITY_DELETED';

        case EVIDENCE_CREATED = 'EVIDENCE_CREATED';
        case EVIDENCE_UPDATED = 'EVIDENCE_UPDATED';
        case EVIDENCE_CLASSIFIED = 'EVIDENCE_CLASSIFIED';
        case EVIDENCE_DELETED = 'EVIDENCE_DELETED';

        case ATTACHMENT_CREATED = 'ATTACHMENT_CREATED';
        case ATTACHMENT_DELETED = 'ATTACHMENT_DELETED';

        case REPORT_CREATED = 'REPORT_CREATED';
        case REPORT_OPERATOR_ASSIGNED = 'REPORT_OPERATOR_ASSIGNED';
        case REPORT_CLOSED = 'REPORT_CLOSED';
        case REPORT_DELETED = 'REPORT_DELETED';

        case BLACKLIST_CREATED = 'BLACKLIST_CREATED';
        case BLACKLIST_EXTENDED = 'BLACKLIST_EXTENDED';
        case BLACKLIST_LIFTED = 'BLACKLIST_LIFTED';
        case BLACKLIST_DELETED = 'BLACKLIST_DELETED';

        /**
         * Returns the type of the record that was changed
         *
         * @return RecordType The record type
         */
        public function getRecordType(): RecordType
        {
            return match($this)
            {
                self::OPERATOR_CREATED,
                self::OPERATOR_UPDATED,
                self::OPERATOR_DISABLED,
                self::OPERATOR_ENABLED,
                self::OPERATOR_DELETED => RecordType::OPERATOR,

                self::ENTITY_CREATED,
                self::ENTITY_UPDATED,
                self::ENTITY_REPUTATION_UPDATED,
                self::ENTITY_DELETED => RecordType::ENTITY,

                self::EVIDENCE_CREATED,
                self::EVIDENCE_UPDATED,
                self::EVIDENCE_CLASSIFIED,
                self::EVIDENCE_DELETED => RecordType::EVIDENCE,

                self::ATTACHMENT_CREATED,
                self::ATTACHMENT_DELETED => RecordType::ATTACHMENT,

                self::REPORT_CREATED,
                self::REPORT_OPERATOR_ASSIGNED,
                self::REPORT_CLOSED,
                self::REPORT_DELETED => RecordType::REPORT,

                self::BLACKLIST_CREATED,
                self::BLACKLIST_EXTENDED,
                self::BLACKLIST_LIFTED,
                self::BLACKLIST_DELETED => RecordType::BLACKLIST,
            };
        }

        /**
         * Returns True if the change deleted the record
         *
         * @return bool True if the record no longer exists
         */
        public function isDeletion(): bool
        {
            return match($this)
            {
                self::OPERATOR_DELETED,
                self::ENTITY_DELETED,
                self::EVIDENCE_DELETED,
                self::ATTACHMENT_DELETED,
                self::REPORT_DELETED,
                self::BLACKLIST_DELETED => true,
                default => false
            };
        }

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?RecordChangeType
        {
            return self::tryFrom(strtoupper(trim($value)));
        }
    }
