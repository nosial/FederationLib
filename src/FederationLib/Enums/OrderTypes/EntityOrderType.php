<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum EntityOrderType : string implements SortableDatabaseInterface
    {
        case HOST = 'host';
        case ID = 'id';
        case REPUTATION = 'reputation';
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
