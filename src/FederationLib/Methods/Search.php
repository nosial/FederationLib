<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\SearchManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SearchResult;

    class Search extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_SEARCH_DISABLED = 'Search functionality is disabled';
        private const string ERROR_QUERY_REQUIRED = 'Search query is required';
        private const string ERROR_QUERY_TOO_SHORT = 'Search query must be at least 2 characters';
        private const string ERROR_AUTH_REQUIRED = 'Authentication is required to use search';
        private const string ERROR_TYPE_INVALID = 'Invalid search type specified';
        private const string ERROR_UNABLE_TO_SEARCH = 'There was an internal server error while preforming the search operation';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            if (!Configuration::getSearchConfiguration()->isEnabled())
            {
                throw new RequestException(self::ERROR_SEARCH_DISABLED, HttpResponseCode::NOT_FOUND);
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

            $typeParam = FederationServer::getParameter('type');
            $types = null;

            if ($typeParam !== null)
            {
                $types = explode(',', $typeParam);
                $types = array_map('trim', $types);

                $validValues = self::getValidTypeValues();
                foreach ($types as $t)
                {
                    if (!in_array($t, $validValues, true))
                    {
                        throw new RequestException(self::ERROR_TYPE_INVALID, 400);
                    }
                }
            }

            $limit = (int)(FederationServer::getParameter('limit') ?? 10);
            $page = (int)(FederationServer::getParameter('page') ?? 1);
            $maxLimit = Configuration::getSearchConfiguration()->getMaxLimit();

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

            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if (!Configuration::getSearchConfiguration()->isPublicSearch() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTH_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            $likePattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

            try
            {
                $searchResults = SearchManager::search($likePattern, $limit, $page, $authenticatedOperator, $types);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_SEARCH, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(array_map(fn(SearchResult $r) => $r->toArray(), $searchResults));
        }

        private static function getValidTypeValues(): array
        {
            return array_map(fn(RecordType $case) => $case->value, RecordType::cases());
        }


        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Search'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Search the database';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Searches across entities, evidence, blacklist records, reports, attachments, audit logs, and operators. Results are filtered based on the authenticated operator\'s permissions and server configuration.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'search';
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
                    'name' => 'type',
                    'in' => 'query',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => self::getValidTypeValues(),
                    ],
                    'description' => 'Comma-separated list of resource types to search. If omitted, all types are searched.',
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 10],
                    'description' => 'Maximum number of results per resource type',
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
                    'description' => 'Search results as a flat array of typed result objects',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => SearchResult::getReference()],
                            ],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_QUERY_REQUIRED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_AUTH_REQUIRED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '404' => [
                    'description' => self::ERROR_SEARCH_DISABLED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_SEARCH,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
