<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum EvidenceOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case TAG = 'TAG';
        case CLASSIFICATION_FLAG = 'CLASSIFICATION_FLAG';
        case CREATED = 'CREATED';
        case UPDATED = 'UPDATED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?EvidenceOrderType
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
