<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\ErrorResponse;

    class ListOperatorBlacklist extends RequestHandler implements RequestSpecificationInterface
    {
        private const ERROR_AUTHENTICATION_REQUIRED = 'You must be authenticated to list blacklist records';
        private const ERROR_UUID_REQUIRED = 'Operator UUID is required';
        private const ERROR_INVALID_UUID = 'A valid operator UUID is required';
        private const ERROR_NOT_FOUND = 'Operator not found';
        private const ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve blacklist records from the operator';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListBlacklistMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);
            $includeLifted = filter_var(FederationServer::getParameter('include_lifted') ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListBlacklistMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListBlacklistMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/blacklist$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                if(!OperatorManager::operatorExists($operatorUuid))
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                $blacklistRecords = BlacklistManager::getEntriesByOperator($operatorUuid, $limit, $page, $includeLifted);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(array_map(fn($record) => $record->toArray(), $blacklistRecords));
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Operators'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'List blacklist records for an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of blacklist records created by a specific operator. If the blacklist is not public, authentication is required.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listOperatorBlacklist';
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
                    'description' => self::ERROR_UUID_REQUIRED,
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'description' => 'Maximum number of blacklist records to return',
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
                    'description' => self::ERROR_INVALID_UUID,
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
                '404' => [
                    'description' => self::ERROR_NOT_FOUND,
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
