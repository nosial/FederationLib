<?php

    namespace FederationLib\Methods\Reports;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\ReportSubmission;
    use FederationLib\Objects\UploadResult;
    use Symfony\Component\Uid\Uuid;
    use Throwable;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class SubmitReport extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'You do not have permission to create reports';
        private const string ERROR_ENTITY_IDENTIFIER_REQUIRED = 'Reporting entity identifier is required';
        private const string ERROR_CONTENT_EMPTY = 'Content cannot be empty';
        private const string ERROR_INVALID_TYPE = 'Invalid incident type';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_FAILED_RETRIEVE_ENTITY = 'Failed to retrieve entity record';
        private const string ERROR_ENTITY_NOT_FOUND = 'Reporting entity not found';
        private const string ERROR_FILE_SIZE = 'File exceeds maximum allowed size (%d bytes)';
        private const string ERROR_FILE_UPLOAD = 'Invalid file upload or multiple files detected';
        private const string ERROR_FILE_SIZE_INVALID = 'Invalid file size';
        private const string ERROR_FILE_NOT_ACCESSIBLE = 'Uploaded file is not accessible';
        private const string ERROR_SYMLINK_DETECTED = 'Invalid file upload (symbolic link detected)';
        private const string ERROR_PATH_TRAVERSAL = 'Path traversal attempt detected';
        private const string ERROR_STORAGE_CREATE = 'Storage directory could not be created';
        private const string ERROR_STORAGE_NOT_WRITABLE = 'Storage directory is not writable';
        private const string ERROR_MOVE_FAILED = 'Failed to move uploaded file';
        private const string ERROR_RENAME_FAILED = 'Failed to finalize file upload';
        private const string ERROR_FAILED_SUBMISSION = 'Failed to create report submission';
        private const string ERROR_FAILED_UPLOAD = 'Unable to upload file attachment to server';
        private const string ERROR_FAILED_GET_REPORT = 'Failed to get report information';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            $entityIdentifier = FederationServer::getParameter('reporting_entity');
            if($entityIdentifier === null)
            {
                throw new RequestException(self::ERROR_ENTITY_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $content = FederationServer::getParameter('content');
            if($content === null || strlen($content) === 0)
            {
                throw new RequestException(self::ERROR_CONTENT_EMPTY, HttpResponseCode::BAD_REQUEST);
            }

            $incidentType = FederationServer::getParameter('incident_type');
            $incidentType = IncidentType::tryFrom($incidentType);
            if($incidentType === null)
            {
                throw new RequestException(self::ERROR_INVALID_TYPE, HttpResponseCode::BAD_REQUEST);
            }

            $reportMessage = FederationServer::getParameter('report_message');
            if(empty((string)$reportMessage))
            {
                $reportMessage = null;
            }

            $evidenceTag = FederationServer::getParameter('evidence_tag');
            if(empty((string)$evidenceTag))
            {
                $evidenceTag = null;
            }

            try
            {
                if(Utilities::isUuid($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByUuid($entityIdentifier);
                }
                elseif(Utilities::isSha256($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByHash($entityIdentifier);
                }
                elseif(Utilities::isEntityAddress($entityIdentifier))
                {
                    $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                    $entityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($parsedAddress['host'], $parsedAddress['id']));
                }
                else
                {
                    throw new RequestException(self::ERROR_INVALID_IDENTIFIER, HttpResponseCode::BAD_REQUEST);
                }
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_RETRIEVE_ENTITY, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            if($entityRecord === null)
            {
                throw new RequestException(self::ERROR_ENTITY_NOT_FOUND, HttpResponseCode::NOT_FOUND);
            }

            // File upload handling (optional)
            $uploadResults = [];
            $fileAttachmentUuid = null;
            $destinationPath = null;
            $tempDestination = null;
            $file = null;

            if(isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE)
            {
                $file = $_FILES['file'];
                if (!is_array($file) || empty($file['tmp_name']) || is_array($file['tmp_name']))
                {
                    throw new RequestException(self::ERROR_FILE_UPLOAD, HttpResponseCode::BAD_REQUEST);
                }

                if (!isset($file['size']) || $file['size'] <= 0)
                {
                    throw new RequestException(self::ERROR_FILE_SIZE_INVALID, HttpResponseCode::BAD_REQUEST);
                }

                if ($file['size'] > Configuration::getServerConfiguration()->getMaxUploadSize())
                {
                    throw new RequestException(sprintf(self::ERROR_FILE_SIZE, Configuration::getServerConfiguration()->getMaxUploadSize()), HttpResponseCode::BAD_REQUEST);
                }

                if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK)
                {
                    throw new RequestException(self::getUploadErrorMessage($file['error'] ?? -1), HttpResponseCode::BAD_REQUEST);
                }

                if (!is_file($file['tmp_name']) || !is_readable($file['tmp_name']))
                {
                    throw new RequestException(self::ERROR_FILE_NOT_ACCESSIBLE, HttpResponseCode::BAD_REQUEST);
                }

                $detectedMimeType = self::detectMimeType($file['tmp_name']);
                $originalName = self::getSafeFileName($file['name'] ?? '');
                if (empty($originalName) || $originalName === 'unnamed')
                {
                    $originalName = Uuid::v4()->toRfc4122();
                }

                if (is_link($file['tmp_name']))
                {
                    throw new RequestException(self::ERROR_SYMLINK_DETECTED, HttpResponseCode::BAD_REQUEST);
                }

                $realpath = realpath($file['tmp_name']);
                if ($realpath === false || !str_starts_with($realpath, sys_get_temp_dir()))
                {
                    throw new RequestException(self::ERROR_PATH_TRAVERSAL, HttpResponseCode::BAD_REQUEST);
                }

                $storagePath = rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR);
                if (!is_dir($storagePath))
                {
                    if (!mkdir($storagePath, 0750, true))
                    {
                        throw new RequestException(self::ERROR_STORAGE_CREATE, HttpResponseCode::INTERNAL_SERVER_ERROR);
                    }
                }

                if (!is_writable($storagePath))
                {
                    throw new RequestException(self::ERROR_STORAGE_NOT_WRITABLE, HttpResponseCode::INTERNAL_SERVER_ERROR);
                }

                $fileAttachmentUuid = Uuid::v4()->toRfc4122();
                $destinationPath = $storagePath . DIRECTORY_SEPARATOR . $fileAttachmentUuid;
                $tempDestination = $storagePath . DIRECTORY_SEPARATOR . uniqid('tmp_', true);

                if (!move_uploaded_file($file['tmp_name'], $tempDestination))
                {
                    throw new RequestException(self::ERROR_MOVE_FAILED, HttpResponseCode::INTERNAL_SERVER_ERROR);
                }
            }

            try
            {
                // Submit the report
                $reportUuid = ReportManager::createReport(
                    submittingOperator: $authenticatedOperator->getUuid(),
                    reportingEntity: $entityRecord->getUuid(),
                    type: $incidentType,
                    message: $content
                );

                ReportManager::assignOperator($reportUuid, $authenticatedOperator->getUuid());

                // Create the evidence
                $evidenceUuid = EvidenceManager::addEvidence(
                    entity: $entityRecord->getUuid(),
                    operator: $authenticatedOperator->getUuid(),
                    textContent: $content,
                    note: $reportMessage,
                    tag: $evidenceTag,
                    report: $reportUuid
                );

                // Finalize file attachment if uploaded
                if($file !== null)
                {
                    chmod($tempDestination, 0640);

                    if (!rename($tempDestination, $destinationPath))
                    {
                        throw new RequestException(self::ERROR_RENAME_FAILED, HttpResponseCode::INTERNAL_SERVER_ERROR);
                    }

                    FileAttachmentManager::createRecord($fileAttachmentUuid, $evidenceUuid, $detectedMimeType, $originalName, $file['size']);
                    $uploadResults[] = new UploadResult($fileAttachmentUuid, Configuration::getServerConfiguration()->getBaseUrl() . '/attachments/' . $fileAttachmentUuid);
                }

                // Create a audit log entry
                AuditLogManager::createEntry(
                    type: AuditLogType::REPORT_SUBMITTED,
                    message: $reportMessage ?? 'No message provided',
                    operatorUuid: $authenticatedOperator->getUuid(),
                    entityUuid: $entityRecord->getUuid(),
                    evidenceUuid: $evidenceUuid,
                    fileAttachmentUuid: $fileAttachmentUuid
                );
            }
            catch (DatabaseOperationException $e)
            {
                if($destinationPath !== null && file_exists($destinationPath))
                {
                    @unlink($destinationPath);
                }
                throw new RequestException(self::ERROR_FAILED_SUBMISSION, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }
            catch (Throwable $e)
            {
                if($destinationPath !== null && file_exists($destinationPath))
                {
                    @unlink($destinationPath);
                }
                throw new RequestException(self::ERROR_FAILED_UPLOAD, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }
            finally
            {
                if($tempDestination !== null && file_exists($tempDestination))
                {
                    @unlink($tempDestination);
                }

                if(isset($file['tmp_name']) && file_exists($file['tmp_name']))
                {
                    @unlink($file['tmp_name']);
                }
            }

            try
            {
                self::successResponse(new ReportSubmission(
                    ReportManager::getReport($reportUuid),
                    EvidenceManager::getEvidence($evidenceUuid),
                    !empty($uploadResults) ? $uploadResults : null
                ));
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_GET_REPORT, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }
        }

        /**
         * Get human-readable error message for PHP upload error codes
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
         */
        private static function detectMimeType(string $filePath): string
        {
            if (function_exists('finfo_open'))
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $filePath);
                if ($mimeType)
                {
                    return $mimeType;
                }
            }

            if (function_exists('mime_content_type'))
            {
                $mimeType = mime_content_type($filePath);
                if ($mimeType)
                {
                    return $mimeType;
                }
            }

            return 'application/octet-stream';
        }

        /**
         * Get a safe filename by removing potentially unsafe characters
         */
        private static function getSafeFileName(string $filename): string
        {
            $filename = basename($filename);
            $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

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
            return ['Reports'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Submit a report';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Creates a new report with optional evidence and file attachments. Requires client permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'submitReport';
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
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'reporting_entity' => [
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the entity being reported',
                                ],
                                'content' => [
                                    'type' => 'string',
                                    'description' => 'The content/message of the report',
                                ],
                                'incident_type' => [
                                    'type' => 'string',
                                    'description' => 'The type of incident being reported',
                                ],
                                'report_message' => [
                                    'type' => 'string',
                                    'description' => 'Optional message for the report',
                                    'nullable' => true,
                                ],
                                'evidence_tag' => [
                                    'type' => 'string',
                                    'description' => 'Optional tag for the evidence',
                                    'nullable' => true,
                                ],
                            ],
                            'required' => ['reporting_entity', 'content', 'incident_type'],
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
                '200' => [
                    'description' => 'Report submitted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ReportSubmission::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_ENTITY_IDENTIFIER_REQUIRED,
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
                    'description' => self::ERROR_ENTITY_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_FAILED_SUBMISSION,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
