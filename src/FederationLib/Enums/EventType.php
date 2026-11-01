<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\AuditLogEventHandlerInterface;
    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Interfaces\MaintenanceEventHandlerInterface;
    use FederationLib\Interfaces\QueryEntityEventHandlerInterface;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Interfaces\RemoteRetrievalEventHandlerInterface;
    use FederationLib\Interfaces\StartupEventHandlerInterface;

    enum EventType : string implements CaseSensitiveInterface
    {
        case AUDIT_LOG = 'AUDIT_LOG';
        case CONTENT_SCAN = 'CONTENT_SCAN';
        case QUERY_ENTITY = 'QUERY_ENTITY';
        case RECORD_CHANGE = 'RECORD_CHANGE';
        case STARTUP = 'STARTUP';
        case MAINTENANCE = 'MAINTENANCE';
        case REMOTE_RETRIEVAL = 'REMOTE_RETRIEVAL';

        /**
         * Returns the interface an event handler class must implement to handle the event
         *
         * @return string The fully qualified interface name
         */
        public function getHandlerInterface(): string
        {
            return match($this)
            {
                self::AUDIT_LOG => AuditLogEventHandlerInterface::class,
                self::CONTENT_SCAN => ContentScanEventHandlerInterface::class,
                self::QUERY_ENTITY => QueryEntityEventHandlerInterface::class,
                self::RECORD_CHANGE => RecordChangeEventHandlerInterface::class,
                self::STARTUP => StartupEventHandlerInterface::class,
                self::MAINTENANCE => MaintenanceEventHandlerInterface::class,
                self::REMOTE_RETRIEVAL => RemoteRetrievalEventHandlerInterface::class,
            };
        }

        /**
         * Returns the values an event handler's filter may contain, the event handler is only executed for events
         * that match one of the filter's values
         *
         * @return string[] The valid filter values, empty if the event does not support filters
         */
        public function getFilterValues(): array
        {
            return match($this)
            {
                self::AUDIT_LOG => array_map(fn(AuditLogType $type) => $type->value, AuditLogType::cases()),
                self::CONTENT_SCAN, self::QUERY_ENTITY, self::STARTUP, self::MAINTENANCE => [],
                self::RECORD_CHANGE => array_map(fn(RecordChangeType $type) => $type->value, RecordChangeType::cases()),
                self::REMOTE_RETRIEVAL => array_map(fn(RetrievalPurpose $purpose) => $purpose->value, RetrievalPurpose::cases()),
            };
        }

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?EventType
        {
            return self::tryFrom(strtoupper(trim($value)));
        }
    }
