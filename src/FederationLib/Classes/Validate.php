<?php

    namespace FederationLib\Classes;

    class Validate
    {
        /**
         * Validates if the given UUID is valid
         *
         * @param string $uuid The UUID to check for validation
         * @return bool True if valid, False otherwise
         */
        public static function uuid(string $uuid): bool
        {
            // Validate UUID format using regex - accepts all valid UUID versions
            return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid) === 1;
        }

        /**
         * Validates if the given evidence tag is valid, a valid tag is 1-32 characters long and contains only
         * alphanumeric characters, underscores, or hyphens
         *
         * @param string $evidenceTag The evidence tag to validate
         * @return bool True if valid, False otherwise
         */
        public static function evidenceTag(string $evidenceTag): bool
        {
            return preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $evidenceTag) === 1;
        }
    }