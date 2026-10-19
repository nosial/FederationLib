create table entity_mention_authors
(
    entity varchar(36) not null comment 'The UUID of the mentioned entity',
    day    date        not null comment 'The day (UTC) of the mention',
    author varchar(36) not null comment 'The UUID of the entity that authored content mentioning the entity in normal content',
    primary key (entity, day, author),
    constraint entity_mention_authors_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint entity_mention_authors_author_uuid_fk
        foreign key (author) references entities (uuid)
            on update cascade on delete cascade
)
    comment 'The distinct authors that mentioned an entity in normal content each day, so that an entity only gains reputation from mentions by several authors';
