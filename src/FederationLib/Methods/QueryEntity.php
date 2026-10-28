<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\Plugin\EntityQuery;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;

    class QueryEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Querying entities is not available to the public, authentication is required';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity identifier is required';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to query entity relationships';
        private const string ERROR_NOT_FOUND = 'Entity not found';
        private const string ERROR_CONCERNS_INVALID = 'Concerns must be a comma-separated list of concerns this server can assess';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(Configuration::getServerConfiguration()->isQueryEntityPublic());
            if(!Configuration::getServerConfiguration()->isQueryEntityPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            $entityIdentifier = Utilities::matchEntityPath(FederationServer::getPath(), '/query');
            if(!$entityIdentifier)
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            // The concerns of Query Entity are a comma-separated list, defaulting to the default concerns of the host
            $concerns = Configuration::getScanningConfiguration()->getDefaultConcerns();
            $concernsInput = FederationServer::getParameter('concerns');
            if($concernsInput !== null)
            {
                $concerns = is_string($concernsInput) ? IncidentType::parseList($concernsInput) : null;
                if(empty($concerns) || array_any($concerns, fn(IncidentType $concern) => !in_array($concern, Configuration::getScanningConfiguration()->getConcerns(), true)))
                {
                    throw new RequestException(self::ERROR_CONCERNS_INVALID, HttpResponseCode::BAD_REQUEST);
                }
            }

            try
            {
                $entityRecord = self::resolveEntityIdentifier($entityIdentifier, self::ERROR_INVALID_IDENTIFIER);
                if($entityRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                $relationshipGroupUuid = $entityRecord->getRelationshipEntity() ?? $entityRecord->getUuid();
                $relationships = EntitiesManager::getEntitiesByRelationshipEntities([$relationshipGroupUuid, $entityRecord->getUuid()]);
                $relatedEntities = $relationships[$relationshipGroupUuid];
                if($entityRecord->getRelationshipEntity() !== null)
                {
                    $parentEntity = EntitiesManager::getEntityByUuid($relationshipGroupUuid);
                    if($parentEntity !== null)
                    {
                        $relatedEntities[] = $parentEntity;
                    }

                    array_push($relatedEntities, ...$relationships[$entityRecord->getUuid()]);
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

            // Plugins may change the result or reject the request
            $entityQuery = new EntityQuery($entityIdentifier, $entityRecord, $relatedEntities, $activeBlacklists, $authenticatedOperator, $concerns);
            PluginManager::dispatchQueryEntity($entityQuery);
            self::successResponse($entityQuery->getResult()->toStandardArray(!self::omitEntityMetadata(), self::hideTelephoneEntities()));
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
            ], [
                'name' => 'concerns',
                'in' => 'query',
                'description' => 'A comma-separated list of concerns whose blacklist records determine the suggested action. Defaults to the default concerns of the server',
                'required' => false,
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
                    'description' => self::ERROR_INVALID_IDENTIFIER . ', or ' . lcfirst(self::ERROR_CONCERNS_INVALID),
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
