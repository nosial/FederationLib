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

            // Verify the file upload field exists
            if(!isset($_FILES['file']))
            {
                throw new RequestException('File upload is required', 400);
            }

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

            $detectedMimeType = self::detectMimeType($file['tmp_name']);
            $originalName = self::getSafeFileName($file['name'] ?? '');
            if (empty($originalName) || $originalName === 'unnamed')
            {
                $originalName = Uuid::v7()->toRfc4122();
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

            if (!move_uploaded_file($file['tmp_name'], $tempDestination))
            {
                throw new RequestException('Failed to move uploaded file', 500);
            }

            try
            {
                // Set restrictive permissions before moving to final destination
                chmod($tempDestination, 0640);

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
                FileAttachmentManager::createRecord($uuid, $evidenceUuid, $detectedMimeType, $originalName, $file['size']);

                // Log upload success
                AuditLogManager::createEntry(AuditLogType::ATTACHMENT_UPLOADED, sprintf('File %s uploaded by operator %s (size: %s bytes) Type %s',
                    $originalName, $operator->getName(), $file['size'], $detectedMimeType
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

                if (file_exists($file['tmp_name']))
                {
                    @unlink($file['tmp_name']);
                }
            }
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
            return 'Uploads a file as an attachment, optionally associating it with an evidence record. Client permissions are required.';
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
                                    'description' => 'The file to upload',
                                ],
                                'evidence_uuid' => [
                                    'type' => 'string',
                                    'format' => 'uuid',
                                    'description' => 'UUID of the evidence record to associate the attachment with',
                                ],
                            ],
                            'required' => ['file', 'evidence_uuid'],
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
                    'description' => 'Invalid request, missing file, invalid UUID, or validation failure',
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

