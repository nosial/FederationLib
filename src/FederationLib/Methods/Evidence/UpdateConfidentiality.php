<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;

    class UpdateConfidentiality extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'You do not have permission to update confidentiality settings';
        private const string ERROR_UUID_REQUIRED = 'Evidence UUID is required';
        private const string ERROR_INVALID_UUID = 'Invalid evidence UUID';
        private const string ERROR_NOT_FOUND = 'Evidence not found';
        private const string ERROR_UNABLE_TO_UPDATE = 'Unable to update evidence confidentiality';
        private const string ERROR_CONFIDENTIAL_REQUIRED = 'The confidential member is required and must be a boolean';
        private const string ERROR_ILLEGAL_CONTENT = 'Evidence linked to an ILLEGAL_CONTENT report must remain confidential';

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

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36})/update-confidentiality$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, 400);
            }

            $evidenceUuid = $matches[1];
            if(!$evidenceUuid || !Validate::uuid($evidenceUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, 400);
            }

            // A missing or invalid value is rejected rather than defaulting, it would otherwise expose the evidence
            $confidential = FederationServer::getBooleanParameter('confidential');
            if($confidential === null)
            {
                throw new RequestException(self::ERROR_CONFIDENTIAL_REQUIRED, 400);
            }

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                if($evidenceRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, 404);
                }

                // Evidence of illegal content must stay confidential while it is linked to the report (OFD "Confidential
                // Evidence")
                if(!$confidential && $evidenceRecord->getReport() !== null)
                {
                    $reportRecord = ReportManager::getReport($evidenceRecord->getReport());
                    if($reportRecord !== null && $reportRecord->getIncidentType() === IncidentType::ILLEGAL_CONTENT)
                    {
                        throw new RequestException(self::ERROR_ILLEGAL_CONTENT, 409);
                    }
                }

                EvidenceManager::updateConfidentiality($evidenceUuid, $confidential);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_UPDATED, sprintf(
                    'Evidence %s made %s by operator %s',
                    $evidenceUuid,
                    $confidential ? 'confidential' : 'non-confidential',
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $evidenceRecord->getEntityUuid(), null, $evidenceUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_UPDATE, 500, $e);
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
            return 'Update evidence confidentiality';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Updates the confidentiality flag of an evidence record. Requires management permissions. Evidence linked to an ILLEGAL_CONTENT report cannot be made non-confidential.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'updateEvidenceConfidentiality';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'uuid',
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                    'description' => 'UUID of the evidence record to update',
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
                                'confidential' => [
                                    'type' => 'boolean',
                                    'description' => 'Whether the evidence should be confidential',
                                ],
                            ],
                            'required' => ['confidential'],
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
                    'description' => 'Confidentiality updated successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_UUID,
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
                '409' => [
                    'description' => self::ERROR_ILLEGAL_CONTENT,
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