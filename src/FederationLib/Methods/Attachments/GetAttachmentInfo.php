<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\FileAttachmentRecord;

    class GetAttachmentInfo extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Authentication is required to view evidence attachment records.';
        private const string ERROR_ATTACHMENT_UUID_REQUIRED = 'Attachment UUID is required';
        private const string ERROR_INVALID_ATTACHMENT_UUID = 'Invalid attachment UUID';
        private const string ERROR_ATTACHMENT_NOT_FOUND = 'Attachment not found';
        private const string ERROR_UNAUTHORIZED_CONFIDENTIAL = 'You must be authenticated to view confidential evidence attachments.';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to view confidential evidence attachments.';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve attachment record';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            if(!preg_match('#^/attachments/([a-fA-F0-9\-]{36})/info$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_ATTACHMENT_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $attachmentUuid  = $matches[1];
            if(!$attachmentUuid || !Validate::uuid($attachmentUuid))
            {
                throw new RequestException(self::ERROR_INVALID_ATTACHMENT_UUID, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                // Fetch the attachment record
                $attachmentRecord = FileAttachmentManager::getRecord($attachmentUuid);
                if($attachmentRecord === null)
                {
                    throw new RequestException(self::ERROR_ATTACHMENT_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                // Get the associated evidence record with the attachment
                $evidenceRecord = EvidenceManager::getEvidence($attachmentRecord->getEvidenceUuid());
                if($evidenceRecord !== null && $evidenceRecord->isConfidential())
                {
                    if($authenticatedOperator === null)
                    {
                        throw new RequestException(self::ERROR_UNAUTHORIZED_CONFIDENTIAL, HttpResponseCode::UNAUTHORIZED);
                    }

                    if(!$authenticatedOperator->hasManagementPermissions())
                    {
                        throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
                    }
                }
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse($attachmentRecord);
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
            return 'Get attachment information';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves metadata about an attachment without downloading the file. Authentication is required if the associated evidence is confidential.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'getAttachmentInfo';
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
                    'description' => 'Attachment record retrieved successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => FileAttachmentRecord::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_ATTACHMENT_UUID,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_AUTHENTICATION_REQUIRED . ' or ' . self::ERROR_UNAUTHORIZED_CONFIDENTIAL,
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
                    'description' => self::ERROR_ATTACHMENT_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_RETRIEVE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }