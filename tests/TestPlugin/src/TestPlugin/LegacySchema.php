<?php

    namespace TestPlugin;

    /**
     * The database schema of FederationLib 1.0.14, the last release before 2.0.0 (schema version 1.0), used by the
     * migration check to build a database as that release created it. The table definitions are copied verbatim from
     * the 1.0.14 tag and must never be changed.
     */
    class LegacySchema
    {
        /**
         * The schema version 1.0.14 recorded in database_metadata
         */
        public const string VERSION = '1.0';

        /**
         * The table definitions in the order 1.0.14 created them
         */
        public const array TABLES = [
            'database_metadata' => <<<'SQL'
create table database_metadata
(
    `key`   varchar(64)                            not null comment 'The unique name of the metadata value, e.g. version'
        primary key,
    value   text                                   not null comment 'The metadata value',
    updated timestamp default current_timestamp() not null on update current_timestamp() comment 'The Timestamp for when this value was last updated'
)
    comment 'A key-value table for information about the database itself, such as the schema version';
SQL,
            'operators' => <<<'SQL'
create table operators
(
    uuid                   varchar(36)                            not null comment 'The Unique Primary Index for the operator UUID'
        primary key,
    name                   varchar(32)                            not null comment 'The public name of the operator',
    access_token           varchar(64)                            not null comment 'The securely stored representation of the operator Access Token. Real tokens are never stored in plaintext, only their SHA-256 hash, so the raw token can only be returned when it is generated or refreshed. The special literal "none" is used solely as the non-usable sentinel Access Token of the built-in system operator',
    client_permissions     tinyint(1) default 0                   not null comment 'Default: 0, 1=This operator has basic client permissions enabling evidence submission, entity pushing, attachment uploads, lookups, report submission, and content scanning. 0=No such permissions are allowed',
    management_permissions tinyint(1) default 0                   not null comment 'Default: 0, 1=This operator has management permissions, inheriting all client permissions plus record deletion, confidential evidence access, and blacklist management. 0=No such permissions are allowed',
    operator_permissions   tinyint(1) default 0                   not null comment 'Default: 0, 1=This operator has operator management permissions, allowing creation, deletion, and management of other operators. 0=No such permissions are allowed',
    auto_assign            tinyint(0) default 0                   not null comment 'The operator can get assigned reports automatically if True',
    disabled               tinyint(1) default 0                   not null comment 'Default: 0, 1=The operator is disabled, 0=The operator is active',
    created                timestamp  default current_timestamp() not null comment 'The Timestamp for when this operator record was created',
    updated                timestamp  default current_timestamp() not null comment 'The Timestamp for when this operator record was last updated',
    constraint operators_access_token_uindex
        unique (access_token),
    constraint operators_name_uindex
        unique (name)
);
create index operators_created_index
    on operators (created, uuid);
SQL,
            'entities' => <<<'SQL'
create table entities
(
    uuid                    varchar(36)                                not null comment 'The primary unique index of the entity'
        primary key,
    hash                    varchar(64)                                not null comment 'The Unique Hash combination of the entity (sha256 ''id@domain'', or ''id'' if no domain is set)',
    host                    varchar(255)                               not null comment 'The domain',
    id                      varchar(255)                               null comment 'The Unique Identifier of the entity',
    metadata                varchar(8192) default null                 null comment 'Optional. JSON data of the entity (8kb limit)',
    whitelisted             tinyint(0)    default 0                    not null comment 'Indicates if the entity is whitelisted from auto-reports, default=0',
    reputation              int           default 0                    not null comment 'The reputation score of the entity',
    reputation_last_updated timestamp     default null                 null comment 'The timestamp for when the reputation score was last updated',
    relationship_entity     varchar(36)                                null comment 'Optional. The target entity UUID that this entity has a relationship with',
    relationship_type       enum ('ALTERNATIVE', 'PROXY', 'DEPENDENT', 'CHILD') null comment 'Optional. The type of relationship with the target peer',
    created                 timestamp     default current_timestamp()  not null comment 'The Timestamp for when this entity was created',
    updated                 timestamp     default null                 null comment 'The Timestamp for when the entity record was last updated',
    constraint entities_hash_uindex
        unique (hash),
    constraint entities_id_domain_uindex
        unique (id, host),
    constraint entities_entities_uuid_fk
        foreign key (relationship_entity) references entities (uuid)
            on update cascade on delete set null
)
    comment 'A table for housing known entities in the database';
create index entities_created_index
    on entities (created, uuid);
create index entities_relationship_entity_index
    on entities (relationship_entity);
SQL,
            'reports' => <<<'SQL'
create table reports
(
    uuid                varchar(36)                                                                                                                 not null comment 'The Unique Universal Identifier of the report record',
    submitting_operator varchar(36)                                                                                                                 not null comment 'The UUID of the operator submitting the report',
    reporting_entity    varchar(36)                                                                                                                 null comment 'Optional. The UUID of the user entity submitting the report',
    assigned_operator   varchar(36)                                                                                                                 null comment 'Optional. The assigned operator that is handling the report',
    automated           tinyint(1)                                                                                        default 0                 not null comment 'Indicates if this report was an automated system report',
    incident_type       enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER') default 'OTHER'           not null comment 'The incident type for the report',
    opened              tinyint(1)                                                                                        default 1                 not null comment 'Indicates if the report is currently opened for review',
    message             text                                                                                                                        null comment 'Optional. The message provided with the report by the entity',
    created             timestamp                                                                                         default current_timestamp not null comment 'The timestamp for when the report was created',
    updated             timestamp                                                                                                                   null comment 'The Timestamp for when the record was last updated',
    primary key (uuid),
    constraint reports_entities_uuid_fk
        foreign key (reporting_entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint reports_operators_uuid_fk
        foreign key (submitting_operator) references operators (uuid)
            on update cascade on delete cascade,
    constraint reports_operators_uuid_fk_2
        foreign key (assigned_operator) references operators (uuid)
            on update cascade on delete set null
)
    comment 'Table for housing user submitted reports via clients';
create index reports_assigned_operator_created_uuid_index
    on reports (assigned_operator asc, created desc, uuid desc);
create index reports_created_uuid_index
    on reports (created desc, uuid desc);
create index reports_reporting_entity_created_uuid_index
    on reports (reporting_entity asc, created desc, uuid desc);
create index reports_submitting_operator_created_uuid_index
    on reports (submitting_operator asc, created desc, uuid desc);
SQL,
            'evidence' => <<<'SQL'
create table evidence
(
    uuid                varchar(36)                                not null comment 'The Unique Universal Identifier index for the evidence'
        primary key,
    entity              varchar(36)                                not null comment 'The UUID of the entity that this evidence is related to',
    operator            varchar(36)                                not null comment 'The operator that submitted the evidence',
    confidential        tinyint(1)  default 0                      not null comment 'Default: 0, 1=The evidence and all of it''s attachments is confidential and only operators can view this, 0=The evidence is available for public view',
    text_content        mediumtext                                 null comment 'Optional. the text content with the evidence',
    metadata            TEXT                                       null comment 'Optional. Metadata attached with the evidence record including abritrary JSON-encoded data',
    tag                 varchar(32)                                null comment 'Optional. Abstract tag name related to the evidence',
    report              varchar(36)                                null comment 'Optional. The UUID of the report that this evidence is associated with',
    classification_flag enum ('MALICIOUS', 'SUSPICIOUS', 'NORMAL') null comment 'Optional. The classification flag assigned to the evidence''s text content. This is used for content filtering',
    note                text                                       null comment 'Optional note by the operator that submitted the evidence',
    created             timestamp   default current_timestamp()    not null comment 'The timestamp of the evidence',
    updated             timestamp                                  null comment 'Optional. The Timestamp for when the record was last updated',
    constraint evidence_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint evidence_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete cascade,
    constraint evidence_reports_uuid_fk
        foreign key (report) references reports (uuid)
            on update cascade on delete set null
)
    comment 'Table for housing evidence';
create index evidence_created_index
    on evidence (created, uuid);
create index evidence_entity_created_index
    on evidence (entity asc, created desc);
create index evidence_operator_created_index
    on evidence (operator asc, created desc);
create index evidence_report_confidential_created_uuid_index
    on evidence (report asc, confidential asc, created desc, uuid desc);
create index evidence_tag_created_index
    on evidence (tag asc, created desc);
SQL,
            'file_attachments' => <<<'SQL'
create table file_attachments
(
    uuid      varchar(36)                             not null comment 'The Unique Universal Identifier Unique Index'
        primary key,
    evidence  varchar(36)                             not null comment 'The Unique Universal Identifier of the evidence that this file is attached to',
    file_name varchar(255)                            not null comment 'The name of the file',
    file_mime varchar(32)                             null comment 'Optional. The MIME type of the file attachment',
    file_size bigint                                  not null comment 'The size of the file',
    created   timestamp   default current_timestamp() not null comment 'The Timestamp for when this file attachment was created',
    constraint file_attachments_evidence_uuid_fk
        foreign key (evidence) references evidence (uuid)
            on update cascade on delete cascade
)
    comment 'A table for housing file attachments related to evidence records';
create index file_attachments_created_index
    on file_attachments (created desc, uuid desc);
create index file_attachments_evidence_index
    on file_attachments (evidence);
SQL,
            'blacklist' => <<<'SQL'
create table blacklist
(
    uuid      varchar(36)                                                                                       not null comment 'The Unique Universal Identifier Primary Unique Index'
        primary key,
    operator  varchar(36)                                                                                       not null comment 'The operator that created this blacklist record',
    entity    varchar(36)                                                                                       not null comment 'The target entity that is blacklisted',
    report    varchar(36)                                                                                       null comment 'Optional. The UUID of the report supporting the blacklist',
    type      enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER') not null comment 'The incident type',
    lifted    tinyint(1)  default 0                                                                             not null comment 'Default: 0, 1=The blacklist was lifted and is no longer in effect, 0=The blacklist is not lifted, it is in effect until it expires',
    lifted_by varchar(36)                                                                                       null comment 'Optional. If the blacklist was manually lifted by an operator, this column represents the operator UUID that made the change.',
    expires   timestamp                                                                                         null comment 'The timestamp for when the blacklist expires, if null the blacklist never expires',
    created   timestamp   default current_timestamp()                                                           not null comment 'The Timestamp for when the record was created',
    constraint blacklist_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint blacklist_reports_uuid_fk
        foreign key (report) references reports (uuid)
            on update cascade on delete set null,
    constraint blacklist_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete cascade,
    constraint blacklist_operators_uuid_fk_2
        foreign key (lifted_by) references operators (uuid)
            on update cascade on delete set null
)
    comment 'Table for housing one or more blacklist events';
create index blacklist_created_index
    on blacklist (created, uuid);
create index blacklist_entity_created_index
    on blacklist (entity asc, created desc);
create index blacklist_report_index
    on blacklist (report);
create index blacklist_operator_created_index
    on blacklist (operator asc, created desc);
SQL,
            'audit_log' => <<<'SQL'
create table audit_log
(
    uuid            varchar(36)                             not null comment 'The Unique Universal Identifier of the log record'
        primary key,
    operator        varchar(36)                             null comment 'Optional. The operator involved in the action',
    entity          varchar(36)                             null comment 'The Unique Universal Identifier of the entity involved in this action',
    blacklist       varchar(36)                             null comment 'Optional. The blacklist record related to this action',
    evidence        varchar(36)                             null comment 'Optional. The evidence record related to this action',
    file_attachment varchar(36)                             null comment 'Optional. The file attachment related to this action',
    type            varchar(36)                             not null comment 'The audit action type',
    message         text                                    not null comment 'The log message',
    timestamp       timestamp   default current_timestamp() not null comment 'The timestamp for when this log event was created',
    constraint audit_log_blacklist_uuid_fk
        foreign key (blacklist) references blacklist (uuid)
            on update cascade on delete set null,
    constraint audit_log_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete set null,
    constraint audit_log_evidence_uuid_fk
        foreign key (evidence) references evidence (uuid)
            on update cascade on delete set null,
    constraint audit_log_file_attachments_uuid_fk
        foreign key (file_attachment) references file_attachments (uuid)
            on update cascade on delete set null,
    constraint audit_log_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete set null
)
    comment 'The table for housing audit logs';
create index audit_log_entity_timestamp_uuid_index
    on audit_log (entity asc, timestamp desc, uuid desc);
create index audit_log_operator_timestamp_uuid_index
    on audit_log (operator asc, timestamp desc, uuid desc);
create index audit_log_timestamp_index
    on audit_log (timestamp desc, uuid desc);
create index audit_log_type_timestamp_uuid_index
    on audit_log (type asc, timestamp desc, uuid desc);
SQL,
        ];
    }
