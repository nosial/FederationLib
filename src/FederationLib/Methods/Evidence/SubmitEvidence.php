<?php

    namespace FederationLib\Methods\Evidence;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ErrorResponse;
    use InvalidArgumentException;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    class SubmitEvidence extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'You do not have permission to create evidence';
        private const string ERROR_INSUFFICIENT_CLASSIFICATION_PERMISSIONS = 'Insufficient permissions to classify evidence';
        private const string ERROR_ENTITY_IDENTIFIER_REQUIRED = 'Entity identifier is required';
        private const string ERROR_METADATA_INVALID = 'Metadata must be an object';
        private const string ERROR_INVALID_CLASSIFICATION = 'Invalid classification flag';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_ENTITY_NOT_FOUND = 'Entity not found';
        private const string ERROR_FAILED_TO_CREATE = 'Failed to create evidence';

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

            $entityIdentifier = FederationServer::getParameter('entity_identifier');
            if($entityIdentifier === null)
            {
                throw new RequestException(self::ERROR_ENTITY_IDENTIFIER_REQUIRED, 400);
            }

            $textContent = FederationServer::getParameter('text_content') ?? null;
            $note = FederationServer::getParameter('note') ?? null;
            $tag = FederationServer::getParameter('tag') ?? null;
            $confidential = filter_var(FederationServer::getParameter('confidential') ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            $metadata = FederationServer::getParameter('metadata');
            $classificationValue = FederationServer::getParameter('classification');
            $classification = null;

            if($classificationValue !== null)
            {
                if(!$authenticatedOperator->hasManagementPermissions())
                {
                    throw new RequestException(self::ERROR_INSUFFICIENT_CLASSIFICATION_PERMISSIONS, HttpResponseCode::FORBIDDEN);
                }

                if(!is_string($classificationValue))
                {
                    throw new RequestException(self::ERROR_INVALID_CLASSIFICATION, HttpResponseCode::BAD_REQUEST);
                }

                $parsedClassification = ClassificationFlag::tryFromCaseInsensitive($classificationValue);
                if($parsedClassification === null)
                {
                    throw new RequestException(self::ERROR_INVALID_CLASSIFICATION, HttpResponseCode::BAD_REQUEST);
                }

                $classification = $parsedClassification;
            }

            try
            {
                if($metadata !== null && !is_array($metadata))
                {
                    throw new RequestException(self::ERROR_METADATA_INVALID, 400);
                }

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
                    throw new RequestException(self::ERROR_ENTITY_NOT_FOUND, 404);
                }

                $entityUuid = $entityRecord->getUuid();

                $evidenceUuid = EvidenceManager::addEvidence(
                    entity: $entityUuid,
                    operator: $authenticatedOperator->getUuid(),
                    textContent: $textContent,
                    note: $note,
                    tag: $tag,
                    confidential: $confidential,
                    metadata: $metadata,
                    classification: $classification
                );
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_SUBMITTED, sprintf(
                    'Evidence created by operator %s',
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $entityUuid, null, $evidenceUuid);

                if($classification !== null && ($bayesianClient = FederationServer::getBayesianClient()) !== null && $textContent !== null)
                {
                    try
                    {
                        $bayesianClient->learn($textContent, $classification->value);
                    }
                    catch(RequestException $e)
                    {
                        Logger::log()->warning('Bayesian learn failed: ' . $e->getMessage());
                    }
                }
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_TO_CREATE, 500, $e);
            }

            self::successResponse($evidenceUuid, HttpResponseCode::CREATED);
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Evidence'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Submit evidence';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Creates a new evidence record for a known entity. Requires client permissions; assigning a classification additionally requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'submitEvidence';
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
                            'allOf' => [
                                ['$ref' => ContentInput::getReference()],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'entity_identifier' => [
                                            'type' => 'string',
                                            'description' => 'UUID, SHA-256 hash, or entity address of the entity',
                                        ],
                                        'classification' => [
                                            'type' => 'string',
                                            'description' => 'Optional immutable classification assigned to the evidence. Requires management permissions and submits text for Bayesian training when enabled.',
                                            'enum' => ['NORMAL', 'SUSPICIOUS', 'MALICIOUS'],
                                            'nullable' => true,
                                        ],
                                    ],
                                    'required' => ['entity_identifier'],
                                ],
                            ],
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
                    'description' => 'Evidence created successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the created evidence record'],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_IDENTIFIER . ' or ' . self::ERROR_INVALID_CLASSIFICATION,
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
                    'description' => self::ERROR_INSUFFICIENT_PERMISSIONS . ' or ' . self::ERROR_INSUFFICIENT_CLASSIFICATION_PERMISSIONS,
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
                    'description' => self::ERROR_FAILED_TO_CREATE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
