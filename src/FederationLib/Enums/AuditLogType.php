<?php

    namespace FederationLib\Enums;

    use FederationLib\Enums\Categories\AuditLogCategory;

    enum AuditLogType : string
    {
        case OPERATOR_CREATED = 'OPERATOR_CREATED';
        case OPERATOR_DELETED = 'OPERATOR_DELETED';
        case OPERATOR_DISABLED = 'OPERATOR_DISABLED';
        case OPERATOR_ENABLED = 'OPERATOR_ENABLED';
        case OPERATOR_PERMISSIONS_CHANGED = 'OPERATOR_PERMISSIONS_CHANGED';
        case OPERATOR_ACCESS_TOKEN_GENERATED = 'OPERATOR_ACCESS_TOKEN_GENERATED';
        case OPERATOR_NAME_CHANGED = 'OPERATOR_NAME_CHANGED';

        case ATTACHMENT_UPLOADED = 'ATTACHMENT_UPLOADED';
        case ATTACHMENT_DELETED = 'ATTACHMENT_DELETED';

        case EVIDENCE_SUBMITTED = 'EVIDENCE_SUBMITTED';
        case EVIDENCE_UPDATED = 'EVIDENCE_UPDATED';
        case EVIDENCE_DELETED = 'EVIDENCE_DELETED';

        case REPORT_GENERATED = 'REPORT_GENERATED';
        case REPORT_SUBMITTED = 'REPORT_SUBMITTED';
        case REPORT_OPERATOR_ASSIGNED = 'REPORT_OPERATOR_ASSIGNED';
        case REPORT_CLOSED = 'REPORT_CLOSED';
        case REPORT_DELETED = 'REPORT_DELETED';

        case ENTITY_DELETED = 'ENTITY_DELETED';
        case ENTITY_BLACKLISTED = 'ENTITY_BLACKLISTED';
        case ENTITY_PUSHED = 'ENTITY_PUSHED';
        case ENTITY_UPDATED = 'ENTITY_UPDATED';
        case ENTITY_REPUTATION_CLEARED = 'ENTITY_REPUTATION_CLEARED';

        case BLACKLIST_RECORD_DELETED = 'BLACKLIST_DELETED';
        case BLACKLIST_LIFTED = 'BLACKLIST_LIFTED';
        case BLACKLIST_EXTENDED = 'BLACKLIST_EXTENDED';
        case BLACKLIST_ATTACHMENT_ADDED = 'BLACKLIST_ATTACHMENT_ADDED';

        case OTHER = 'OTHER';

        /**
         * Returns an array of audit log types that are considered public.
         * These types can be shared with clients or logged publicly.
         *
         * @return AuditLogType[]
         */
        public static function getDefaultPublic(): array
        {
            return [
                self::OPERATOR_CREATED,
                self::OPERATOR_DELETED,
                self::ATTACHMENT_UPLOADED,
                self::ATTACHMENT_DELETED,
                self::EVIDENCE_SUBMITTED,
                self::EVIDENCE_DELETED,
                self::ENTITY_BLACKLISTED,
                self::ENTITY_UPDATED,
                self::REPORT_GENERATED
            ];
        }

        /**
         * Returns the category related to the audit log
         *
         * @return AuditLogCategory
         */
        public function getCategory(): AuditLogCategory
        {
            return match($this)
            {
                self::OPERATOR_CREATED,
                self::OPERATOR_DELETED,
                self::OPERATOR_DISABLED,
                self::OPERATOR_ENABLED,
                self::OPERATOR_PERMISSIONS_CHANGED,
                self::OPERATOR_ACCESS_TOKEN_GENERATED,
                self::OPERATOR_NAME_CHANGED => AuditLogCategory::OPERATOR_EVENTS,

                self::ATTACHMENT_UPLOADED,
                self::ATTACHMENT_DELETED => AuditLogCategory::ATTACHMENT_EVENTS,

                self::EVIDENCE_SUBMITTED,
                self::EVIDENCE_UPDATED,
                self::EVIDENCE_DELETED => AuditLogCategory::EVIDENCE_EVENTS,

                self::REPORT_GENERATED,
                self::REPORT_SUBMITTED,
                self::REPORT_OPERATOR_ASSIGNED,
                self::REPORT_CLOSED,
                self::REPORT_DELETED => AuditLogCategory::REPORT_EVENTS,

                self::ENTITY_DELETED,
                self::ENTITY_BLACKLISTED,
                self::ENTITY_PUSHED,
                self::ENTITY_REPUTATION_CLEARED => AuditLogCategory::ENTITY_EVENTS,

                self::BLACKLIST_RECORD_DELETED,
                self::BLACKLIST_LIFTED,
                self::BLACKLIST_EXTENDED,
                self::BLACKLIST_ATTACHMENT_ADDED => AuditLogCategory::BLACKLIST_EVENTS,

                default => AuditLogCategory::OTHER
            };
        }
    }