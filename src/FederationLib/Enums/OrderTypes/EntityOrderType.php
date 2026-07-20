<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum EntityOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case HOST = 'host';
        case ID = 'id';
        case REPUTATION = 'reputation';
        case CREATED = 'created';
        case UPDATED = 'updated';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?EntityOrderType
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
