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

        /**
         * Validates if the given host is a valid domain name or IP address
         *
         * @param string $host The host to validate
         * @return bool True if valid, False otherwise
         */
        public static function host(string $host): bool
        {
            // Trim whitespace
            $host = trim($host);

            // Check if it's empty or contains whitespace
            if (empty($host) || preg_match('/\s/', $host))
            {
                return false;
            }

            // Check for valid IPv4
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            {
                return true;
            }

            // Check for valid IPv6
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            {
                return true;
            }

            // Check for valid domain name
            // Domain validation: must not start/end with hyphen, valid characters only
            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false)
            {
                // Additional check to ensure it's a proper domain format
                return preg_match('/^(?!-)(?:[a-zA-Z0-9-]{1,63}(?<!-)\.)*[a-zA-Z0-9-]{1,63}(?<!-)$/', $host) === 1;
            }

            return false;
        }
    }