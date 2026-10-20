<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\Categories\BlacklistCategory;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\BlacklistOrderType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\ErrorResponse;

    class SearchBlacklist extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_SEARCH_DISABLED = 'Search functionality is disabled for blacklist';
        private const string ERROR_QUERY_REQUIRED = 'Search query is required';
        private const string ERROR_QUERY_TOO_SHORT = 'Search query must be at least 2 characters';
        private const string ERROR_AUTH_REQUIRED = 'Authentication is required to search blacklist records';
        private const string ERROR_INVALID_TYPE = 'The type parameter must be a comma-separated list of incident types';
        private const string ERROR_UNABLE_TO_SEARCH = 'There was an internal server error while performing the search operation';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $searchConfig = Configuration::getSearchConfiguration();
            if (!$searchConfig->isEnabled() || !$searchConfig->isBlacklistEnabled())
            {
                throw new RequestException(self::ERROR_SEARCH_DISABLED, 404);
            }

            $authenticatedOperator = FederationServer::getAuthenticatedOperator(Configuration::getServerConfiguration()->isBlacklistPublic());
            if (!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTH_REQUIRED, 401);
            }

            $query = FederationServer::getParameter('q');
            if (empty($query) || !is_string($query))
            {
                throw new RequestException(self::ERROR_QUERY_REQUIRED, 400);
            }

            $query = trim($query);
            // The query must contain at least two non-whitespace characters
            if (mb_strlen(preg_replace('/\s+/u', '', $query) ?? '') < 2)
            {
                throw new RequestException(self::ERROR_QUERY_TOO_SHORT, 400);
            }

            $limit = (FederationServer::getIntegerParameter('limit') ?? 10);
            $page = (FederationServer::getIntegerParameter('page') ?? 1);
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

            $categoryInput = FederationServer::getStringParameter('category', true);
            $category = $categoryInput !== null ? BlacklistCategory::tryFromCaseInsensitive($categoryInput) : null;
            $by = FederationServer::getStringParameter('by', true);
            $orderInput = FederationServer::getStringParameter('order', true);
            $order = $orderInput !== null ? OrderType::tryFromCaseInsensitive($orderInput) : null;

            $types = null;
            $typeInput = FederationServer::getParameter('type');
            if($typeInput !== null)
            {
                $types = IncidentType::parseList($typeInput);
                if($types === null)
                {
                    throw new RequestException(self::ERROR_INVALID_TYPE, 400);
                }
            }

            try
            {
                $results = BlacklistManager::searchBlacklist($likePattern, $limit, $page, $category, $by, $order, self::useExtendedSearch(), $types, self::hideTelephoneEntities());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_SEARCH, 500, $e);
            }

            self::successResponse($results);
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Blacklist'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Search blacklist records';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Searches blacklist records by UUID or entity. Authenticated operators also match the incident type and the UUIDs of the creating operator, the supporting report, and the lifting operator, unless the host disabled the extended search.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'searchBlacklist';
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
                    'description' => 'Filter blacklist records by category',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_column(BlacklistCategory::cases(), 'value'),
                    ],
                ],
                [
                    'name' => 'type',
                    'in' => 'query',
                    'description' => 'A comma-separated list of incident types, only blacklist records of one of these types are returned',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'by',
                    'in' => 'query',
                    'description' => 'Field to sort by',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_column(BlacklistOrderType::cases(), 'value'),
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
                    'description' => 'List of matching blacklist records',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => BlacklistRecord::getReference()],
                            ],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_QUERY_REQUIRED . ', or ' . lcfirst(self::ERROR_INVALID_TYPE),
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
