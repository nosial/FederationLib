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
         * Get file extension from MIME type
         *
         * @param string $mimeType The MIME type
         * @return string The file extension (with dot) or empty string if unknown
         */
        public static function extensionFromMime(string $mimeType): string
        {
            $mimeToExtension = [
                // Images
                'image/jpeg' => '.jpg',
                'image/jpg' => '.jpg',
                'image/png' => '.png',
                'image/gif' => '.gif',
                'image/bmp' => '.bmp',
                'image/webp' => '.webp',
                'image/svg+xml' => '.svg',
                'image/tiff' => '.tiff',

                // Documents
                'application/pdf' => '.pdf',
                'application/msword' => '.doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
                'application/vnd.ms-excel' => '.xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
                'application/vnd.ms-powerpoint' => '.ppt',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx',
                'text/plain' => '.txt',
                'text/csv' => '.csv',
                'application/rtf' => '.rtf',

                // Archives
                'application/zip' => '.zip',
                'application/x-rar-compressed' => '.rar',
                'application/x-7z-compressed' => '.7z',
                'application/x-tar' => '.tar',
                'application/gzip' => '.gz',

                // Audio
                'audio/mpeg' => '.mp3',
                'audio/wav' => '.wav',
                'audio/ogg' => '.ogg',
                'audio/flac' => '.flac',

                // Video
                'video/mp4' => '.mp4',
                'video/avi' => '.avi',
                'video/quicktime' => '.mov',
                'video/x-msvideo' => '.avi',
                'video/webm' => '.webm',

                // Other
                'application/json' => '.json',
                'application/xml' => '.xml',
                'text/html' => '.html',
                'text/css' => '.css',
                'application/javascript' => '.js',
                'application/octet-stream' => '.bin'
            ];

            return $mimeToExtension[$mimeType] ?? '';
        }

        /**
         * Parse an email address into its components
         *
         * @param string $email The email address to parse
         * @return array|null Array with 'domain' and 'username' keys, or null if invalid email
         */
        public static function parseEmail(string $email): ?array
        {
            $pattern = '/^(?<username>[a-zA-Z0-9._%+-]+)@(?<domain>[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$/';

            if (preg_match($pattern, $email, $matches))
            {
                return [
                    'domain' => $matches['domain'],
                    'username' => $matches['username']
                ];
            }

            return null;
        }

        /**
         * Extract the domain from a given URL.
         *
         * This function attempts to robustly extract the domain from various URL formats,
         * including those with or without protocols, ports, paths, query parameters, and fragments.
         * It also handles some malformed URLs by making reasonable assumptions.
         *
         * @param string $url The input URL from which to extract the domain.
         * @return string|null The extracted domain in lowercase, or null if no valid domain can be determined.
         * @noinspection HttpUrlsUsage (for handling http and https)
         */
        public static function extractDomainFromUrl(string $url): ?string
        {
            // Trim whitespace and remove common protocol prefixes to handle malformed input
            $url = trim($url);
            if (!preg_match('/^https?:\/\//i', $url))
            {
                $url = 'http://' . $url;
            }

            $parsedUrl = parse_url($url);
            if ($parsedUrl === false)
            {
                return null;
            }

            // Attempt to get the 'host' component.
            $host = $parsedUrl['host'] ?? null;
            if ($host !== null)
            {
                return strtolower($host);
            }

            // If the 'host' is not found, check if the first path component could be the domain.
            // This handles cases like "example.com/path".
            $path = $parsedUrl['path'] ?? null;
            if ($path !== null)
            {
                $pathParts = explode('/', $path);
                $potentialHost = $pathParts[0];

                // A basic check to see if the path part looks like a hostname.
                if (str_contains($potentialHost, '.'))
                {
                    // Ensure it's not a path fragment by validating.
                    // An IPv4 or IPv6 address is also a valid host.
                    if (filter_var($potentialHost, FILTER_VALIDATE_IP) || filter_var($potentialHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
                    {
                        return strtolower($potentialHost);
                    }
                }
            }

            // If no host can be determined, return null.
            return null;
        }
    }