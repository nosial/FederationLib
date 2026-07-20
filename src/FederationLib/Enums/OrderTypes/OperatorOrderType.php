<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum OperatorOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case NAME = 'name';
        case CREATED = 'created';
        case UPDATED = 'updated';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?OperatorOrderType
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
