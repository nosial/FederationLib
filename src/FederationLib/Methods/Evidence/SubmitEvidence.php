<?php

    namespace FederationLib\Methods\Evidence;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\ContentType;
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
        private const string ERROR_INVALID_CONTENT_TYPE = 'Invalid content type';
        private const string ERROR_CONTEXT_INVALID = 'Context must be an object';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_ENTITY_NOT_FOUND = 'Entity not found';
        private const string ERROR_FAILED_TO_CREATE = 'Failed to create evidence';
        private const string ERROR_UNKNOWN_MEMBER = 'Unknown evidence member "%s"';

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

            // The body is a ContentInput object extended with entity_identifier and classification, it must not contain
            // other members (OFD "ContentInput object")
            $body = self::getDecodedContent();
            if($body !== null)
            {
                foreach(array_keys($body) as $member)
                {
                    if(!in_array($member, [...ContentInput::MEMBERS, 'entity_identifier', 'classification'], true))
                    {
                        throw new RequestException(sprintf(self::ERROR_UNKNOWN_MEMBER, $member), HttpResponseCode::BAD_REQUEST);
                    }
                }
            }

            $entityIdentifier = FederationServer::getStringParameter('entity_identifier');
            if($entityIdentifier === null)
            {
                throw new RequestException(self::ERROR_ENTITY_IDENTIFIER_REQUIRED, 400);
            }

            $textContent = FederationServer::getStringParameter('text_content') ?? null;
            $note = FederationServer::getStringParameter('note') ?? null;
            $tag = FederationServer::getStringParameter('tag') ?? null;
            $confidential = FederationServer::getBooleanParameter('confidential') ?? false;
            $metadata = FederationServer::getParameter('metadata');
            $classificationValue = FederationServer::getStringParameter('classification');
            $classification = null;

            $contentType = ContentType::TEXT;
            $contentTypeValue = FederationServer::getStringParameter('content_type');
            if($contentTypeValue !== null)
            {
                $contentType = is_string($contentTypeValue) ? ContentType::tryFromCaseInsensitive($contentTypeValue) : null;
                if($contentType === null)
                {
                    throw new RequestException(self::ERROR_INVALID_CONTENT_TYPE, HttpResponseCode::BAD_REQUEST);
                }
            }

            $context = FederationServer::getParameter('context');
            if($context !== null && !is_array($context))
            {
                throw new RequestException(self::ERROR_CONTEXT_INVALID, HttpResponseCode::BAD_REQUEST);
            }

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

                $entityRecord = self::resolveEntityIdentifier($entityIdentifier, self::ERROR_INVALID_IDENTIFIER);
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
                    classification: $classification,
                    contentType: $contentType,
                    context: $context,
                    // Evidence submitted by a bot operator is automated, and therefore confidential
                    automated: $authenticatedOperator->isBot()
                );
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_SUBMITTED, sprintf(
                    'Evidence created by operator %s',
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $entityUuid, null, $evidenceUuid);
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
                                            'type' => ['string', 'null'],
                                            'description' => 'Optional classification assigned to the evidence. Requires management permissions.',
                                            'enum' => ['NORMAL', 'SUSPICIOUS', 'MALICIOUS', null],
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
