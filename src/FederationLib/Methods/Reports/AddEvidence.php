<?php

    namespace FederationLib\Methods\Reports;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;
    use InvalidArgumentException;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class AddEvidence extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage reports';
        private const string ERROR_EVIDENCE_UUID_REQUIRED = 'Evidence UUID is required';
        private const string ERROR_INVALID_EVIDENCE_UUID = 'Invalid evidence UUID';
        private const string ERROR_REPORT_UUID_REQUIRED = 'A valid report UUID is required';
        private const string ERROR_EVIDENCE_NOT_FOUND = 'Evidence not found';
        private const string ERROR_UNABLE_TO_LINK = 'Unable to link evidence to report';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36})/link_report$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_EVIDENCE_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $evidenceUuid = $matches[1];
            if(!Validate::uuid($evidenceUuid))
            {
                throw new RequestException(self::ERROR_INVALID_EVIDENCE_UUID, HttpResponseCode::BAD_REQUEST);
            }

            $reportUuid = FederationServer::getParameter('report_uuid');
            if($reportUuid === null || !Validate::uuid($reportUuid))
            {
                throw new RequestException(self::ERROR_REPORT_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                if($evidenceRecord === null)
                {
                    throw new RequestException(self::ERROR_EVIDENCE_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                EvidenceManager::updateEvidenceReport($evidenceUuid, $reportUuid);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_UPDATED, sprintf(
                    'Evidence %s linked to report %s by %s',
                    $evidenceUuid,
                    $reportUuid,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $evidenceRecord->getEntityUuid(), null, $evidenceUuid);
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), HttpResponseCode::BAD_REQUEST, $e);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_LINK, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse();
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Reports'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Add evidence to a report';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Links an existing evidence record to a report. Requires operator management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'addEvidenceToReport';
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
                    'description' => self::ERROR_EVIDENCE_UUID_REQUIRED,
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
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
                                'report_uuid' => [
                                    'type' => 'string',
                                    'format' => 'uuid',
                                    'description' => 'UUID of the report to link the evidence to',
                                ],
                            ],
                            'required' => ['report_uuid'],
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
                    'description' => 'Evidence linked to report successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_EVIDENCE_UUID,
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
                    'description' => self::ERROR_EVIDENCE_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_LINK,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
