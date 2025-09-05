create table entities
(
    uuid    varchar(36) default uuid()              not null comment 'The primary unique index of the entity'
        primary key,
    hash    varchar(64)                             not null comment 'The Unique Hash combination of the entity (sha256 ''id@domain'', or ''id'' if no domain is set)',
    host  varchar(255)                            not null comment 'The domain',
    id      varchar(255)                            null comment 'The Unique Identifier of the entity',
    created timestamp   default current_timestamp() not null comment 'The Timestamp for when this entity was created',
    constraint entities_hash_uindex
        unique (hash),
    constraint entities_id_domain_uindex
        unique (id, host),
    constraint entities_uuid_uindex
        unique (uuid)
)
    comment 'A table for housing known entities in the database';

create index entities_domain_index
    on entities (host);

create index entities_id_index
    on entities (id);

