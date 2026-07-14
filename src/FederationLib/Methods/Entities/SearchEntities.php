<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;

    class SearchEntities extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_SEARCH_DISABLED = 'Search functionality is disabled for entities';
        private const string ERROR_QUERY_REQUIRED = 'Search query is required';
        private const string ERROR_QUERY_TOO_SHORT = 'Search query must be at least 2 characters';
        private const string ERROR_AUTH_REQUIRED = 'Authentication is required to search entities';
        private const string ERROR_UNABLE_TO_SEARCH = 'There was an internal server error while preforming the search operation';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $searchConfig = Configuration::getSearchConfiguration();
            if (!$searchConfig->isEnabled() || !$searchConfig->isEntitiesEnabled())
            {
                throw new RequestException(self::ERROR_SEARCH_DISABLED, 404);
            }

            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if (!Configuration::getServerConfiguration()->isEntitiesPublic() && $authenticatedOperator === null)
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

            try
            {
                $results = EntitiesManager::searchEntities($likePattern, $limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_SEARCH, 500, $e);
            }

            self::successResponse(array_map(fn(EntityRecord $r) => $r->toArray(), $results));
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Entities'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Search entities';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Searches entities by UUID, host, or ID.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'searchEntities';
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
                    'description' => 'List of matching entities',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => EntityRecord::getReference()],
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
