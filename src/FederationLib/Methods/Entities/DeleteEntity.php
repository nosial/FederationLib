<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;

    class DeleteEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage entities';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity Identifier SHA-256/UUID is required';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_NOT_FOUND = 'Entity not found';
        private const string ERROR_UNABLE_TO_DELETE = 'Unable to delete entity';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasManagementPermissions())
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

                EntitiesManager::deleteEntity($entityRecord->getUuid());

                AuditLogManager::createEntry(AuditLogType::ENTITY_DELETED, sprintf(
                    'Entity %s deleted by %s',
                    $entityRecord->getAddress(),
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_DELETE, 500, $e);
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
            return 'Delete an entity';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Permanently deletes an entity by UUID, SHA-256 hash, or entity address. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'deleteEntity';
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
            return null;
        }

        /**
         * @inheritDoc
         */
        public static function getResponses(): array
        {
            return [
                '200' => [
                    'description' => 'Entity deleted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
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
                    'description' => self::ERROR_UNABLE_TO_DELETE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
