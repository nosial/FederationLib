<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RemoteRetrieval;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
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
        private const string ERROR_NOT_EVIDENCE_OWNER = 'Files can only be attached to evidence submitted by the operator itself without management permissions';
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

            $evidenceUuid = FederationServer::getStringParameter('evidence_uuid');
            if(empty($evidenceUuid) || !Validate::uuid($evidenceUuid))
            {
                throw new RequestException('A valid evidence UUID is required', 400);
            }

            if($evidenceUuid !== null)
            {
                try
                {
                    $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                    if($evidenceRecord === null)
                    {
                        throw new RequestException(self::ERROR_EVIDENCE_NOT_FOUND, 404);
                    }

                    if($evidenceRecord->getOperatorUuid() !== $operator->getUuid() && !$operator->hasManagementPermissions())
                    {
                        throw new RequestException(self::ERROR_NOT_EVIDENCE_OWNER, 403);
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

            if($hasUrl && !Configuration::getRemoteRetrievalConfiguration()->isUploadsEnabled())
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
                    $suppliedName = RemoteRetrieval::download($url, $tempDestination, Configuration::getServerConfiguration()->getMaxUploadSize());
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

            $originalName = Utilities::getSafeFileName($suppliedName);
            if (empty($originalName) || $originalName === 'unnamed')
            {
                $originalName = Uuid::v7()->toRfc4122();
            }

            try
            {
                // Set restrictive permissions before moving to final destination
                chmod($tempDestination, 0640);
                $detectedMimeType = Utilities::detectMimeType($tempDestination);

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
            return 'Uploads a file as an attachment and associates it with an evidence record. The file is either uploaded directly as a multipart/form-data part or retrieved by the server from an HTTP or HTTPS URL, but not both. Client permissions are required, and management permissions to attach a file to evidence submitted by another operator.';
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
                    'maxLength' => RemoteRetrieval::URL_MAX_LENGTH,
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
                    'description' => self::ERROR_INSUFFICIENT_PERMISSIONS . ', or ' . self::ERROR_NOT_EVIDENCE_OWNER,
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

