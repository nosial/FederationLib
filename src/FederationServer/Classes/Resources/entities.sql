create table entities
(
    uuid    varchar(36) default uuid()              not null comment 'The Unique Universal Identifier of the entity'
        primary key comment 'The primary unique index of the entity',
    id      varchar(255)                            not null comment 'The Unique Identifier of the entity',
    domain  varchar(255)                            null comment 'The domain',
    created timestamp   default current_timestamp() not null comment 'The Timestamp for when this entity was created',
    constraint entities_id_domain_uindex
        unique (id, domain) comment 'The Unique Index of the Entity ID and Domain combination',
    constraint entities_uuid_uindex
        unique (uuid) comment 'The primary unique index of the entity'
)
    comment 'A table for housing known entities in the database';

create index entities_domain_index
    on entities (domain)
    comment 'The domain name of the entity';

create index entities_id_index
    on entities (id)
    comment 'The index of the entity ID';

