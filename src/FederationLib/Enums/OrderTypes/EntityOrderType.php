<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum EntityOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case HOST = 'HOST';
        case ID = 'ID';
        case REPUTATION = 'REPUTATION';
        case CREATED = 'CREATED';
        case UPDATED = 'UPDATED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?EntityOrderType
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
