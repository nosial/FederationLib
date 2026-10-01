create table database_metadata
(
    `key`   varchar(64)                            not null comment 'The unique name of the metadata value, e.g. version'
        primary key,
    value   text                                   not null comment 'The metadata value',
    updated timestamp default current_timestamp() not null on update current_timestamp() comment 'The Timestamp for when this value was last updated'
)
    comment 'A key-value table for information about the database itself, such as the schema version';
