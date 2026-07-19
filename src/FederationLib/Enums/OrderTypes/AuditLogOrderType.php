<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum AuditLogOrderType : string implements SortableDatabaseInterface
    {
        case TYPE = 'type';
        case TIMESTAMP = 'timestamp';

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return $this->value;
        }
    }
