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
            // Check if it's empty or contains whitespace, a canonical entity host is never trimmed
            if (empty($host) || preg_match('/\s/', $host))
            {
                return false;
            }

            // An entity host must already be canonical (OFD "Entity Address Canonicalization"):
            // the SHA-256 identifier is derived from the exact string, so Example.COM and
            // example.com, or 2001:DB8:0:0::1 and 2001:db8::1, would otherwise be separate entities.

            // Four dot-separated numbers are an IPv4 address, never a domain: they must be four
            // decimal octets without leading zeros ("010.1.1.1" is rejected, not read as a name)
            if (preg_match('/^[0-9]+(\.[0-9]+){3}$/', $host))
            {
                return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && long2ip(ip2long($host)) === $host;
            }

            // IPv6 must be in its lowercase compressed textual representation
            if (str_contains($host, ':'))
            {
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
                {
                    return false;
                }
                $packed = @inet_pton($host);
                return $packed !== false && inet_ntop($packed) === $host;
            }

            // DNS hosts must use lowercase ASCII labels
            if ($host !== strtolower($host))
            {
                return false;
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

        /**
         * Validates if the given string is a valid domain name.
         *
         * @param string $domain The domain name to validate
         * @return bool True if valid, False otherwise
         */
        public static function domain(string $domain): bool
        {
            // Basic validation - check length and structure
            if (strlen($domain) > 253) return false;

            $parts = explode('.', $domain);
            if (count($parts) < 2) return false;

            foreach ($parts as $part) {
                if (strlen($part) > 63 || strlen($part) < 1) return false;
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/', $part)) return false;
            }

            return true;
        }

        /**
         * Validates if the given string is a valid URL.
         *
         * @param string $url The URL to validate
         * @return bool True if valid, False otherwise
         */
        public static function url(string $url): bool
        {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        /**
         * Validates if the given string is an absolute URL (RFC 3986): a scheme followed by ':' and a hierarchical or
         * opaque part, without whitespace. Any scheme is accepted.
         *
         * @param string $url The URL to validate
         * @return bool True if valid, False otherwise
         */
        public static function absoluteUrl(string $url): bool
        {
            if(preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:[^\s]+$/', $url) !== 1 || preg_match('/[\x00-\x20\x7f]/', $url) === 1)
            {
                return false;
            }

            return parse_url($url) !== false;
        }

        /**
         * Validates if the given string is an absolute HTTP or HTTPS URL with a host
         *
         * @param string $url The URL to validate
         * @return bool True if valid, False otherwise
         */
        public static function httpUrl(string $url): bool
        {
            if(!self::absoluteUrl($url))
            {
                return false;
            }

            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            $host = parse_url($url, PHP_URL_HOST);
            return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== '';
        }

        /**
         * Validates if the given string is a DNS name (RFC 1034) in lowercase A-label form, as carried by the HOSTNAME
         * content type: dot-separated labels of 1 to 63 letters, digits, hyphens or underscores, at most 253 bytes in
         * total. IP address literals are not DNS names.
         *
         * @param string $name The DNS name to validate
         * @return bool True if valid, False otherwise
         */
        public static function dnsName(string $name): bool
        {
            if($name === '' || strlen($name) > 253 || $name !== strtolower($name))
            {
                return false;
            }

            if(filter_var($name, FILTER_VALIDATE_IP) !== false || str_contains($name, ':'))
            {
                return false;
            }

            // Four dot-separated numbers are an IPv4 address in any notation, never a name
            if(preg_match('/^[0-9]+(\.[0-9]+){3}$/', $name) === 1)
            {
                return false;
            }

            foreach(explode('.', $name) as $label)
            {
                if(preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/', $label) !== 1)
                {
                    return false;
                }
            }

            return true;
        }

        /**
         * Validates the local part of a telephone entity (OFD "Telephone Entities"): the E.164 international number
         * as 1 to 15 ASCII digits whose first digit is not 0, without a leading '+' or any separators.
         *
         * @param string $localPart The local part to validate
         * @return bool True if valid, False otherwise
         */
        public static function telephoneNumber(string $localPart): bool
        {
            return preg_match('/^[1-9][0-9]{0,14}$/', $localPart) === 1;
        }

        /**
         * Validates the entity host and optional local part of an entity address beyond their individual forms: a
         * telephone entity must have a canonical E.164 local part, the telephone numbering namespace e164.arpa is
         * not itself a host entity, and no entity host is a subdomain of it, such as the ENUM domain of a number
         * (OFD "Telephone Entities").
         *
         * @param string $host The entity host
         * @param string|null $id The local part, null for a host entity
         * @return bool True if valid, False otherwise
         */
        public static function entityAddress(string $host, ?string $id): bool
        {
            if(str_ends_with($host, '.' . Utilities::TELEPHONE_ENTITY_HOST))
            {
                return false;
            }

            if($host !== Utilities::TELEPHONE_ENTITY_HOST)
            {
                return true;
            }

            return $id !== null && self::telephoneNumber($id);
        }

        /**
         * Validates that an entity identifier that denotes the telephone numbering namespace is a canonical telephone
         * entity (OFD "Telephone Entities"): its entity host is e164.arpa, written in lowercase without a trailing
         * dot, and its local part is the E.164 number as 1 to 15 digits without a leading '+' or separators. An
         * entity host under e164.arpa, such as the ENUM domain of a number, never passes. Any other identifier,
         * including a UUID or SHA-256 identifier, is not a telephone address and passes.
         *
         * @param string $identifier The entity identifier
         * @return bool False if the identifier denotes the telephone namespace without being a canonical telephone entity
         */
        public static function telephoneAddress(string $identifier): bool
        {
            $separator = strrpos($identifier, '@');
            $host = $separator === false ? $identifier : substr($identifier, $separator + 1);
            $normalizedHost = rtrim(strtolower($host), '.');
            if(str_ends_with($normalizedHost, '.' . Utilities::TELEPHONE_ENTITY_HOST))
            {
                return false;
            }

            if($normalizedHost !== Utilities::TELEPHONE_ENTITY_HOST)
            {
                return true;
            }

            return $separator !== false && $host === Utilities::TELEPHONE_ENTITY_HOST && self::telephoneNumber(substr($identifier, 0, $separator));
        }

        /**
         * Validates if the given string is a valid email address.
         *
         * @param string $email The email address to validate
         * @return bool True if valid, False otherwise
         */
        public static function email(string $email): bool
        {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }

        /**
         * Validates if the given string is a valid IPv4 address.
         *
         * @param string $ip The IPv4 address to validate
         * @return bool True if valid, False otherwise
         */
        public static function ipv4(string $ip): bool
        {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        /**
         * Validates if the given string is a valid IPv6 address.
         *
         * @param string $ip The IPv6 address to validate
         * @return bool True if valid, False otherwise
         */
        public static function ipv6(string $ip): bool
        {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        /**
         * Validates the Entity metadata input, returns False if the following conditions are met
         *
         *  - The key value length is greater than 64 characters
         *  - The value type is not one of: string, integer, boolean or null
         *  - The value type exceeds 1000 chracters if it's a string
         *  - The value type is empty if it's a string instead of null
         *  - The serialized JSON encoding results in a size larger than 8kb
         *
         * @param array $input
         * @return bool
         */
        public static function metadata(array $input): bool
        {

            foreach($input as $key => $value)
            {
                // key cannot be empty or greater than 64 characters
                if(strlen($key) < 1 || strlen($key) > 64)
                {
                    return false;
                }

                // null is allowed
                if($value === null)
                {
                    continue;
                }

                switch(gettype($value))
                {
                    // string value cannot be empty or greater than 64 characters
                    case 'string':
                        if(strlen($value) > 1000 || $value === '')
                        {
                            return false;
                        }
                        break;

                    // integer, double and boolean is allowed
                    case 'integer':
                    case 'boolean':
                    case 'double':
                        break;

                        // disallow anything else
                    default:
                        return false;
                }
            }

            // disallow json encoding larger than 8kb
            if(strlen(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 8000)
            {
                return false;
            }

            return true;
        }
    }