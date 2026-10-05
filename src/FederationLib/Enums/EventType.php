<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\AuditLogEventHandlerInterface;
    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Interfaces\QueryEntityEventHandlerInterface;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;

    enum EventType : string implements CaseSensitiveInterface
    {
        /**
         * Produced whenever an audit log entry is created, the handler receives the AuditLog entry
         */
        case AUDIT_LOG = 'AUDIT_LOG';

        /**
         * Produced during a content scan request, the handler receives the ContentScan and may add its own scanning
         * rules to the scan results or reject the request
         */
        case CONTENT_SCAN = 'CONTENT_SCAN';

        /**
         * Produced during a query entity request, the handler receives the EntityQuery and may change the response
         * or reject the request
         */
        case QUERY_ENTITY = 'QUERY_ENTITY';

        /**
         * Produced whenever a change is written to the database (eg; a report is created or closed, an evidence
         * record is classified or an entity is deleted), the handler receives the RecordChange
         */
        case RECORD_CHANGE = 'RECORD_CHANGE';

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
                self::CONTENT_SCAN, self::QUERY_ENTITY => [],
                self::RECORD_CHANGE => array_map(fn(RecordChangeType $type) => $type->value, RecordChangeType::cases()),
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
