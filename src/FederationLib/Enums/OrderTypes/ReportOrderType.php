<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum ReportOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case INCIDENT_TYPE = 'INCIDENT_TYPE';
        case CREATED = 'CREATED';
        case UPDATED = 'UPDATED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?ReportOrderType
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return strtolower($this->value);
        }
    }
