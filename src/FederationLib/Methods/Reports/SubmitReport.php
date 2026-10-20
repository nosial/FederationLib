<?php

    namespace FederationLib\Methods\Reports;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\ReportSubmission;
    use InvalidArgumentException;
    use Throwable;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class SubmitReport extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'You do not have permission to create reports';
        private const string ERROR_ENTITY_IDENTIFIER_REQUIRED = 'Reporting entity identifier is required';
        private const string ERROR_EVIDENCE_REQUIRED = 'Evidence is required';
        private const string ERROR_EVIDENCE_INVALID = 'Evidence must be a single evidence object or an array of evidence objects';
        private const string ERROR_INVALID_TYPE = 'Invalid incident type';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_FAILED_RETRIEVE_ENTITY = 'Failed to retrieve entity record';
        private const string ERROR_ENTITY_NOT_FOUND = 'Reporting entity not found';
        private const string ERROR_FAILED_SUBMISSION = 'Failed to create report submission';
        private const string ERROR_FAILED_GET_REPORT = 'Failed to get report information';
        private const string ERROR_ILLEGAL_CONTENT_NOT_PERMITTED = 'Reports of type ILLEGAL_CONTENT are not permitted on this server';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            $entityIdentifier = FederationServer::getStringParameter('reporting_entity');
            if($entityIdentifier === null)
            {
                throw new RequestException(self::ERROR_ENTITY_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $evidenceInput = FederationServer::getParameter('evidence');
            if(!is_array($evidenceInput) || empty($evidenceInput))
            {
                throw new RequestException(self::ERROR_EVIDENCE_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $evidenceItems = self::normalizeEvidence($evidenceInput);
            if($evidenceItems === null)
            {
                throw new RequestException(self::ERROR_EVIDENCE_INVALID, HttpResponseCode::BAD_REQUEST);
            }

            // Each evidence entry is a ContentInput object, validated with its content type and context
            $contentInputs = [];
            foreach($evidenceItems as $index => $item)
            {
                try
                {
                    $contentInputs[] = ContentInput::fromRequest($item);
                }
                catch(InvalidArgumentException $e)
                {
                    throw new RequestException(sprintf('%s at index %d', $e->getMessage(), $index), HttpResponseCode::BAD_REQUEST, $e);
                }
            }

            $incidentType = FederationServer::getStringParameter('incident_type');
            if(!is_string($incidentType))
            {
                throw new RequestException(self::ERROR_INVALID_TYPE, HttpResponseCode::BAD_REQUEST);
            }
            $incidentType = IncidentType::tryFromCaseInsensitive($incidentType);
            if($incidentType === null)
            {
                throw new RequestException(self::ERROR_INVALID_TYPE, HttpResponseCode::BAD_REQUEST);
            }

            if($incidentType === IncidentType::ILLEGAL_CONTENT && !Configuration::getServerConfiguration()->isIllegalContentAllowed())
            {
                throw new RequestException(self::ERROR_ILLEGAL_CONTENT_NOT_PERMITTED, HttpResponseCode::FORBIDDEN);
            }

            $reportMessage = FederationServer::getStringParameter('report_message');
            if(empty((string)$reportMessage))
            {
                $reportMessage = null;
            }

            try
            {
                $entityRecord = self::resolveEntityIdentifier($entityIdentifier, self::ERROR_INVALID_IDENTIFIER);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_RETRIEVE_ENTITY, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            if($entityRecord === null)
            {
                throw new RequestException(self::ERROR_ENTITY_NOT_FOUND, HttpResponseCode::NOT_FOUND);
            }

            try
            {
                // Submit the report
                $reportUuid = ReportManager::createReport(
                    submittingOperator: $authenticatedOperator->getUuid(),
                    reportingEntity: $entityRecord->getUuid(),
                    type: $incidentType,
                    message: $reportMessage ?? null,
                    // A report submitted by a bot operator is automated, like the reports the host creates itself
                    automated: $authenticatedOperator->isBot()
                );

                // Create the evidence records
                $evidenceRecords = [];
                foreach($contentInputs as $contentInput)
                {
                    // Evidence for ILLEGAL_CONTENT reports is always confidential regardless of the submitted value,
                    // and EvidenceManager makes evidence about a telephone entity confidential
                    $evidenceUuid = EvidenceManager::addEvidence(
                        entity: $entityRecord->getUuid(),
                        operator: $authenticatedOperator->getUuid(),
                        textContent: $contentInput->getTextContent(),
                        note: $contentInput->getNote(),
                        tag: $contentInput->getTag(),
                        confidential: $incidentType === IncidentType::ILLEGAL_CONTENT || $contentInput->isConfidential(),
                        report: $reportUuid,
                        metadata: $contentInput->getMetadata(),
                        contentType: $contentInput->getContentType(),
                        context: $contentInput->getContext(),
                        automated: $authenticatedOperator->isBot()
                    );

                    $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                    if($evidenceRecord !== null)
                    {
                        $evidenceRecords[] = $evidenceRecord;
                    }
                }

                if(!empty($evidenceRecords))
                {
                    AuditLogManager::createEntry(
                        type: AuditLogType::REPORT_SUBMITTED,
                        message: $reportMessage ?? 'No message provided',
                        operatorUuid: $authenticatedOperator->getUuid(),
                        entityUuid: $entityRecord->getUuid(),
                        evidenceUuid: $evidenceRecords[0]->getUuid(),
                        reportUuid: $reportUuid
                    );
                }
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), HttpResponseCode::BAD_REQUEST, $e);
            }
            catch(Throwable $e)
            {
                throw new RequestException(self::ERROR_FAILED_SUBMISSION, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            try
            {
                self::successResponse(new ReportSubmission(
                    ReportManager::getReport($reportUuid),
                    $evidenceRecords
                ));
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_GET_REPORT, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }
        }

        /**
         * Normalizes the evidence input into a list of evidence parameter arrays.
         *
         * @param array $evidenceInput The raw evidence parameter from the request
         * @return array<int, array>|null A list of evidence arrays, or null if invalid
         */
        private static function normalizeEvidence(array $evidenceInput): ?array
        {
            if(array_is_list($evidenceInput))
            {
                if (array_any($evidenceInput, fn($item) => !is_array($item)))
                {
                    return null;
                }

                return $evidenceInput;
            }

            return [$evidenceInput];
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
            return 'Submit a report';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Creates a new report with one or more evidence records. File attachments can be added to the created evidence records afterwards. Evidence submitted with an ILLEGAL_CONTENT report is always marked as confidential, and servers may reject ILLEGAL_CONTENT reports entirely. Requires client permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'submitReport';
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
                                'reporting_entity' => [
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the entity being reported',
                                ],
                                'evidence' => [
                                    'oneOf' => [
                                        ['$ref' => ContentInput::getReference()],
                                        [
                                            'type' => 'array',
                                            'description' => 'Multiple evidence records to create with the report',
                                            'items' => ['$ref' => ContentInput::getReference()],
                                        ],
                                    ],
                                ],
                                'incident_type' => [
                                    'type' => 'string',
                                    'description' => 'The type of incident being reported',
                                    'enum' => [...array_map(fn(IncidentType $type) => $type->value, IncidentType::cases())],
                                ],
                                'report_message' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Optional message for the report',
                                ],
                            ],
                            'required' => ['reporting_entity', 'evidence', 'incident_type'],
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
                    'description' => 'Report submitted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ReportSubmission::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_ENTITY_IDENTIFIER_REQUIRED,
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
                    'description' => self::ERROR_INSUFFICIENT_PERMISSIONS . ', or ' . self::ERROR_ILLEGAL_CONTENT_NOT_PERMITTED,
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
                    'description' => self::ERROR_FAILED_SUBMISSION,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
