<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;

    class BlacklistEntity extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage the blacklist';
        private const string ERROR_ENTITY_IDENTIFIER_REQUIRED = 'Entity UUID is required';
        private const string ERROR_INVALID_TYPE = 'A valid blacklist type is required';
        private const string ERROR_EXPIRES_IN_PAST = 'The expiration time must be in the future';
        private const string ERROR_INVALID_EVIDENCE = 'Evidence must be a valid UUID';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_ENTITY_NOT_FOUND = 'Entity not found';
        private const string ERROR_EVIDENCE_NOT_FOUND = 'Evidence not found';
        private const string ERROR_FAILED_TO_BLACKLIST = 'Failed to blacklist entity';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            $entityIdentifier = FederationServer::getParameter('entity_identifier') ?? null;
            $evidence = FederationServer::getParameter('evidence_uuid') ?? null;
            $type = IncidentType::tryFrom(FederationServer::getParameter('type') ?? '');
            $expires = FederationServer::getParameter('expires');

            if($entityIdentifier === null)
            {
                throw new RequestException(self::ERROR_ENTITY_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            if($type === null)
            {
                throw new RequestException(self::ERROR_INVALID_TYPE, HttpResponseCode::BAD_REQUEST);
            }

            if($expires !== null)
            {
                if((int)$expires < time())
                {
                    throw new RequestException(self::ERROR_EXPIRES_IN_PAST, HttpResponseCode::BAD_REQUEST);
                }
            }

            if($evidence !== null && !Validate::uuid($evidence))
            {
                throw new RequestException(self::ERROR_INVALID_EVIDENCE, HttpResponseCode::BAD_REQUEST);
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
                    throw new RequestException(self::ERROR_ENTITY_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                if($evidence !== null)
                {
                    $evidenceRecord = EvidenceManager::getEvidence($evidence);
                    if($evidenceRecord === null)
                    {
                        throw new RequestException(self::ERROR_EVIDENCE_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                    }

                    if($evidenceRecord->getEntityUuid() !== $entityRecord->getUuid())
                    {
                        throw new RequestException(self::ERROR_EVIDENCE_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                    }
                }

                $blacklistUuid = BlacklistManager::blacklistEntity(
                    entityUuid: $entityRecord->getUuid(),
                    operatorUuid: $authenticatedOperator->getUuid(),
                    type: $type,
                    expires: $expires !== null ? (int)$expires : null,
                    evidenceUuid: $evidence
                );

                AuditLogManager::createEntry(AuditLogType::ENTITY_BLACKLISTED, sprintf(
                    'Entity %s blacklisted by operator %s with type %s%s',
                    $entityRecord->getAddress(),
                    $authenticatedOperator->getName(),
                    $type->name,
                    $expires ? ' until ' . date('Y-m-d H:i:s', $expires) : ' as a permanent'
                ), $authenticatedOperator->getUuid(), $entityRecord->getUuid(), $blacklistUuid, $evidence, null);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_TO_BLACKLIST, 500, $e);
            }

            self::successResponse($blacklistUuid, HttpResponseCode::CREATED);
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
            return 'Blacklist an entity';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Creates a new blacklist entry for an entity. The entity can be identified by UUID, SHA-256 hash, or entity address. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'blacklistEntity';
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
                                'entity_identifier' => [
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the entity to blacklist',
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'description' => 'The type of incident (e.g. spam, scam, malware)',
                                ],
                                'evidence_uuid' => [
                                    'type' => 'string',
                                    'format' => 'uuid',
                                    'description' => 'UUID of evidence supporting the blacklist',
                                    'nullable' => true,
                                ],
                                'expires' => [
                                    'type' => 'integer',
                                    'description' => 'Unix timestamp when the blacklist should expire',
                                    'nullable' => true,
                                ],
                            ],
                            'required' => ['entity_identifier', 'type'],
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
                '201' => [
                    'description' => 'Entity blacklisted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the created blacklist record'],
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
                    'description' => self::ERROR_ENTITY_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_FAILED_TO_BLACKLIST,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
