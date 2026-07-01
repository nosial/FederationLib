<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\FileAttachmentRecord;

    class ListAttachments extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Authentication is required to list attachments';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to list attachments';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve attachment records';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if($authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListAttachmentsMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListAttachmentsMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListAttachmentsMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $attachmentRecords = FileAttachmentManager::getAttachmentRecords($limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(array_map(fn($attachment) => $attachment->toArray(), $attachmentRecords));
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
            return 'List all attachments';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of all file attachments. Management permissions are required.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listAttachments';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'description' => 'Maximum number of attachments to return per page',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
                [
                    'name' => 'page',
                    'in' => 'query',
                    'description' => 'Page number for pagination',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1],
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
                    'description' => 'List of attachment records',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => FileAttachmentRecord::getReference()],
                            ],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_AUTHENTICATION_REQUIRED,
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
