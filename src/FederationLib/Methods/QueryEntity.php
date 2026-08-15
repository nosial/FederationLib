<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;

    class QueryEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Querying entities is not available to the public, authentication is required';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity identifier is required';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to query entity relationships';
        private const string ERROR_NOT_FOUND = 'Entity not found';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            if(!Configuration::getServerConfiguration()->isQueryEntityPublic() && FederationServer::getAuthenticatedOperator() === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/query$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/query$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/query$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $entityRecord = self::resolveEntity($matches[1]);
                if($entityRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                $relationshipGroupUuid = $entityRecord->getRelationshipEntity() ?? $entityRecord->getUuid();
                $relatedEntities = EntitiesManager::getEntitiesByRelationshipEntity($relationshipGroupUuid);
                if($entityRecord->getRelationshipEntity() !== null)
                {
                    $parentEntity = EntitiesManager::getEntityByUuid($relationshipGroupUuid);
                    if($parentEntity !== null)
                    {
                        $relatedEntities[] = $parentEntity;
                    }
                }

                $relatedEntities = array_values(array_filter($relatedEntities,
                    fn(EntityRecord $entity) => $entity->getUuid() !== $entityRecord->getUuid()
                ));

                $relatedByUuid = [];
                foreach($relatedEntities as $relatedEntity)
                {
                    $relatedByUuid[$relatedEntity->getUuid()] = $relatedEntity;
                }

                $relatedEntities = array_values($relatedByUuid);
                $entityUuids = [$entityRecord->getUuid(), ...array_map(fn(EntityRecord $entity) => $entity->getUuid(), $relatedEntities)];
                $activeBlacklists = BlacklistManager::getActiveEntriesByEntities($entityUuids);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(
                new EntityQueryResult($entityRecord, $relatedEntities, $activeBlacklists)->toStandardArray(!self::omitEntityMetadata())
            );
        }

        /**
         * Resolves a UUID, SHA-256 hash, or entity address to an entity record.
         *
         * @throws RequestException If the identifier format is invalid.
         * @throws DatabaseOperationException If entity retrieval fails.
         */
        private static function resolveEntity(string $identifier): ?EntityRecord
        {
            if(Utilities::isUuid($identifier))
            {
                return EntitiesManager::getEntityByUuid($identifier);
            }

            if(Utilities::isSha256($identifier))
            {
                return EntitiesManager::getEntityByHash($identifier);
            }

            if(Utilities::isEntityAddress($identifier))
            {
                $address = Utilities::parseEntityAddress($identifier);
                return EntitiesManager::getEntityByHash(Utilities::hashEntity($address['host'], $address['id']));
            }

            throw new RequestException(self::ERROR_INVALID_IDENTIFIER, HttpResponseCode::BAD_REQUEST);
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
            return 'Query an entity relationship group';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves an entity and the other entities related through its parent relationship. When the queried entity has a parent, that parent and its other children are returned as related entities.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'queryEntity';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [[
                'name' => 'identifier',
                'in' => 'path',
                'description' => 'UUID, SHA-256 hash, or entity address of the entity',
                'required' => true,
                'schema' => ['type' => 'string'],
            ]];
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
                    'description' => 'Entity relationship group retrieved successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => EntityQueryResult::getReference()]
                        ]
                    ]
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_IDENTIFIER,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()]
                        ]
                    ]
                ],
                '401' => [
                    'description' => self::ERROR_AUTHENTICATION_REQUIRED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()]
                        ]
                    ]
                ],
                '404' => [
                    'description' => self::ERROR_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()]
                        ]
                    ]
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_RETRIEVE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()]
                        ]
                    ]
                ],
            ];
        }
    }
