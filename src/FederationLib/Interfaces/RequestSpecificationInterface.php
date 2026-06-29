<?php

    namespace FederationLib\Interfaces;

    interface RequestSpecificationInterface
    {
        /**
         * Get the tags for the operation.
         *
         * @return array List of tag strings for API documentation grouping.
         */
        public static function getTags(): array;

        /**
         * Get a short summary of what the operation does.
         *
         * @return string The operation summary.
         */
        public static function getSummary(): string;

        /**
         * Get a verbose explanation of the operation behavior.
         *
         * @return string The operation description.
         */
        public static function getDescription(): string;

        /**
         * Get a unique string used to identify the operation.
         *
         * @return string The operation ID.
         */
        public static function getOperationId(): string;

        /**
         * Get the list of parameters that are applicable for this operation.
         *
         * @return array An array of Parameter Objects.
         */
        public static function getParameters(): array;

        /**
         * Get the request body applicable for this operation, or null if the operation has no request body.
         *
         * @return array|null The Request Body Object, or null if not applicable.
         */
        public static function getRequestBody(): ?array;

        /**
         * Get the list of possible responses as they are returned from executing this operation.
         *
         * @return array The Responses Object.
         */
        public static function getResponses(): array;
    }
