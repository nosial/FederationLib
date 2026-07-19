<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum BlacklistOrderType : string implements SortableDatabaseInterface
    {
        case TYPE = 'type';
        case EXPIRES = 'expires';
        case CREATED = 'created';

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return $this->value;
        }
    }
