<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum OperatorOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case NAME = 'NAME';
        case CREATED = 'CREATED';
        case UPDATED = 'UPDATED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?OperatorOrderType
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
