<?php

    namespace FederationLib\Interfaces;

    interface ObjectSpecificationInterface
    {
        /**
         * Get the JSON Schema type of the object.
         *
         * @return string The schema type (e.g., 'object').
         */
        public static function getObjectType(): string;

        /**
         * Get the properties of the object schema.
         *
         * @return array A map of property names to their schema definitions.
         */
        public static function getObjectProperties(): array;

        /**
         * Get the list of required property names for the object schema.
         *
         * @return array An array of required property name strings.
         */
        public static function getObjectRequired(): array;

        /**
         * Returns the object schema reference
         *
         * @return string The object schema reference
         */
        public static function getReference(): string;
    }
