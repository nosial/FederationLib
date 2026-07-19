<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum BlacklistCategory : string implements CategorizableDatabaseInterface
    {
        case ACTIVE = 'ACTIVE';
        case LIFTED = 'LIFTED';
        case EXPIRED = 'EXPIRED';
        case PERMANENT = 'PERMANENT';

        /**
         * @inheritDoc
         */
        public function toCondition(): string
        {
            return match($this)
            {
                self::ACTIVE => "lifted=0 AND (expires IS NULL OR expires > NOW())",
                self::LIFTED => 'lifted=1',
                self::EXPIRED => "lifted=0 AND expires IS NOT NULL AND expires <= NOW()",
                self::PERMANENT => 'lifted=0 AND expires IS NULL',
            };
        }
    }
