<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum OperatorCategory : string implements CategorizableDatabaseInterface, CaseSensitiveInterface
    {
        case DISABLED = 'DISABLED';
        case ENABLED = 'ENABLED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?OperatorCategory
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * @inheritDoc
         */
        public function toCondition(): string
        {
            return match($this)
            {
                self::DISABLED => 'disabled = 1',
                self::ENABLED => 'disabled = 0',
            };
        }
    }
