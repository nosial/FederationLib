<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum ReportOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case INCIDENT_TYPE = 'incident_type';
        case CREATED = 'created';
        case UPDATED = 'updated';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?ReportOrderType
        {
            return self::tryFrom(strtolower($value));
        }

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return $this->value;
        }
    }
