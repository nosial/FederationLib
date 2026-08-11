<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class SetRelationship extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage entities';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity identifier is required';
        private const string ERROR_TARGET_IDENTIFIER_REQUIRED = 'Target entity identifier is required';
        private const string ERROR_INVALID_TARGET_IDENTIFIER = 'A valid target entity identifier is required';
        private const string ERROR_RELATIONSHIP_TYPE_REQUIRED = 'Relationship type is required';
        private const string ERROR_INVALID_RELATIONSHIP_TYPE = 'Relationship type must be one of: ALTERNATIVE, PROXY, CHILD';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_NOT_FOUND = 'Entity not found';
        private const string ERROR_TARGET_NOT_FOUND = 'Target entity not found';
        private const string ERROR_UNABLE_TO_SET = 'Unable to set entity relationship';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            $path = FederationServer::getPath();

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/relationship$#', $path, $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/relationship$#', $path, $matches) &&
                !preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/relationship$#', $path, $matches)
            )
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
            }

            $entityIdentifier = $matches[1];
            if(!$entityIdentifier)
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
            }

            $targetEntityIdentifier = FederationServer::getParameter('target_identifier');
            if($targetEntityIdentifier === null)
            {
                throw new RequestException(self::ERROR_TARGET_IDENTIFIER_REQUIRED, 400);
            }

            $relationshipType = FederationServer::getParameter('relationship_type');
            if($relationshipType === null)
            {
                throw new RequestException(self::ERROR_RELATIONSHIP_TYPE_REQUIRED, 400);
            }

            $type = EntityRelationshipType::tryFromCaseInsensitive($relationshipType);
            if($type === null)
            {
                throw new RequestException(self::ERROR_INVALID_RELATIONSHIP_TYPE, 400);
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
                    $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                    $entityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($parsedAddress['host'], $parsedAddress['id']));
                }
                else
                {
                    throw new RequestException(self::ERROR_INVALID_IDENTIFIER, 400);
                }

                if($entityRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, 404);
                }

                if(Utilities::isUuid($targetEntityIdentifier))
                {
                    $targetEntityRecord = EntitiesManager::getEntityByUuid($targetEntityIdentifier);
                }
                elseif(Utilities::isSha256($targetEntityIdentifier))
                {
                    $targetEntityRecord = EntitiesManager::getEntityByHash($targetEntityIdentifier);
                }
                elseif(Utilities::isEntityAddress($targetEntityIdentifier))
                {
                    $targetParsedAddress = Utilities::parseEntityAddress($targetEntityIdentifier);
                    $targetEntityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($targetParsedAddress['host'], $targetParsedAddress['id']));
                }
                else
                {
                    throw new RequestException(self::ERROR_INVALID_TARGET_IDENTIFIER, 400);
                }

                if($targetEntityRecord === null)
                {
                    throw new RequestException(self::ERROR_TARGET_NOT_FOUND, 404);
                }

                EntitiesManager::assignEntityRelationship($entityRecord->getUuid(), $targetEntityRecord->getUuid(), $type);
                AuditLogManager::createEntry(AuditLogType::ENTITY_UPDATED, sprintf(
                    'Relationship set for entity %s to %s by %s',
                    $entityRecord->getAddress(),
                    $targetEntityRecord->getAddress(),
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_SET, 500, $e);
            }

            self::successResponse();
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
            return 'Set entity relationship';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Sets a relationship between two entities. Requires operator permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'setEntityRelationship';
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
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getRequestBody(): ?array
        {
            return [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'target_identifier' => [
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the target entity',
                                ],
                                'relationship_type' => [
                                    'type' => 'string',
                                    'description' => 'Type of relationship',
                                    'enum' => ['alternative', 'proxy', 'child'],
                                ],
                            ],
                            'required' => ['target_identifier', 'relationship_type'],
                        ],
                    ],
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getResponses(): array
        {
            return [
                '200' => [
                    'description' => 'Entity relationship set successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_TARGET_IDENTIFIER,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => 'Authentication required',
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
                '404' => [
                    'description' => self::ERROR_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_SET,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
