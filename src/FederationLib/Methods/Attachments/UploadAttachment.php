<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\UploadResult;
    use Symfony\Component\Uid\Uuid;
    use Throwable;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class UploadAttachment extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to upload attachments';
        private const string ERROR_EVIDENCE_NOT_FOUND = 'Evidence not found';
        private const string ERROR_UNABLE_TO_UPLOAD = 'Unable to upload file attachment to server';
        private const int URL_MAX_LENGTH = 2048;
        private const int URL_MAX_REDIRECTS = 5;

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $operator = FederationServer::requireAuthenticatedOperator();
            if(!$operator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            $evidenceUuid = FederationServer::getParameter('evidence_uuid');
            if(empty($evidenceUuid) || !Validate::uuid($evidenceUuid))
            {
                throw new RequestException('A valid evidence UUID is required', 400);
            }

            if($evidenceUuid !== null)
            {
                try
                {
                    if(!EvidenceManager::evidenceExists($evidenceUuid))
                    {
                        throw new RequestException(self::ERROR_EVIDENCE_NOT_FOUND, 404);
                    }
                }
                catch (DatabaseOperationException $e)
                {
                    throw new RequestException('Unable to verify evidence record', 500, $e);
                }
            }

            // The file is either uploaded directly or retrieved by the server from a URL, but never both
            $url = FederationServer::getParameter('url');
            $hasFile = isset($_FILES['file']);
            $hasUrl = $url !== null && $url !== '';
            if($hasFile && $hasUrl)
            {
                throw new RequestException('Provide either a file upload or a URL, not both', 400);
            }

            if(!$hasFile && !$hasUrl)
            {
                throw new RequestException('A file upload or a URL is required', 400);
            }

            if($hasUrl && !is_string($url))
            {
                throw new RequestException('A valid URL is required', 400);
            }

            if($hasUrl && !Configuration::getServerConfiguration()->isUrlUploadsEnabled())
            {
                throw new RequestException('Uploading attachments from a URL is disabled on this server', 400);
            }

            $file = null;
            if($hasFile)
            {
                // Ensure only a single file is uploaded
                $file = $_FILES['file'];
                if (!is_array($file) || empty($file['tmp_name']) || is_array($file['tmp_name']))
                {
                    throw new RequestException('Invalid file upload or multiple files detected', 400);
                }

                // Validate the file size
                if (!isset($file['size']) || $file['size'] <= 0)
                {
                    throw new RequestException('Invalid file size', 400);
                }

                if ($file['size'] > Configuration::getServerConfiguration()->getMaxUploadSize())
                {
                    throw new RequestException(sprintf("File exceeds maximum allowed size (%d bytes)", Configuration::getServerConfiguration()->getMaxUploadSize()), 400);
                }

                // Validate file upload status
                if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK)
                {
                    throw new RequestException(self::getUploadErrorMessage($file['error'] ?? -1), 400);
                }

                // Validate file exists and is readable
                if (!is_file($file['tmp_name']) || !is_readable($file['tmp_name']))
                {
                    throw new RequestException('Uploaded file is not accessible', 400);
                }

                // Check for symlinks/hardlinks in tmp_name
                if (is_link($file['tmp_name']))
                {
                    throw new RequestException('Invalid file upload (symbolic link detected)', 400);
                }

                // Additional check for path traversal attempts
                $realpath = realpath($file['tmp_name']);
                if ($realpath === false || !str_starts_with($realpath, sys_get_temp_dir()))
                {
                    throw new RequestException('Request not allowed', 400);
                }
            }

            // Get file storage path and ensure the directory exists
            $storagePath = rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR);
            if (!is_dir($storagePath))
            {
                if (!mkdir($storagePath, 0750, true))
                {
                    throw new RequestException('Storage directory could not be created', 500);
                }
            }

            // Verify storage directory permissions
            if (!is_writable($storagePath))
            {
                throw new RequestException('Storage directory is not writable', 500);
            }

            $uuid = Uuid::v7()->toRfc4122();
            $destinationPath = $storagePath . DIRECTORY_SEPARATOR . $uuid;
            $tempDestination = $storagePath . DIRECTORY_SEPARATOR . uniqid('tmp_', true);

            if($file !== null)
            {
                if (!move_uploaded_file($file['tmp_name'], $tempDestination))
                {
                    throw new RequestException('Failed to move uploaded file', 500);
                }

                $fileSize = (int)$file['size'];
                $suppliedName = $file['name'] ?? '';
            }
            else
            {
                try
                {
                    $suppliedName = self::downloadFromUrl($url, $tempDestination);
                }
                catch (Throwable $e)
                {
                    @unlink($tempDestination);
                    throw $e;
                }

                clearstatcache(true, $tempDestination);
                $fileSize = (int)filesize($tempDestination);
                if ($fileSize <= 0)
                {
                    @unlink($tempDestination);
                    throw new RequestException('The file retrieved from the URL is empty', 400);
                }
            }

            $originalName = self::getSafeFileName($suppliedName);
            if (empty($originalName) || $originalName === 'unnamed')
            {
                $originalName = Uuid::v7()->toRfc4122();
            }

            try
            {
                // Set restrictive permissions before moving to final destination
                chmod($tempDestination, 0640);
                $detectedMimeType = self::detectMimeType($tempDestination);

                // Move to final destination
                if (!rename($tempDestination, $destinationPath))
                {
                    throw new RequestException('Failed to finalize file upload', 500);
                }

                // Extract the entityUuid if evidenceUuid is provided
                $entityUuid = null;
                if ($evidenceUuid !== null)
                {
                    $entityUuid = EvidenceManager::getEvidence($evidenceUuid)->getEntityUuid();
                }

                // Create a record in the database
                FileAttachmentManager::createRecord($uuid, $evidenceUuid, $detectedMimeType, $originalName, $fileSize);

                // Log upload success
                AuditLogManager::createEntry(AuditLogType::ATTACHMENT_UPLOADED, sprintf('File %s uploaded by operator %s (size: %s bytes) Type %s',
                    $originalName, $operator->getName(), $fileSize, $detectedMimeType
                ), $operator->getUuid(), $entityUuid, null, $evidenceUuid, $uuid);

                self::successResponse(new UploadResult($uuid, Configuration::getServerConfiguration()->getBaseUrl() . '/attachments/' . $uuid), 201);
            }
            catch (DatabaseOperationException $e)
            {
                // If database insertion fails, remove the file to maintain consistency
                @unlink($destinationPath);
                throw new RequestException('Unable to create file attachment record', 500, $e);
            }
            catch (Throwable $e)
            {
                // Handle any other unexpected errors
                @unlink($destinationPath);
                throw new RequestException(self::ERROR_UNABLE_TO_UPLOAD, 500, $e);
            }
            finally
            {
                // Clean up temporary files
                if (file_exists($tempDestination))
                {
                    @unlink($tempDestination);
                }

                if ($file !== null && file_exists($file['tmp_name']))
                {
                    @unlink($file['tmp_name']);
                }
            }
        }

        /**
         * Retrieves the file at the given URL and writes it to the destination path. Every request, including each
         * redirect, is validated and pinned to the resolved address so that the server can't be used to reach
         * hosts on non-public networks unless explicitly permitted by the configuration.
         *
         * @param string $url The HTTP or HTTPS URL to retrieve
         * @param string $destination The path the retrieved file is written to
         * @return string The file name suggested by the response or the URL path, unsanitized
         * @throws RequestException If the URL is invalid, not permitted or the file could not be retrieved
         */
        private static function downloadFromUrl(string $url, string $destination): string
        {
            $maxSize = Configuration::getServerConfiguration()->getMaxUploadSize();
            $deadline = time() + Configuration::getServerConfiguration()->getUrlUploadTimeout();

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
                curl_close($ch);
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

            if (!Configuration::getServerConfiguration()->isUrlUploadPrivateAllowed())
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

        /**
         * Get human-readable error message for PHP upload error codes
         *
         * @param int $errorCode PHP upload error code
         * @return string Human-readable error message
         */
        private static function getUploadErrorMessage(int $errorCode): string
        {
            return match ($errorCode)
            {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
                default => 'Unknown upload error',
            };
        }

        /**
         * Safely detect the MIME type of a file
         *
         * @param string $filePath Path to the file
         * @return string The detected MIME type
         */
        private static function detectMimeType(string $filePath): string
        {
            // Using multiple methods for better reliability

            // First try with Fileinfo extension (most reliable)
            if (function_exists('finfo_open'))
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $filePath);
                if ($mimeType)
                {
                    return $mimeType;
                }
            }

            // Then try with mime_content_type
            if (function_exists('mime_content_type'))
            {
                $mimeType = mime_content_type($filePath);
                if ($mimeType)
                {
                    return $mimeType;
                }
            }

            // Fall back to a simple extension-based check as last resort
            return 'application/octet-stream';
        }

        /**
         * Get a safe filename by removing potentially unsafe characters
         *
         * @param string $filename Original filename
         * @return string Sanitized filename
         */
        private static function getSafeFileName(string $filename): string
        {
            // Remove any path information to avoid directory traversal
            $filename = basename($filename);

            // Remove null bytes and other control characters
            $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);

            // Remove potentially dangerous characters
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

            // Limit length to avoid extremely long filenames
            if (strlen($filename) > 255)
            {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
                $filename = substr($baseFilename, 0, 245) . '.' . $extension;
            }

            return $filename;
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Attachments'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Upload a file attachment';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Uploads a file as an attachment and associates it with an evidence record. The file is either uploaded directly as a multipart/form-data part or retrieved by the server from an HTTP or HTTPS URL, but not both. Client permissions are required.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'uploadAttachment';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [];
        }

        /**
         * @inheritDoc
         */
        public static function getRequestBody(): ?array
        {
            $urlProperties = [
                'url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'maxLength' => self::URL_MAX_LENGTH,
                    'description' => 'An HTTP or HTTPS URL the server retrieves the file from, instead of uploading the file',
                ],
                'evidence_uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                    'description' => 'UUID of the evidence record to associate the attachment with',
                ],
            ];

            return [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'file' => [
                                    'type' => 'string',
                                    'format' => 'binary',
                                    'description' => 'The file to upload, required unless url is provided',
                                ],
                            ] + $urlProperties,
                            'required' => ['evidence_uuid'],
                        ],
                    ],
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => $urlProperties,
                            'required' => ['url', 'evidence_uuid'],
                        ],
                    ],
                    'application/x-www-form-urlencoded' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => $urlProperties,
                            'required' => ['url', 'evidence_uuid'],
                        ],
                    ],
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getResponses(): array
        {
            return [
                '201' => [
                    'description' => 'File uploaded successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => UploadResult::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Invalid request, missing or conflicting file and URL, invalid UUID, a URL that is not permitted or could not be retrieved, or validation failure',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => 'Authentication required',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '403' => [
                    'description' => self::ERROR_INSUFFICIENT_PERMISSIONS,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '404' => [
                    'description' => self::ERROR_EVIDENCE_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_UPLOAD,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }

