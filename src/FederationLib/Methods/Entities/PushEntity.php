<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;

    class PushEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to push entities';
        private const string ERROR_INVALID_METADATA = 'Invalid entity metadata provided';
        private const string ERROR_UNABLE_TO_REGISTER = 'Unable to register entity';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            $host = FederationServer::getParameter('host');
            $id = FederationServer::getParameter('id') ?? null;
            $metadata = FederationServer::getParameter('metadata');

            if($metadata !== null && (!is_array($metadata) || !Validate::entityMetadata($metadata)))
            {
                throw new RequestException(self::ERROR_INVALID_METADATA, 400);
            }

            try
            {
                if(!EntitiesManager::entityExists($host, $id))
                {
                    $entityUuid = EntitiesManager::registerEntity($host, $id, $metadata);
                    AuditLogManager::createEntry(AuditLogType::ENTITY_PUSHED, sprintf(
                        'Entity %s registered by operator %s',
                        $id !== null ? $id . '@' . $host : $host,
                        $authenticatedOperator->getName()
                    ), $authenticatedOperator->getUuid(), $entityUuid);
                }
                else
                {
                    $entity = EntitiesManager::getEntity($host, $id);
                    $entityUuid = $entity->getUuid();

                    if($metadata !== null && EntitiesManager::updateEntityMetadata($entityUuid, $metadata))
                    {
                        AuditLogManager::createEntry(AuditLogType::ENTITY_UPDATED, sprintf(
                            'Entity %s updated by operator %s',
                            $entity->getAddress(), $authenticatedOperator->getName()
                        ), $authenticatedOperator->getUuid(), $entityUuid);
                    }
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_REGISTER, 500, $e);
            }

            self::successResponse($entityUuid);
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
            return 'Push an entity';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Registers or updates an entity on the server. If the entity already exists, the metadata can be updated. Requires client permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'pushEntity';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [];
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
                                'host' => [
                                    'type' => 'string',
                                    'description' => 'The hostname or domain of the entity',
                                ],
                                'id' => [
                                    'type' => 'string',
                                    'description' => 'The local part identifier of the entity (e.g. email username)',
                                    'nullable' => true,
                                ],
                                'metadata' => [
                                    'type' => 'object',
                                    'description' => 'Arbitrary metadata associated with the entity',
                                    'nullable' => true,
                                ],
                            ],
                            'required' => ['host'],
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
                    'description' => 'Entity registered or updated successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the registered or updated entity'],
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
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_REGISTER,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
