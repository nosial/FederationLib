create table entities
(
    uuid                    varchar(36)   default uuid()               not null comment 'The primary unique index of the entity'
        primary key,
    hash                    varchar(64)                                not null comment 'The Unique Hash combination of the entity (sha256 ''id@domain'', or ''id'' if no domain is set)',
    host                    varchar(255)                               not null comment 'The domain',
    id                      varchar(255)                               null comment 'The Unique Identifier of the entity',
    metadata                varchar(8192) default null                 null comment 'Optional. JSON data of the entity (8kb limit)',
    whitelisted             tinyint(0)    default 0                    not null comment 'Indicates if the entity is whitelisted from auto-reports, default=0',
    reputation              int           default 0                    not null comment 'The reputation score of the entity',
    reputation_last_updated timestamp     default null                 null comment 'The timestamp for when the reputation score was last updated',
    relationship_entity     varchar(36)                                null comment 'Optional. The target entity UUID that this entity has a relationship with',
    relationship_type       enum ('ALTERNATIVE', 'PROXY', 'DEPENDENT') null comment 'Optional. The type of relationship with the target peer',
    created                 timestamp     default current_timestamp()  not null comment 'The Timestamp for when this entity was created',
    updated                 timestamp     default null                 null comment 'The Timestamp for when the entity record was last updted',
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

