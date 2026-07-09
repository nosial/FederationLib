<?php

    namespace FederationLib\Methods\Attachments;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use InvalidArgumentException;
    use Throwable;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class DownloadAttachment extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_UNAUTHORIZED = 'Unauthorized: You must be authenticated to download attachments';
        private const string ERROR_ATTACHMENT_UUID_REQUIRED = 'Attachment UUID is required';
        private const string ERROR_INVALID_ATTACHMENT_UUID = 'Invalid attachment UUID';
        private const string ERROR_ATTACHMENT_NOT_FOUND = 'Attachment not found';
        private const string ERROR_ASSOCIATED_EVIDENCE_NOT_FOUND = 'Associated evidence not found';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to view confidential evidence';
        private const string ERROR_INTERNAL_SERVER_ERROR = 'Internal server error while retrieving attachment';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_UNAUTHORIZED, 401);
            }

            if(!preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_ATTACHMENT_UUID_REQUIRED, 400);
            }

            $attachmentUuid  = $matches[1];
            if(!$attachmentUuid || !Validate::uuid($attachmentUuid))
            {
                throw new RequestException(self::ERROR_INVALID_ATTACHMENT_UUID, 400);
            }

            try
            {
                $attachment = FileAttachmentManager::getRecord($attachmentUuid);
                if(!$attachment)
                {
                    throw new RequestException(self::ERROR_ATTACHMENT_NOT_FOUND, 404);
                }

                $evidence = EvidenceManager::getEvidence($attachment->getEvidenceUuid());

                if($evidence === null)
                {
                    throw new RequestException(self::ERROR_ASSOCIATED_EVIDENCE_NOT_FOUND, 404);
                }

                if($evidence->isConfidential())
                {
                    if($authenticatedOperator === null)
                    {
                        throw new RequestException('You must be authenticated to view confidential evidence', 401);
                    }

                    if(!$authenticatedOperator->hasManagementPermissions())
                    {
                        throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
                    }
                }
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch(RequestException $e)
            {
                throw $e;
            }
            catch(Exception $e)
            {
                throw new RequestException(self::ERROR_INTERNAL_SERVER_ERROR, 500, $e);
            }

            $fileLocation = Configuration::getServerConfiguration()->getStoragePath() . DIRECTORY_SEPARATOR . $attachment->getUuid();
            if(!file_exists($fileLocation))
            {
                throw new RequestException('Attachment file not found', 404);
            }

            $chunkSize = 8192; // 8KB per chunk
            $handle = fopen($fileLocation, 'rb');

            if ($handle === false)
            {
                throw new RequestException('Failed to open attachment file for reading', 500);
            }

            // Set headers for file download
            header('Content-Type: ' . $attachment->getFileMime());
            header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\', "\n", "\r"], '_', $attachment->getFileName()) . '"');
            header('Content-Length: ' . filesize($fileLocation));
            header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1
            header('Pragma: no-cache'); // HTTP 1.0
            header('Expires: 0'); // Proxies

            while (!feof($handle))
            {
                $buffer = fread($handle, $chunkSize);
                if ($buffer === false)
                {
                    fclose($handle);
                    throw new RequestException('Error reading attachment file', 500);
                }

                print($buffer);

                // Flush output buffers to send data immediately
                if (ob_get_level())
                {
                    ob_flush();
                }

                flush();
            }

            fclose($handle);
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
            return 'Download an attachment file';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Downloads the file associated with the provided attachment UUID. Authentication is required if the evidence is confidential.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'downloadAttachment';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'uuid',
                    'in' => 'path',
                    'description' => self::ERROR_ATTACHMENT_UUID_REQUIRED,
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getRequestBody(): ?array
        {
            return null;
        }

        /**
         * @inheritDoc
         */
        public static function getResponses(): array
        {
            return [
                '200' => [
                    'description' => 'The attachment file content',
                    'content' => [
                        'application/octet-stream' => [
                            'schema' => ['type' => 'string', 'format' => 'binary'],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_ATTACHMENT_UUID . ' or bad request',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_UNAUTHORIZED,
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
                    'description' => self::ERROR_ATTACHMENT_NOT_FOUND . ' or ' . self::ERROR_ASSOCIATED_EVIDENCE_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_INTERNAL_SERVER_ERROR,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }

