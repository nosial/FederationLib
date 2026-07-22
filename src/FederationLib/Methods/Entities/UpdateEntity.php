<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;

    class UpdateEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to update entities';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity identifier is required';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_INVALID_METADATA = 'Invalid entity metadata provided';
        private const string ERROR_NOT_FOUND = 'Entity not found';
        private const string ERROR_UNABLE_TO_UPDATE = 'Unable to update entity';

        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
            }

            $entityIdentifier = $matches[1];
            if(!$entityIdentifier)
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
            }

            $metadata = FederationServer::getParameter('metadata');
            if($metadata === null || !is_array($metadata))
            {
                throw new RequestException(self::ERROR_INVALID_METADATA, 400);
            }

            if(!Validate::entityMetadata($metadata))
            {
                throw new RequestException(self::ERROR_INVALID_METADATA, 400);
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

                $entityUuid = $entityRecord->getUuid();

                if(EntitiesManager::replaceEntityMetadata($entityUuid, $metadata))
                {
                    AuditLogManager::createEntry(AuditLogType::ENTITY_UPDATED, sprintf(
                        'Entity %s updated by operator %s',
                        $entityRecord->getAddress(),
                        $authenticatedOperator->getName()
                    ), $authenticatedOperator->getUuid(), $entityUuid);
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_UPDATE, 500, $e);
            }

            self::successResponse();
        }

        public static function getTags(): array
        {
            return ['Entities'];
        }

        public static function getSummary(): string
        {
            return 'Update an entity';
        }

        public static function getDescription(): string
        {
            return 'Updates an existing entity\'s metadata by UUID, SHA-256 hash, or entity address. Requires client permissions.';
        }

        public static function getOperationId(): string
        {
            return 'updateEntity';
        }

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

        public static function getRequestBody(): ?array
        {
            return [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'metadata' => [
                                    'type' => 'object',
                                    'description' => 'Arbitrary metadata to merge with existing entity metadata',
                                ],
                            ],
                            'required' => ['metadata'],
                        ],
                    ],
                ],
            ];
        }

        public static function getResponses(): array
        {
            return [
                '200' => [
                    'description' => 'Entity updated successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_METADATA,
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
                    'description' => self::ERROR_UNABLE_TO_UPDATE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
