<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum OrderType : string implements CaseSensitiveInterface
    {
        case ASCENDING = 'ASC';
        case DESCENDING = 'DESC';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?OrderType
        {
            return self::tryFrom(strtoupper($value));
        }
    }
