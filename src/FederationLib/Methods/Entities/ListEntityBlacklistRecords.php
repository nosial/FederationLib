<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\ErrorResponse;

    class ListEntityBlacklistRecords extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'You must be authenticated to list blacklist records';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity Identifier SHA-256/UUID is required';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_NOT_FOUND = 'Entity not found';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve blacklist records from the entity';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, 401);
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

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/blacklist$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/blacklist$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/blacklist$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
            }

            $entityIdentifier = $matches[1];
            if(!$entityIdentifier)
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
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
                    $entityAddress = Utilities::parseEntityAddress($entityIdentifier);
                    $entityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($entityAddress['host'], $entityAddress['id']));
                }
                else
                {
                    throw new RequestException(self::ERROR_INVALID_IDENTIFIER, 400);
                }

                if($entityRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, 404);
                }

                $blacklistRecords = BlacklistManager::getEntriesByEntity($entityRecord->getUuid(), $limit, $page, $includeLifted);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, 500, $e);
            }

            self::successResponse(array_map(fn($record) => $record->toArray(), $blacklistRecords));
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
            return 'List entity blacklist records';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of blacklist records associated with an entity. If the blacklist is public, authentication is optional; otherwise, an operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listEntityBlacklistRecords';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'identifier',
                    'in' => 'path',
                    'description' => 'UUID, SHA-256 hash, or entity address of the entity',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
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
                    'description' => 'List of blacklist records for the entity',
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
                    'description' => self::ERROR_INVALID_IDENTIFIER,
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
