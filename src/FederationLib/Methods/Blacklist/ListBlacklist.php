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

    class ListBlacklist extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'You must be authenticated to list blacklist records';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve blacklist records';
        private const string ERROR_INVALID_TYPE = 'The type parameter must be a comma-separated list of incident types';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(Configuration::getServerConfiguration()->isBlacklistPublic());
            if(!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, 401);
            }

            $limit = (FederationServer::getIntegerParameter('limit') ?? Configuration::getServerConfiguration()->getListBlacklistMaxItems());
            $page = (FederationServer::getIntegerParameter('page') ?? 1);
            $includeLifted = FederationServer::getBooleanParameter('include_lifted') ?? false;

            $categoryInput = FederationServer::getStringParameter('category', true);
            $category = $categoryInput !== null ? BlacklistCategory::tryFromCaseInsensitive($categoryInput) : null;

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListBlacklistMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListBlacklistMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

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
                $blacklistRecords = BlacklistManager::getEntries($limit, $page, $includeLifted, $category, $by, $order, $types);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, 500, $e);
            }

            self::successResponse($blacklistRecords);
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
            return 'List blacklist records';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of blacklist records. If the blacklist is public, authentication is optional; otherwise, an operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listBlacklist';
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
                    'description' => 'Maximum number of blacklist records to return per page',
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
                [
                    'name' => 'include_lifted',
                    'in' => 'query',
                    'description' => 'Whether to include lifted blacklist records',
                    'required' => false,
                    'schema' => ['type' => 'boolean'],
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
                    'description' => 'List of blacklist records',
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
                    'description' => self::ERROR_INVALID_TYPE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
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
