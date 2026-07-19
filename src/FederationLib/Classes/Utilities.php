<?php

    namespace FederationLib\Classes;

    use Random\RandomException;
    use RuntimeException;

    class Utilities
    {
        /**
         * Generate a random string of specified length.
         *
         * @param int $length Length of the random string to generate.
         * @return string Randomly generated string.
         */
        public static function generateString(int $length=32): string
        {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';

            for ($i = 0; $i < $length; $i++)
            {
                // Use a cryptographically secure random source; access tokens are generated with this method.
                try
                {
                    $randomString .= $characters[random_int(0, $charactersLength - 1)];
                }
                catch (RandomException $e)
                {
                    throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
                }

            }
            return $randomString;
        }

        /**
         * Check if the input is a valid SHA-256 hash.
         *
         * @param string $input The input string to check.
         * @return bool True if the input is a valid SHA-256 hash, false otherwise.
         */
        public static function isSha256(string $input): bool
        {
            // Check if the input is a valid SHA-256 hash
            return preg_match('/^[a-f0-9]{64}$/i', $input) === 1;
        }

        /**
         * Check if the input is a valid UUID (version 4).
         *
         * @param string $input The input string to check.
         * @return bool True if the input is a valid UUID, false otherwise.
         */
        public static function isUuid(string $input): bool
        {
            return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[47][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $input) === 1;
        }

        /**
         * Check if the input is a valid entity address (email format).
         *
         * An entity address is an email that can be resolved to an entity
         * using Utilities::hashEntity().
         *
         * @param string $input The input string to check.
         * @return bool True if the input is a valid entity address, false otherwise.
         */
        public static function isEntityAddress(string $input): bool
        {
            return self::parseEntityAddress($input) !== null;
        }

        /**
         * Calculates the SHA256 hash of an entity with the given domain and optional ID
         *
         * @param string $host The host/domain of the entity
         * @param string|null $id Optional. The ID of the entity if they belong to a specific domain
         * @return string The SHA256 calculated checksum of the
         */
        public static function hashEntity(string $host, ?string $id=null): string
        {
            if($id !== null)
            {
                return hash('sha256', sprintf("%s@%s", $id, $host));
            }

            return hash('sha256', $host);
        }

        /**
         * Parse an entity address into its components
         *
         * @param string $address The entity address to parse
         * @return array|null Array with 'host' and 'id' keys, or null if invalid address
         */
        public static function parseEntityAddress(string $address): ?array
        {
            $pattern = '/^(?<id>[a-zA-Z0-9._%+-]+)@(?<host>[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$/';

            if (preg_match($pattern, $address, $matches))
            {
                return [
                    'host' => $matches['host'],
                    'id' => $matches['id']
                ];
            }

            return null;
        }

    }