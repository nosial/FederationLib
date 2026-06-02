create table audit_log
(
    uuid            varchar(36) default uuid()              not null comment 'The Unique Universal Identifier of the log record'
        primary key comment 'The Unique Universal Identifier primary unique index',
    operator        varchar(36)                             null comment 'Optional. The operator involved in the action',
    entity          varchar(36)                             null comment 'The Unique Universal Identifier of the entity involved in this action',
    blacklist       varchar(36)                             null comment 'Optional. The blacklist record related to this action',
    evidence        varchar(36)                             null comment 'Optional. The evidence record related to this action',
    file_attachment varchar(36)                             null comment 'Optional. The file attachment related to this action',
    type            varchar(36)                             not null comment 'The audit action type',
    message         text                                    not null comment 'The log message',
    timestamp       timestamp   default current_timestamp() not null comment 'The timestamp for when this log event was created',
    constraint audit_log_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete set null,
    constraint audit_log_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete set null,
    constraint audit_log_blacklist_uuid_fk
        foreign key (blacklist) references blacklist (uuid)
            on update cascade on delete set null,
    constraint audit_log_evidence_uuid_fk
        foreign key (evidence) references evidence (uuid)
            on update cascade on delete set null,
    constraint audit_log_file_attachments_uuid_fk
        foreign key (file_attachment) references file_attachments (uuid)
            on update cascade on delete set null
)
    comment 'The table for housing audit logs';

create index audit_log_entity_timestamp_index
    on audit_log (entity, timestamp desc)
    comment 'Composite index for entity lookups ordered by timestamp';

create index audit_log_operator_timestamp_index
    on audit_log (operator, timestamp desc)
    comment 'Composite index for operator lookups ordered by timestamp';

create index audit_log_type_timestamp_index
    on audit_log (type, timestamp desc)
    comment 'Composite index for type lookups ordered by timestamp';

create index audit_log_timestamp_index
    on audit_log (timestamp desc, uuid desc)
    comment 'Composite index for cleanup queries and global pagination ordered by timestamp';

