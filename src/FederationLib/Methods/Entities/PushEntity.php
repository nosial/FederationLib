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

    class PushEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to push entities';
        private const string ERROR_INVALID_ID = 'The local part of the entity must only contain ASCII letters, digits, ., _, %, + or -';
        private const string ERROR_INVALID_METADATA = 'Invalid entity metadata provided';
        private const string ERROR_INVALID_TELEPHONE = 'A telephone entity must be the international number as 1 to 15 digits without a leading + or separators, such as 15550123456@e164.arpa, and no entity host may be under e164.arpa';
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

            if($host === null || !is_string($host))
            {
                throw new RequestException(self::ERROR_INVALID_METADATA, 400);
            }

            // The local part must already be canonical, it is case-sensitive and limited to these characters
            if($id !== null && (!is_string($id) || preg_match('/^[a-zA-Z0-9._%+-]+$/', $id) !== 1))
            {
                throw new RequestException(self::ERROR_INVALID_ID, 400);
            }

            // A telephone entity is the E.164 number without '+' or separators, and neither e164.arpa nor a host under it is an entity
            if(!Validate::entityAddress($host, $id))
            {
                throw new RequestException(self::ERROR_INVALID_TELEPHONE, 400);
            }

            if($metadata !== null && (!is_array($metadata) || !Validate::metadata($metadata)))
            {
                throw new RequestException(self::ERROR_INVALID_METADATA, 400);
            }

            try
            {
                if(!EntitiesManager::entityExists($host, $id))
                {
                    $entityUuid = EntitiesManager::registerEntity($host, $id, $metadata);
                    // The address of a telephone entity never appears in an audit log message (OFD "Telephone Entities")
                    AuditLogManager::createEntry(AuditLogType::ENTITY_PUSHED, sprintf(
                        'Entity %s registered by operator %s',
                        Utilities::isTelephoneHost($host) ? 'telephone entity ' . $entityUuid : ($id !== null ? $id . '@' . $host : $host),
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
                            $entity->getLoggableAddress(), $authenticatedOperator->getName()
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
                                    'type' => ['string', 'null'],
                                    'description' => 'The local part identifier of the entity (e.g. email username)',
                                ],
                                'metadata' => Utilities::getMetadataSchema('Metadata to merge into the existing entity metadata'),
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
