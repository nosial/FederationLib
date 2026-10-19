create table entity_activity
(
    entity           varchar(36)          not null comment 'The UUID of the entity the activity is about',
    day              date                 not null comment 'The day (UTC) of the activity',
    normal_scans     int        default 0 not null comment 'Scans of content the entity authored that warranted no action',
    caution_scans    int        default 0 not null comment 'Scans of content the entity authored that warranted caution',
    blocked_scans    int        default 0 not null comment 'Scans of content the entity authored that reached the block threshold',
    normal_mentions  int        default 0 not null comment 'Scans of content that mentioned the entity and warranted no action',
    flagged_mentions int        default 0 not null comment 'Scans of content that mentioned the entity and warranted caution or more',
    settled          tinyint(1) default 0 not null comment 'Default: 0, 1=The reputation adjustment of the day has been applied',
    primary key (entity, day),
    constraint entity_activity_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade
)
    comment 'The daily activity of entities in scans by authenticated clients, settled into reputation once per day';

create index entity_activity_settled_day_index
    on entity_activity (settled, day);
