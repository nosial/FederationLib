<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum BlacklistCategory : string implements CategorizableDatabaseInterface, CaseSensitiveInterface
    {
        case ACTIVE = 'ACTIVE';
        case LIFTED = 'LIFTED';
        case EXPIRED = 'EXPIRED';
        case PERMANENT = 'PERMANENT';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?BlacklistCategory
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
                self::ACTIVE => "lifted=0 AND (expires IS NULL OR expires > NOW())",
                self::LIFTED => 'lifted=1',
                self::EXPIRED => "lifted=0 AND expires IS NOT NULL AND expires <= NOW()",
                self::PERMANENT => 'lifted=0 AND expires IS NULL',
            };
        }
    }
