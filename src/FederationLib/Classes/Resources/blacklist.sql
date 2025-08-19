create table blacklist
(
    uuid      varchar(36) default uuid()                                                                        not null comment 'The Unique Universal Identifier Primary Unique Index'
        primary key,
    operator  varchar(36)                                                                                       not null comment 'The operator that created this blacklist record',
    entity    varchar(36)                                                                                       not null comment 'The target entity that is blacklisted',
    evidence  varchar(36)                                                                                       null comment 'Optional. The evidence for the blacklist',
    type      enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER') not null comment 'The blacklist reason type',
    lifted    tinyint(1)  default 0                                                                             not null comment 'Default: 0, 1=The blacklist was lifted and is no longer in effect, 0=The blacklist is not lifted, it is in effect until it expires',
    lifted_by varchar(36)                                                                                       null comment 'Optional. If the blacklist was manually lifted by an operator, this column represents the operator UUID that made the change.',
    expires   timestamp                                                                                         null comment 'The timestamp for when the blacklist expires, if null the blacklist never expires',
    created   timestamp   default current_timestamp()                                                           not null comment 'The Timestamp for when the record was created',
    constraint blacklist_uuid_uindex
        unique (uuid) comment 'The Unique Universal Identifier Primary Unique Index',
    constraint blacklist_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint blacklist_evidence_uuid_fk
        foreign key (evidence) references evidence (uuid)
            on update cascade on delete cascade,
    constraint blacklist_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete cascade,
    constraint blacklist_operators_uuid_fk_2
        foreign key (lifted_by) references operators (uuid)
            on update cascade on delete set null
)
    comment 'Table for housing one or more blacklist events';

create index blacklist_created_index
    on blacklist (created)
    comment 'The Timestamp creation index';

create index blacklist_entity_index
    on blacklist (entity)
    comment 'The Unique Universal Identifier of the entity index';

create index blacklist_evidence_index
    on blacklist (evidence)
    comment 'The index for the blacklist evidence column';

create index blacklist_operator_index
    on blacklist (operator)
    comment 'The Unique Universal Identifier of the operator index';

create index blacklist_type_index
    on blacklist (type)
    comment 'The blacklist reason type';

