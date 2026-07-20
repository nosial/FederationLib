<?php

    namespace FederationLib\Interfaces;

    interface SortableDatabaseInterface
    {
        /**
         * Returns the SQL column name for sorting by this type.
         *
         * @return string The column name
         */
        public function toColumn(): string;
    }
