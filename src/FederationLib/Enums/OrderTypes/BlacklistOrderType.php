<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum BlacklistOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case TYPE = 'TYPE';
        case EXPIRES = 'EXPIRES';
        case CREATED = 'CREATED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?BlacklistOrderType
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
