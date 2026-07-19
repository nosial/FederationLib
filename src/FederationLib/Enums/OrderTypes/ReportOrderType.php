<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum ReportOrderType : string implements SortableDatabaseInterface
    {
        case INCIDENT_TYPE = 'incident_type';
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
