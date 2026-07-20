<?php

    namespace FederationLib\Interfaces;

    interface CategorizableDatabaseInterface
    {
        /**
         * Returns the SQL condition snippet for filtering by this category.
         *
         * @return string|array{string, array} The SQL condition string, or an array of [condition, params]
         */
        public function toCondition(): string|array;
    }
