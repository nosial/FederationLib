create table operators
(
    uuid                   varchar(36)                            not null comment 'The Unique Primary Index for the operator UUID'
        primary key,
    name                   varchar(32)                            not null comment 'The public name of the operator',
    access_token           varchar(64)                            not null comment 'The securely stored representation of the operator Access Token. Real tokens are never stored in plaintext, only their SHA-256 hash, so the raw token can only be returned when it is generated or refreshed. The special literal "none" is used solely as the non-usable sentinel Access Token of the built-in system operator',
    client_permissions     tinyint(1) default 0                   not null comment 'Default: 0, 1=This operator has basic client permissions enabling evidence submission, entity pushing, attachment uploads, lookups, report submission, and content scanning. 0=No such permissions are allowed',
    management_permissions tinyint(1) default 0                   not null comment 'Default: 0, 1=This operator has management permissions, inheriting all client permissions plus record deletion, confidential evidence access, and blacklist management. 0=No such permissions are allowed',
    operator_permissions   tinyint(1) default 0                   not null comment 'Default: 0, 1=This operator has operator management permissions, allowing creation, deletion, and management of other operators. 0=No such permissions are allowed',
    auto_assign            tinyint(0) default 0                   not null comment 'The operator can get assigned reports automatically if True',
    disabled               tinyint(1) default 0                   not null comment 'Default: 0, 1=The operator is disabled, 0=The operator is active',
    created                timestamp  default current_timestamp() not null comment 'The Timestamp for when this operator record was created',
    updated                timestamp  default current_timestamp() not null comment 'The Timestamp for when this operator record was last updated',
    constraint operators_access_token_uindex
        unique (access_token),
    constraint operators_name_uindex
        unique (name)
);

create index operators_created_index
    on operators (created, uuid);

