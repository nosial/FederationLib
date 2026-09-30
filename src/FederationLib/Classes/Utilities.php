<?php

    namespace FederationLib\Classes;

    use Random\RandomException;
    use RuntimeException;

    class Utilities
    {
        /**
         * Route pattern fragment for an OFD entity identifier path segment: a UUID, a SHA-256 identifier or an entity
         * address (named or host alone, with a DNS, IPv4 or IPv6 entity host). It never matches a literal sub-path of
         * the entities domain, whatever the request method, as OFD "Route Patterns" requires. The segment is only
         * shape-matched here; EntitiesManager::getEntityByIdentifier() validates it.
         */
        public const string ENTITY_IDENTIFIER_SEGMENT = '(?!(?:search|top-threats)(?:/|$))([a-zA-Z0-9._%+:@-]+)';

        /**
         * Matches a path of the form /entities/{identifier}{suffix} and returns the identifier segment.
         *
         * @param string $path The request path
         * @param string $suffix Optional. The literal path that follows the identifier, e.g. '/evidence'
         * @return string|null The identifier segment, or null if the path does not match
         */
        public static function matchEntityPath(string $path, string $suffix=''): ?string
        {
            if(preg_match('#^/entities/' . self::ENTITY_IDENTIFIER_SEGMENT . preg_quote($suffix, '#') . '$#', $path, $matches) === 1)
            {
                return $matches[1];
            }

            return null;
        }

        /**
         * Returns the OpenAPI schema of an entity or evidence metadata object, as defined by OFD "Entity Metadata": a
         * flat JSON object whose keys are 1 to 64 bytes and whose values are scalars or null, with string values of 1
         * to 1000 bytes. JSON Schema measures lengths in characters, so the byte limits are only approximated.
         *
         * @param string $description The description of the metadata member
         * @param bool $nullable Whether the metadata member itself may be null
         * @return array The metadata schema
         */
        public static function getMetadataSchema(string $description, bool $nullable=true): array
        {
            return [
                'type' => $nullable ? ['object', 'null'] : 'object',
                'propertyNames' => ['minLength' => 1, 'maxLength' => 64],
                'additionalProperties' => [
                    'type' => ['string', 'integer', 'number', 'boolean', 'null'],
                    'minLength' => 1,
                    'maxLength' => 1000,
                ],
                'description' => $description,
            ];
        }

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
         * Canonicalizes an entity host by removing a leading "www." label from DNS hosts, so that www.example.com
         * and example.com resolve to the same entity. Other subdomains are left untouched, and the label is only
         * removed when the remainder is still a registrable domain, so hosts such as www.com or www.co.uk (where
         * "www" is itself the registered label) are left untouched.
         *
         * @param string $host The host/domain of the entity
         * @return string The canonical entity host
         */
        public static function canonicalizeHost(string $host): string
        {
            if(str_starts_with($host, 'www.') && self::getRegistrableDomain(substr($host, 4)) !== null)
            {
                return substr($host, 4);
            }

            return $host;
        }

        /**
         * Returns the registrable domain (the public suffix plus one label) of a DNS host using the Public Suffix
         * List, e.g. sub.example.com and example.com both return example.com, and a.b.example.co.uk returns
         * example.co.uk.
         *
         * @param string $host The DNS host
         * @return string|null The registrable domain, or null if the host is an IP address, is itself a public
         *                     suffix, or the Public Suffix List is unavailable
         */
        public static function getRegistrableDomain(string $host): ?string
        {
            if(filter_var($host, FILTER_VALIDATE_IP) !== false)
            {
                return null;
            }

            $labels = explode('.', strtolower($host));
            $labelCount = count($labels);
            $rules = self::getPublicSuffixRules();
            if($labelCount < 2 || count($rules) === 0)
            {
                return null;
            }

            // Walk from the longest candidate suffix to the shortest; exception rules win over every other rule,
            // otherwise the longest matching rule prevails, and the implicit "*" rule applies when none match
            $suffixLength = null;
            for($i = 0; $i < $labelCount && $suffixLength === null; $i++)
            {
                if(isset($rules['!' . implode('.', array_slice($labels, $i))]))
                {
                    $suffixLength = $labelCount - $i - 1;
                }
            }

            for($i = 0; $i < $labelCount && $suffixLength === null; $i++)
            {
                $candidate = implode('.', array_slice($labels, $i));
                $wildcard = $i + 1 < $labelCount ? '*.' . implode('.', array_slice($labels, $i + 1)) : null;

                if(isset($rules[$candidate]) || ($wildcard !== null && isset($rules[$wildcard])))
                {
                    $suffixLength = $labelCount - $i;
                }
            }

            $suffixLength ??= 1;

            if($labelCount <= $suffixLength)
            {
                return null;
            }

            return implode('.', array_slice($labels, $labelCount - $suffixLength - 1));
        }

        /**
         * Loads the Public Suffix List rules bundled in the Resources directory, keyed by rule (including any
         * leading "*." wildcard or "!" exception marker). Internationalized rules are converted to their ASCII
         * (punycode) form so they match canonical entity hosts.
         *
         * @return array<string, true> The rules, or an empty array if the list could not be loaded
         */
        private static function getPublicSuffixRules(): array
        {
            static $rules = null;
            if($rules !== null)
            {
                return $rules;
            }

            $rules = [];
            $lines = @file(__DIR__ . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'public_suffix_list.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if($lines === false)
            {
                Logger::log()->warning('Unable to load the Public Suffix List, subdomain resolution is disabled');
                return $rules;
            }

            foreach($lines as $line)
            {
                $rule = trim($line);
                if($rule === '' || str_starts_with($rule, '//'))
                {
                    continue;
                }

                if(preg_match('/[^\x20-\x7e]/', $rule) === 1)
                {
                    if(!function_exists('idn_to_ascii'))
                    {
                        continue;
                    }

                    $prefix = str_starts_with($rule, '!') ? '!' : (str_starts_with($rule, '*.') ? '*.' : '');
                    $rule = idn_to_ascii(substr($rule, strlen($prefix)), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                    if($rule === false)
                    {
                        continue;
                    }

                    $rule = $prefix . $rule;
                }

                $rules[strtolower($rule)] = true;
            }

            return $rules;
        }

        /**
         * Calculates the SHA256 hash of an entity with the given domain and optional ID
         *
         * @param string $host The host/domain of the entity, canonicalized with canonicalizeHost() before hashing
         * @param string|null $id Optional. The ID of the entity if they belong to a specific domain
         * @return string The SHA256 calculated checksum of the
         */
        public static function hashEntity(string $host, ?string $id=null): string
        {
            $host = self::canonicalizeHost($host);

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