<?php

    namespace FederationServer\Enums;

    enum AuditLogType : string
    {
        case OPERATOR_CREATED = 'OPERATOR_CREATED';
        case OPERATOR_DELETED = 'OPERATOR_DELETED';
        case OPERATOR_DISABLED = 'OPERATOR_DISABLED';
        case OPERATOR_ENABLED = 'OPERATOR_ENABLED';
        case OPERATOR_PERMISSIONS_CHANGED = 'OPERATOR_PERMISSIONS_CHANGED';

        case ATTACHMENT_UPLOADED = 'ATTACHMENT_UPLOADED';
        case ATTACHMENT_DELETED = 'ATTACHMENT_DELETED';

        case EVIDENCE_SUBMITTED = 'EVIDENCE_SUBMITTED';
        case EVIDENCE_DELETED = 'EVIDENCE_DELETED';

        case ENTITY_DELETED = 'ENTITY_DELETED';
        case ENTITY_BLACKLISTED = 'ENTITY_BLACKLISTED';
        case ENTITY_PUSHED = 'ENTITY_PUSHED';

        case BLACKLIST_RECORD_DELETED = 'BLACKLIST_DELETED';
        case BLACKLIST_LIFTED = 'BLACKLIST_LIFTED';
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
            ];
        }
    }