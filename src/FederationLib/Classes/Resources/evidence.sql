create table evidence
(
    uuid         varchar(36) default uuid()              not null comment 'The Unique Universal Identifier index for the evidence'
        primary key,
    entity       varchar(36)                             not null comment 'The UUID of the entity that this evidence is related to',
    operator     varchar(36)                             not null comment 'The operator that submitted the evidence',
    confidential tinyint(1)  default 0                   not null comment 'Default: 0, 1=The evidence and all of it''s attachments is confidential and only operators can view this, 0=The evidence is available for public view',
    text_content text                                    null comment 'Optional. the text content with the evidence',
    tag          varchar(32)                             null comment 'Optional. Abstract tag name related to the evidence',
    note         text                                    null comment 'Optional note by the operator that submitted the evidence',
    created      timestamp   default current_timestamp() not null comment 'The timestamp of the evidence',
    constraint evidence_uuid_uindex
        unique (uuid) comment 'The Unique Universal Identifier index for the evidence',
    constraint evidence_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint evidence_operators_uuid_fk
        foreign key (operator) references operators (uuid)
            on update cascade on delete cascade
)
    comment 'Table for housing evidence';

create index evidence_entity_index
    on evidence (entity)
    comment 'The index of the entity UUID';

create index evidence_operator_index
    on evidence (operator)
    comment 'The index of the operator that submitted the evidence';

create index evidence_tag_index
    on evidence (tag)
    comment 'The index for evidence tags for quickly group evidence records together';

