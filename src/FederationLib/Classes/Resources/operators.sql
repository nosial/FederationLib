create table operators
(
    uuid             varchar(36) default uuid()              not null comment 'The Unique Primary Index for the operator UUID'
        primary key,
    name             varchar(32)                             not null comment 'The public name of the operator',
    access_token     varchar(32)                             not null comment 'The current Access Token of the operator',
    manage_operators tinyint(1)  default 0                   not null comment 'Default: 0, 1=This operator can manage other operators by creating new ones, deleting existing ones or disabling existing ones, etc. 0=No such permissions are allowed',
    manage_blacklist tinyint(1)  default 0                   not null comment 'Default: 0, 1=This operator can manage the blacklist by adding/removing to the database, 0=No such permissions are allowed',
    is_client        tinyint(1)  default 0                   not null comment 'Default: 0, 1=This operator has access to client methods that allows the client to build the database of known entities and automatically report evidence or manage the database (if permitted to do so), 0=No such permissions are allowed',
    disabled         tinyint(1)  default 0                   not null comment 'Default: 0, 1=The operator is disabled, 0=The operator is active',
    created          timestamp   default current_timestamp() not null comment 'The Timestamp for when this operator record was created',
    updated          timestamp   default current_timestamp() not null comment 'The Timestamp for when this operator record was last updated',
    constraint operators_access_token_uindex
        unique (access_token),
    constraint operators_name_uindex
        unique (name)
);

create index operators_created_index
    on operators (created, uuid);

