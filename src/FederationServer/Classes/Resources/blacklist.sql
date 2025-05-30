create table blacklist
(
    uuid     varchar(36) default uuid()                                                                        not null comment 'The Unique Universal Identifier of the blacklist record'
        primary key comment 'The Unique Universal Identifier Primary Unique Index',
    operator varchar(36)                                                                                       not null comment 'The operator that created this blacklist record',
    entity   varchar(36)                                                                                       not null comment 'The target entity that is blacklisted',
    type     enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER') not null comment 'The blacklist reason type',
    expires  timestamp                                                                                         null comment 'The timestamp for when the blacklist expires, if null the blacklist never expires',
    created  timestamp   default current_timestamp()                                                           not null comment 'The Timestamp for when the record was created',
    constraint blacklist_uuid_uindex
        unique (uuid) comment 'The Unique Universal Identifier Primary Unique Index',
    constraint blacklist_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint blacklist_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete cascade
)
    comment 'Table for housing one or more blacklist events';

create index blacklist_created_index
    on blacklist (created)
    comment 'The Timestamp creation index';

create index blacklist_entity_index
    on blacklist (entity)
    comment 'The Unique Universal Identifier of the entity index';

create index blacklist_operator_index
    on blacklist (operator)
    comment 'The Unique Universal Identifier of the operator index';

create index blacklist_type_index
    on blacklist (type)
    comment 'The blacklist reason type';

