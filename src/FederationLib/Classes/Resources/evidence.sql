create table evidence
(
    uuid                varchar(36) default uuid()                 not null comment 'The Unique Universal Identifier index for the evidence'
        primary key,
    entity              varchar(36)                                not null comment 'The UUID of the entity that this evidence is related to',
    operator            varchar(36)                                not null comment 'The operator that submitted the evidence',
    confidential        tinyint(1)  default 0                      not null comment 'Default: 0, 1=The evidence and all of it''s attachments is confidential and only operators can view this, 0=The evidence is available for public view',
    text_content        mediumtext                                 null comment 'Optional. the text content with the evidence',
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
    on evidence (created desc, uuid desc);

create index evidence_entity_created_index
    on evidence (entity asc, created desc);

create index evidence_operator_created_index
    on evidence (operator asc, created desc);

create index evidence_tag_created_index
    on evidence (tag asc, created desc);

