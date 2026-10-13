<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum AuditLogOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case TYPE = 'TYPE';
        case TIMESTAMP = 'TIMESTAMP';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?AuditLogOrderType
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
