<?php

    namespace FederationLib\Classes;

    class Utilities
    {
        /*
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
                $randomString .= $characters[rand(0, $charactersLength - 1)];

            }
            return $randomString;
        }

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
            // Check if the input is a valid UUID (version 4)
            return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $input) === 1;
        }
    }