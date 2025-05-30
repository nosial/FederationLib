create table audit_log
(
    uuid      varchar(36) default uuid()              not null comment 'The Unique Universal Identifier of the log record'
        primary key comment 'The Unique Universal Identifier primary unique index',
    operator  varchar(36)                             null comment 'Optional. The operator involved in the action',
    entity    varchar(36)                             null comment 'The Unique Universal Identifier of the entity involved in this action',
    type      varchar(36)                             not null comment 'The audit action type',
    message   text                                    not null comment 'The log message',
    timestamp timestamp   default current_timestamp() not null comment 'The timestamp for when this log event was created',
    constraint audit_log_uuid_uindex
        unique (uuid) comment 'The Unique Universal Identifier primary unique index',
    constraint audit_log_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete set null,
    constraint audit_log_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete set null
)
    comment 'The table for housing audit logs';

create index audit_log_entity_index
    on audit_log (entity)
    comment 'The Index of the entity uuid';

create index audit_log_opreator_index
    on audit_log (operator);

create index audit_log_timestamp_index
    on audit_log (timestamp)
    comment 'The index of the log timestamp';

create index audit_log_type_index
    on audit_log (type)
    comment 'The index of the log type';

