<?php

    namespace FederationLib\Classes;

    use FederationLib\Exceptions\RequestException;

    class RemoteRetrieval
    {
        public const int URL_MAX_LENGTH = 2048;
        private const int URL_MAX_REDIRECTS = 5;

        /**
         * Validates the form of a URL for retrieval without resolving it: it must be an absolute HTTP or HTTPS URL
         * of at most 2048 characters with a valid host, and must not carry credentials
         *
         * @param string $url The URL to validate
         * @return array The components of the URL, as returned by parse_url()
         * @throws RequestException If the URL is not valid
         */
        public static function validateUrl(string $url): array
        {
            if (strlen($url) > self::URL_MAX_LENGTH)
            {
                throw new RequestException(sprintf('The URL exceeds the maximum length of %d characters', self::URL_MAX_LENGTH), 400);
            }

            $parts = parse_url($url);
            if ($parts === false || !isset($parts['scheme'], $parts['host']))
            {
                throw new RequestException('A valid URL is required', 400);
            }

            $scheme = strtolower($parts['scheme']);
            if (!in_array($scheme, ['http', 'https']))
            {
                throw new RequestException('Only HTTP and HTTPS URLs are supported', 400);
            }

            if (isset($parts['user']) || isset($parts['pass']))
            {
                throw new RequestException('URLs containing credentials are not supported', 400);
            }

            $host = strtolower(trim($parts['host'], '[]'));
            $isAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
            if (!$isAddress && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
            {
                throw new RequestException('A valid URL is required', 400);
            }

            return $parts;
        }

        /**
         * Retrieves the file at the given URL and writes it to the destination path. Every request, including each
         * redirect, is validated and pinned to the resolved address so that the server can't be used to reach
         * hosts on non-public networks unless explicitly permitted by the configuration.
         *
         * @param string $url The HTTP or HTTPS URL to retrieve
         * @param string $destination The path the retrieved file is written to
         * @param int $maxSize The maximum size of the file in bytes, the retrieval stops once it is exceeded
         * @param int|null $deadline Optional. The UNIX timestamp by which the retrieval must end, so that several
         *                           retrievals can share one timeout; the configured timeout from now when omitted
         * @return string The file name suggested by the response or the URL path, unsanitized
         * @throws RequestException If the URL is invalid, not permitted or the file could not be retrieved
         */
        public static function download(string $url, string $destination, int $maxSize, ?int $deadline=null): string
        {
            $deadline ??= time() + Configuration::getRemoteRetrievalConfiguration()->getTimeout();

            for ($redirects = 0; ; $redirects++)
            {
                $target = self::resolveUrlTarget($url);
                $url = $target['url'];

                $remaining = $deadline - time();
                if ($remaining <= 0)
                {
                    throw new RequestException('Timed out while retrieving the file from the URL', 400);
                }

                $handle = fopen($destination, 'wb');
                if ($handle === false)
                {
                    throw new RequestException('Unable to write the retrieved file to storage', 500);
                }

                $headers = [];
                $written = 0;
                $exceeded = false;

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_CONNECTTIMEOUT => min(10, $remaining),
                    CURLOPT_TIMEOUT => $remaining,
                    CURLOPT_MAXFILESIZE => $maxSize,
                    CURLOPT_USERAGENT => 'FederationLib',
                    CURLOPT_HEADERFUNCTION => function($ch, string $line) use (&$headers): int
                    {
                        if (str_starts_with($line, 'HTTP/'))
                        {
                            $headers = [];
                        }
                        elseif (str_contains($line, ':'))
                        {
                            [$name, $value] = explode(':', $line, 2);
                            $headers[strtolower(trim($name))] = trim($value);
                        }

                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => function($ch, string $data) use ($handle, &$written, &$exceeded, $maxSize): int
                    {
                        $written += strlen($data);
                        if ($written > $maxSize)
                        {
                            $exceeded = true;
                            return 0;
                        }

                        $bytes = fwrite($handle, $data);
                        return $bytes === false ? 0 : $bytes;
                    },
                ]);

                // Pin the connection to the validated address, so the host isn't resolved a second time
                if ($target['address'] !== null)
                {
                    $address = str_contains($target['address'], ':') ? '[' . $target['address'] . ']' : $target['address'];
                    curl_setopt($ch, CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $target['host'], $target['port'], $address)]);
                }

                curl_exec($ch);
                $errorCode = curl_errno($ch);
                $error = curl_error($ch);
                $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                unset($ch);
                fclose($handle);

                if ($exceeded || $errorCode === CURLE_FILESIZE_EXCEEDED)
                {
                    throw new RequestException(sprintf("File exceeds maximum allowed size (%d bytes)", $maxSize), 400);
                }

                if ($errorCode !== 0)
                {
                    throw new RequestException(sprintf('Unable to retrieve the file from the URL: %s', $error), 400);
                }

                if (in_array($status, [301, 302, 303, 307, 308]) && !empty($headers['location']))
                {
                    if ($redirects >= self::URL_MAX_REDIRECTS)
                    {
                        throw new RequestException('The URL redirected too many times', 400);
                    }

                    $url = self::resolveRedirect($url, $headers['location']);
                    continue;
                }

                if ($status < 200 || $status >= 300)
                {
                    throw new RequestException(sprintf('The URL responded with HTTP status %d', $status), 400);
                }

                return self::getUrlFileName($url, $headers['content-disposition'] ?? null);
            }
        }

        /**
         * Validates a URL for retrieval and resolves its host, rejecting URLs that aren't HTTP or HTTPS, carry
         * credentials, or resolve to an address that isn't publicly routable (unless permitted by the configuration)
         *
         * @param string $url The URL to validate
         * @return array{url: string, host: string, port: int, address: string|null} The normalized URL, its host and
         *         port, and the address to connect to (null when the host is an IP address literal)
         * @throws RequestException If the URL is not valid or not permitted
         */
        private static function resolveUrlTarget(string $url): array
        {
            $parts = self::validateUrl($url);
            $scheme = strtolower($parts['scheme']);
            $host = strtolower(trim($parts['host'], '[]'));
            $isAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;

            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            if ($isAddress)
            {
                $addresses = [$host];
            }
            else
            {
                $addresses = gethostbynamel($host) ?: [];
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record)
                {
                    if (isset($record['ipv6']))
                    {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }

            if (empty($addresses))
            {
                throw new RequestException('The host of the URL could not be resolved', 400);
            }

            if (!Configuration::getRemoteRetrievalConfiguration()->isPrivateAllowed())
            {
                foreach ($addresses as $address)
                {
                    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false)
                    {
                        throw new RequestException('The URL resolves to an address that is not publicly routable', 400);
                    }
                }
            }

            // Rebuild the URL from the validated components, so the request can't target a different host than
            // the one that was validated due to URL parsing differences
            $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
            if (isset($parts['port']))
            {
                $authority .= ':' . $parts['port'];
            }

            $normalized = $scheme . '://' . $authority . ($parts['path'] ?? '/');
            if (isset($parts['query']))
            {
                $normalized .= '?' . $parts['query'];
            }

            return [
                'url' => $normalized,
                'host' => $host,
                'port' => $port,
                'address' => $isAddress ? null : $addresses[0],
            ];
        }

        /**
         * Resolves the Location header of a redirect against the URL that was requested
         *
         * @param string $baseUrl The normalized URL that was requested
         * @param string $location The value of the Location header
         * @return string The absolute URL to redirect to
         */
        private static function resolveRedirect(string $baseUrl, string $location): string
        {
            $location = trim($location);
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location))
            {
                return $location;
            }

            $base = parse_url($baseUrl);
            if (str_starts_with($location, '//'))
            {
                return $base['scheme'] . ':' . $location;
            }

            $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
            $path = $base['path'] ?? '/';
            if (str_starts_with($location, '/'))
            {
                return $origin . $location;
            }

            if (str_starts_with($location, '?'))
            {
                return $origin . $path . $location;
            }

            return $origin . preg_replace('#/[^/]*$#', '/', $path) . $location;
        }

        /**
         * Determines the file name of a retrieved file from the Content-Disposition header of the response, or the
         * last segment of the URL path when the header doesn't carry one
         *
         * @param string $url The URL the file was retrieved from
         * @param string|null $contentDisposition The Content-Disposition header of the response, if any
         * @return string The unsanitized file name, possibly empty
         */
        private static function getUrlFileName(string $url, ?string $contentDisposition): string
        {
            if ($contentDisposition !== null)
            {
                if (preg_match("/filename\*\s*=\s*[^']*'[^']*'([^;]+)/i", $contentDisposition, $matches))
                {
                    return rawurldecode(trim($matches[1]));
                }

                if (preg_match('/filename\s*=\s*"([^"]*)"/i', $contentDisposition, $matches) || preg_match('/filename\s*=\s*([^;\s]+)/i', $contentDisposition, $matches))
                {
                    return $matches[1];
                }
            }

            return rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?? ''));
        }
    }
