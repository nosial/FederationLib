create table file_attachments
(
    uuid      varchar(36) default uuid()              not null comment 'The Unique Universal Identifier Unique Index'
        primary key,
    evidence  varchar(36)                             not null comment 'The Unique Universal Identifier of the evidence that this file is attached to',
    file_name varchar(255)                            not null comment 'The name of the file',
    file_mime varchar(32)                             null comment 'Optional. The MIME type of the file attachment',
    file_size bigint                                  not null comment 'The size of the file',
    created   timestamp   default current_timestamp() not null comment 'The Timestamp for when this file attachment was created',
    constraint file_attachments_uuid_uindex
        unique (uuid) comment 'The Unique Universal Identifier Unique Index',
    constraint file_attachments_evidence_uuid_fk
        foreign key (evidence) references evidence (uuid)
            on update cascade on delete cascade
)
    comment 'A table for housing file attachments related to evidence records';

create index file_attachments_evidence_index
    on file_attachments (evidence)
    comment 'The file attachment index';

