<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;
    use InvalidArgumentException;

    class ClassifyEvidence extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to classify evidence';
        private const string ERROR_INVALID_UUID = 'Invalid evidence UUID';
        private const string ERROR_INVALID_CLASSIFICATION = 'Invalid classification flag';
        private const string ERROR_NOT_FOUND = 'Evidence not found';
        private const string ERROR_ALREADY_CLASSIFIED = 'Evidence has already been classified';
        private const string ERROR_UNABLE_TO_CLASSIFY = 'Unable to classify evidence';

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

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36})/classify$#', FederationServer::getPath(), $matches) || !Validate::uuid($matches[1]))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            $classificationValue = FederationServer::getParameter('classification_flag');
            if(!is_string($classificationValue))
            {
                throw new RequestException(self::ERROR_INVALID_CLASSIFICATION, HttpResponseCode::BAD_REQUEST);
            }

            $classification = ClassificationFlag::tryFromCaseInsensitive($classificationValue);
            if($classification === null)
            {
                throw new RequestException(self::ERROR_INVALID_CLASSIFICATION, HttpResponseCode::BAD_REQUEST);
            }

            $evidenceUuid = $matches[1];

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                if($evidenceRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                if(!EvidenceManager::updateClassificationFlag($evidenceUuid, $classification))
                {
                    throw new RequestException(self::ERROR_ALREADY_CLASSIFIED, HttpResponseCode::CONFLICT);
                }

                AuditLogManager::createEntry(AuditLogType::EVIDENCE_UPDATED, sprintf(
                    'Evidence %s classified as %s by %s',
                    $evidenceUuid,
                    $classification->value,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $evidenceRecord->getEntityUuid(), null, $evidenceUuid);

                if(($bayesianClient = FederationServer::getBayesianClient()) !== null && $evidenceRecord->getTextContent() !== null)
                {
                    try
                    {
                        $bayesianClient->learn($evidenceRecord->getTextContent(), $classification->value);
                    }
                    catch(RequestException $e)
                    {
                        Logger::log()->warning('Bayesian learn failed: ' . $e->getMessage());
                    }
                }
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), HttpResponseCode::BAD_REQUEST, $e);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_CLASSIFY, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse();
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
            return 'Classify evidence';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Assigns an immutable classification to evidence and submits its text for Bayesian training when enabled. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'classifyEvidence';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [[
                'name' => 'uuid',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
                'description' => 'UUID of the evidence record to classify',
            ]];
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
                                'classification_flag' => [
                                    'type' => 'string',
                                    'enum' => ['NORMAL', 'SUSPICIOUS', 'MALICIOUS'],
                                    'description' => 'Classification assigned to the evidence',
                                ],
                            ],
                            'required' => ['classification_flag'],
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
                '200' => ['description' => 'Evidence classified successfully', 'content' => ['application/json' => ['schema' => ['$ref' => SuccessResponse::getReference()]]]],
                '400' => ['description' => self::ERROR_INVALID_UUID . ' or ' . self::ERROR_INVALID_CLASSIFICATION, 'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]]],
                '401' => ['description' => 'Authentication required', 'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]]],
                '403' => ['description' => self::ERROR_INSUFFICIENT_PERMISSIONS, 'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]]],
                '404' => ['description' => self::ERROR_NOT_FOUND, 'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]]],
                '409' => ['description' => self::ERROR_ALREADY_CLASSIFIED, 'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]]],
                '500' => ['description' => self::ERROR_UNABLE_TO_CLASSIFY, 'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]]],
            ];
        }
    }
