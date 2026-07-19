<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum EntityCategory : string implements CategorizableDatabaseInterface
    {
        case WHITELISTED = 'WHITELISTED';
        case NOT_WHITELISTED = 'NOT_WHITELISTED';
        case WITH_RELATIONSHIP = 'WITH_RELATIONSHIP';
        case WITHOUT_RELATIONSHIP = 'WITHOUT_RELATIONSHIP';

        /**
         * @inheritDoc
         */
        public function toCondition(): string
        {
            return match($this)
            {
                self::WHITELISTED => 'whitelisted = 1',
                self::NOT_WHITELISTED => 'whitelisted = 0',
                self::WITH_RELATIONSHIP => 'relationship_entity IS NOT NULL',
                self::WITHOUT_RELATIONSHIP => 'relationship_entity IS NULL',
            };
        }
    }
