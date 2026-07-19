<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum EvidenceOrderType : string implements SortableDatabaseInterface
    {
        case TAG = 'tag';
        case CLASSIFICATION_FLAG = 'classification_flag';
        case CREATED = 'created';
        case UPDATED = 'updated';

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return $this->value;
        }
    }
