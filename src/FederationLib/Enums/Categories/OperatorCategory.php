<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum OperatorCategory : string implements CategorizableDatabaseInterface
    {
        case DISABLED = 'DISABLED';
        case ENABLED = 'ENABLED';

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
