<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum AuditLogOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case TYPE = 'type';
        case TIMESTAMP = 'timestamp';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?AuditLogOrderType
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
