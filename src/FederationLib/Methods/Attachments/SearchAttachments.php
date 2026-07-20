<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\Categories\AttachmentCategory;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\AttachmentOrderType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\FileAttachmentRecord;

    class SearchAttachments extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_SEARCH_DISABLED = 'Search functionality is disabled for attachments';
        private const string ERROR_QUERY_REQUIRED = 'Search query is required';
        private const string ERROR_QUERY_TOO_SHORT = 'Search query must be at least 2 characters';
        private const string ERROR_AUTH_REQUIRED = 'Management permissions are required to search attachments';
        private const string ERROR_UNABLE_TO_SEARCH = 'There was an internal server error while preforming the search operation';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $searchConfig = Configuration::getSearchConfiguration();
            if (!$searchConfig->isEnabled() || !$searchConfig->isAttachmentsEnabled())
            {
                throw new RequestException(self::ERROR_SEARCH_DISABLED, 404);
            }

            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if ($authenticatedOperator === null || !$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_AUTH_REQUIRED, 401);
            }

            $query = FederationServer::getParameter('q');
            if (empty($query))
            {
                throw new RequestException(self::ERROR_QUERY_REQUIRED, 400);
            }

            $query = trim($query);
            if (strlen($query) < 2)
            {
                throw new RequestException(self::ERROR_QUERY_TOO_SHORT, 400);
            }

            $limit = (int)(FederationServer::getParameter('limit') ?? 10);
            $page = (int)(FederationServer::getParameter('page') ?? 1);
            $maxLimit = $searchConfig->getMaxLimit();

            if ($limit < 1)
            {
                $limit = 1;
            }
            elseif ($limit > $maxLimit)
            {
                $limit = $maxLimit;
            }

            if ($page < 1)
            {
                $page = 1;
            }

            $likePattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

            $categoryInput = FederationServer::getParameter('category');
            $category = $categoryInput !== null ? AttachmentCategory::tryFromCaseInsensitive($categoryInput) : null;
            $by = FederationServer::getParameter('by');
            $orderInput = FederationServer::getParameter('order');
            $order = $orderInput !== null ? OrderType::tryFromCaseInsensitive($orderInput) : null;

            try
            {
                $results = FileAttachmentManager::searchAttachments($likePattern, $limit, $page, true, $category, $by, $order);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_SEARCH, 500, $e);
            }

            self::successResponse(array_map(fn(FileAttachmentRecord $r) => $r->toArray(), $results));
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
            return 'Search attachments';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Searches file attachments by UUID, file name, or associated evidence.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'searchAttachments';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'q',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 2],
                    'description' => 'The search query string',
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 10],
                    'description' => 'Maximum number of results',
                ],
                [
                    'name' => 'page',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'description' => 'Page number for pagination',
                ],
                [
                    'name' => 'category',
                    'in' => 'query',
                    'description' => 'Filter attachments by MIME type category',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_column(AttachmentCategory::cases(), 'value'),
                    ],
                ],
                [
                    'name' => 'by',
                    'in' => 'query',
                    'description' => 'Field to sort by',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_column(AttachmentOrderType::cases(), 'value'),
                    ],
                ],
                [
                    'name' => 'order',
                    'in' => 'query',
                    'description' => 'Sort direction',
                    'required' => false,
                    'schema' => ['type' => 'string', 'enum' => array_column(OrderType::cases(), 'value')],
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
                    'description' => 'List of matching file attachments',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => FileAttachmentRecord::getReference()],
                            ],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_QUERY_REQUIRED,
                    'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]],
                ],
                '401' => [
                    'description' => self::ERROR_AUTH_REQUIRED,
                    'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]],
                ],
                '404' => [
                    'description' => self::ERROR_SEARCH_DISABLED,
                    'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_SEARCH,
                    'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]],
                ],
            ];
        }
    }
