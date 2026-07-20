<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum EvidenceOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case TAG = 'tag';
        case CLASSIFICATION_FLAG = 'classification_flag';
        case CREATED = 'created';
        case UPDATED = 'updated';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?EvidenceOrderType
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
