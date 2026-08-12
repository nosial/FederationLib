<?php

    namespace FederationLib\Methods\Reports;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
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
        private const string ERROR_EVIDENCE_ITEM_INVALID = 'Each evidence entry must be an object';
        private const string ERROR_INVALID_TYPE = 'Invalid incident type';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_FAILED_RETRIEVE_ENTITY = 'Failed to retrieve entity record';
        private const string ERROR_ENTITY_NOT_FOUND = 'Reporting entity not found';
        private const string ERROR_FAILED_SUBMISSION = 'Failed to create report submission';
        private const string ERROR_FAILED_GET_REPORT = 'Failed to get report information';

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

            $entityIdentifier = FederationServer::getParameter('reporting_entity');
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

            foreach($evidenceItems as $index => $item)
            {
                if(!self::validateEvidenceItem($item))
                {
                    throw new RequestException(self::ERROR_EVIDENCE_ITEM_INVALID . ' at index ' . $index, HttpResponseCode::BAD_REQUEST);
                }
            }

            $incidentType = FederationServer::getParameter('incident_type');
            if(!is_string($incidentType))
            {
                throw new RequestException(self::ERROR_INVALID_TYPE, HttpResponseCode::BAD_REQUEST);
            }
            $incidentType = IncidentType::tryFromCaseInsensitive($incidentType);
            if($incidentType === null)
            {
                throw new RequestException(self::ERROR_INVALID_TYPE, HttpResponseCode::BAD_REQUEST);
            }

            $reportMessage = FederationServer::getParameter('report_message');
            if(empty((string)$reportMessage))
            {
                $reportMessage = null;
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
                    throw new RequestException(self::ERROR_INVALID_IDENTIFIER, HttpResponseCode::BAD_REQUEST);
                }
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
                    message: $reportMessage ?? null
                );

                ReportManager::assignOperator($reportUuid, $authenticatedOperator->getUuid());

                // Create the evidence records
                $evidenceRecords = [];
                foreach($evidenceItems as $item)
                {
                    $textContent = isset($item['text_content']) && is_string($item['text_content']) ? $item['text_content'] : null;
                    $note = isset($item['note']) && is_string($item['note']) ? $item['note'] : null;
                    $tag = isset($item['tag']) && is_string($item['tag']) ? $item['tag'] : null;
                    $confidential = isset($item['confidential'])
                        ? filter_var($item['confidential'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false
                        : false;
                    $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : null;

                    $evidenceUuid = EvidenceManager::addEvidence(
                        entity: $entityRecord->getUuid(),
                        operator: $authenticatedOperator->getUuid(),
                        textContent: $textContent,
                        note: $note,
                        tag: $tag,
                        confidential: $confidential,
                        report: $reportUuid,
                        metadata: $metadata
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
                        evidenceUuid: $evidenceRecords[0]->getUuid()
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
         * Validates that an evidence item contains only expected fields with valid types.
         *
         * @param array $item The evidence item to validate
         * @return bool True if valid, false otherwise
         */
        private static function validateEvidenceItem(array $item): bool
        {
            if (array_any(array_keys($item), fn($key) => !in_array($key, ['text_content', 'note', 'tag', 'confidential', 'metadata'], true)))
            {
                return false;
            }

            if(isset($item['text_content']) && !is_string($item['text_content']))
            {
                return false;
            }

            if(isset($item['note']) && !is_string($item['note']))
            {
                return false;
            }

            if(isset($item['tag']) && !is_string($item['tag']))
            {
                return false;
            }

            if(isset($item['confidential']) && !is_bool($item['confidential']) && !is_string($item['confidential']) && !is_int($item['confidential']))
            {
                return false;
            }

            if(isset($item['metadata']) && !is_array($item['metadata']))
            {
                return false;
            }

            return true;
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
            return 'Creates a new report with one or more evidence records. File attachments can be added to the created evidence records afterwards. Requires client permissions.';
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
            $evidenceSchema = [
                'type' => 'object',
                'properties' => [
                    'text_content' => [
                        'type' => 'string',
                        'description' => 'Text content of the evidence',
                        'nullable' => true,
                    ],
                    'note' => [
                        'type' => 'string',
                        'description' => 'Optional note by the operator',
                        'nullable' => true,
                    ],
                    'tag' => [
                        'type' => 'string',
                        'description' => 'Optional tag name for the evidence',
                        'nullable' => true,
                    ],
                    'confidential' => [
                        'type' => 'boolean',
                        'description' => 'Whether the evidence is confidential',
                        'default' => false,
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Arbitrary JSON-encoded metadata',
                        'nullable' => true,
                    ],
                ],
            ];

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
                                        [
                                            'type' => 'object',
                                            'description' => 'Single evidence record to create with the report',
                                            'properties' => $evidenceSchema['properties'],
                                        ],
                                        [
                                            'type' => 'array',
                                            'description' => 'Multiple evidence records to create with the report',
                                            'items' => $evidenceSchema,
                                        ],
                                    ],
                                ],
                                'incident_type' => [
                                    'type' => 'string',
                                    'description' => 'The type of incident being reported',
                                    'enum' => ['SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'OTHER'],
                                ],
                                'report_message' => [
                                    'type' => 'string',
                                    'description' => 'Optional message for the report',
                                    'nullable' => true,
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
