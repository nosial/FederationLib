create table reports
(
    uuid                varchar(36)                                                                                                                 not null comment 'The Unique Universal Identifier of the report record',
    submitting_operator varchar(36)                                                                                                                 not null comment 'The UUID of the operator submitting the report',
    reporting_entity    varchar(36)                                                                                                                 null comment 'Optional. The UUID of the user entity submitting the report',
    assigned_operator   varchar(36)                                                                                                                 null comment 'Optional. The assigned operator that is handling the report',
    automated           tinyint(1)                                                                                        default 0                 not null comment 'Indicates if this report was an automated system report',
    incident_type       enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER') default 'OTHER'           not null comment 'The incident type for the report',
    opened              tinyint(1)                                                                                        default 1                 not null comment 'Indicates if the report is currently opened for review',
    message             text                                                                                                                        null comment 'Optional. The message provided with the report by the entity',
    created             timestamp                                                                                         default current_timestamp not null comment 'The timestamp for when the report was created',
    updated             timestamp                                                                                                                   null comment 'The Timestamp for when the record was last updated',
    constraint reports_entities_uuid_fk
        foreign key (reporting_entity) references entities (uuid)
            on update cascade on delete cascade,
    constraint reports_operators_uuid_fk
        foreign key (submitting_operator) references operators (uuid)
            on update cascade on delete cascade,
    constraint reports_operators_uuid_fk_2
        foreign key (assigned_operator) references operators (uuid)
            on update cascade on delete set null
)
    comment 'Table for housing user submitted reports via clients';

create index reports_assigned_operator_index
    on reports (assigned_operator);

create index reports_created_index
    on reports (created);

create index reports_reporting_entity_index
    on reports (reporting_entity);

create index reports_submitting_operator_index
    on reports (submitting_operator);

create index reports_uuid_index
    on reports (uuid);

alter table reports
    add primary key (uuid);

alter table reports
    add constraint reports_uuid_uindex
        unique (uuid);

