<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum EvidenceCategory : string implements CategorizableDatabaseInterface, CaseSensitiveInterface
    {
        case CONFIDENTIAL = 'CONFIDENTIAL';
        case NOT_CONFIDENTIAL = 'NOT_CONFIDENTIAL';
        case CLASSIFIED = 'CLASSIFIED';
        case UNCLASSIFIED = 'UNCLASSIFIED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?EvidenceCategory
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
                self::CONFIDENTIAL => 'confidential = 1',
                self::NOT_CONFIDENTIAL => 'confidential = 0',
                self::CLASSIFIED => 'classification_flag IS NOT NULL',
                self::UNCLASSIFIED => 'classification_flag IS NULL',
            };
        }
    }
