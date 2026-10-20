<?php

    namespace FederationLib\Classes\Configuration;

    class ConfigurationValues
    {
        private const array DURATION_UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'y' => 31536000];

        /**
         * Parses a list of uppercase identifiers, keeping only the allowed ones
         *
         * @param mixed $value The configured list, an array or a comma-separated string
         * @param string[] $allowed The allowed identifiers
         * @param string[] $default The list when nothing is configured
         * @return string[] The identifiers, uppercase and without duplicates
         */
        public static function parseList(mixed $value, array $allowed, array $default): array
        {
            if($value === null)
            {
                return $default;
            }

            $values = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
            $result = [];
            foreach($values as $item)
            {
                $item = is_string($item) ? strtoupper(trim($item)) : null;
                if($item !== null && in_array($item, $allowed, true))
                {
                    $result[$item] = $item;
                }
            }

            return array_values($result);
        }

        /**
         * Parses a map of uppercase keys to values
         *
         * @param mixed $value The configured map, an associative array or a comma-separated string of KEY=VALUE pairs
         * @return array<string, mixed> The map with uppercase keys, string values of a string map are trimmed
         */
        public static function parseMap(mixed $value): array
        {
            if(is_array($value))
            {
                return array_change_key_case(array_filter($value, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY), CASE_UPPER);
            }

            if(!is_string($value))
            {
                return [];
            }

            $result = [];
            foreach(explode(',', $value) as $pair)
            {
                if(!str_contains($pair, '='))
                {
                    continue;
                }

                [$key, $item] = explode('=', $pair, 2);
                $result[strtoupper(trim($key))] = trim($item);
            }

            return $result;
        }

        /**
         * Parses a duration in seconds
         *
         * @param mixed $value The configured duration: seconds, a number with a unit (eg; 30d), or off/never/null
         * @param int|null $default The duration when the value cannot be parsed
         * @return int|null The duration in seconds, null for no duration
         */
        public static function parseDuration(mixed $value, ?int $default): ?int
        {
            if($value === null || $value === false)
            {
                return null;
            }

            if(is_int($value) || is_float($value))
            {
                return (int)$value;
            }

            if(!is_string($value))
            {
                return $default;
            }

            $value = strtolower(trim($value));
            if(in_array($value, ['off', 'never', 'null', 'none', 'false'], true))
            {
                return null;
            }

            if(preg_match('/^(\d+)\s*([smhdwy]?)$/', $value, $matches) !== 1)
            {
                return $default;
            }

            return (int)$matches[1] * self::DURATION_UNITS[$matches[2] === '' ? 's' : $matches[2]];
        }

        /**
         * Parses a boolean, accepting the strings of environment variables
         *
         * @param mixed $value The configured value
         * @param bool $default The value when nothing is configured or it cannot be parsed
         * @return bool The boolean
         */
        public static function parseBool(mixed $value, bool $default): bool
        {
            if(is_bool($value))
            {
                return $value;
            }

            if($value === null)
            {
                return $default;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }
    }
