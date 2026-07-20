<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum BlacklistOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case TYPE = 'type';
        case EXPIRES = 'expires';
        case CREATED = 'created';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?BlacklistOrderType
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
