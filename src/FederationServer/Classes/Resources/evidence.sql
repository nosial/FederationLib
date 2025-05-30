create table evidence
(
    uuid         varchar(36) default uuid()              not null comment 'The Unique Universal Identifier for the evidence record'
        primary key comment 'The Unique Universal Identifier index for the evidence',
    blacklist    varchar(36)                             null comment 'Optional. If this evidence caused a blacklist, this would be the evidence related to it',
    entity       varchar(36)                             not null comment 'The UUID of the entity that this evidence is related to',
    operator     varchar(36)                             not null comment 'The operator that submitted the evidence',
    text_content text                                    null comment 'Optional. the text content with the evidence',
    note         text                                    null comment 'Optional note by the operator that submitted the evidence',
    created      timestamp   default current_timestamp() not null comment 'The timestamp of the evidence',
    constraint evidence_uuid_uindex
        unique (uuid) comment 'The Unique Universal Identifier index for the evidence',
    constraint evidence_blacklist_uuid_fk
        foreign key (blacklist) references blacklist (uuid)
            on update cascade on delete set null,
    constraint evidence_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint evidence_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete cascade
)
    comment 'Table for housing evidence';

create index evidence_blacklist_index
    on evidence (blacklist)
    comment 'The Blacklist UUID index';

create index evidence_entity_index
    on evidence (entity)
    comment 'The index of the entity UUID';

create index evidence_operator_index
    on evidence (operator)
    comment 'The index of the operator that submitted the evidence';

