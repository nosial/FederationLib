<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;
    use FederationLib\Objects\ScannedContent\ResolvedEntityPosition;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class ScanContent extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Scanning content is not available to the public, authentication is required';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to scan content, client permissions are required';
        private const string ERROR_EVIDENCE_REQUIRED = 'Evidence is required';
        private const string ERROR_EVIDENCE_INVALID = 'Evidence must be a single evidence object or an array of evidence objects';
        private const string ERROR_EVIDENCE_ITEM_INVALID = 'Each evidence entry must be an object';
        private const string ERROR_CONTENT_EMPTY = 'At least one evidence record must contain text content';
        private const string ERROR_FAILED_RESOLVE_AUTHOR = 'Failed to resolve author entity';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();

            if(!Configuration::getServerConfiguration()->isScanContentPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            if($authenticatedOperator !== null && !$authenticatedOperator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            // Get the parameters
            $authorIdentifier = FederationServer::getParameter('author');
            $evidenceInput = FederationServer::getParameter('evidence');
            $topK = FederationServer::getParameter('top_k');
            $threshold = FederationServer::getParameter('threshold');

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

            $hasContent = false;
            foreach($evidenceItems as $item)
            {
                if(isset($item['text_content']) && is_string($item['text_content']) && strlen($item['text_content']) > 0)
                {
                    $hasContent = true;
                    break;
                }
            }

            if(!$hasContent)
            {
                throw new RequestException(self::ERROR_CONTENT_EMPTY, HttpResponseCode::BAD_REQUEST);
            }

            // First, resolve the author entity
            $authorRecord = null;
            if(!empty($authorIdentifier))
            {
                try
                {
                    $authorRecord = self::resolveEntity($authorIdentifier);
                }
                catch (DatabaseOperationException $e)
                {
                    throw new RequestException(self::ERROR_FAILED_RESOLVE_AUTHOR, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
                }
            }

            $parsedThreshold = null;
            $parsedTopK = null;
            if($threshold !== null)
            {
                $parsedThreshold = (float)$threshold;
            }

            if($topK !== null)
            {
                $parsedTopK = (int)$topK;
            }

            // Process each evidence record individually
            $allResolvedEntities = [];

            foreach($evidenceItems as $item)
            {
                $textContent = isset($item['text_content']) && is_string($item['text_content']) ? $item['text_content'] : '';
                if(strlen($textContent) === 0)
                {
                    continue;
                }

                // Resolve any detected named entities from the text content
                foreach(NamedEntityType::extract($textContent) as $entityIdentifier => $entityPosition)
                {
                    try
                    {
                        $resolvedEntity = self::resolveEntity($entityIdentifier, $entityPosition);
                        if($resolvedEntity === null)
                        {
                            continue;
                        }

                        $allResolvedEntities[$resolvedEntity->getEntity()->getUuid()] = $resolvedEntity;
                    }
                    catch (DatabaseOperationException $e)
                    {
                        Logger::log()->warning('Failed to resolve ' . $entityIdentifier . ': ' . $e->getMessage(), $e);
                        continue;
                    }
                }
            }

            // Let the plugins scan the content, they may classify it, add their own scanning rules or reject the
            // request before anything about the scan is recorded
            $contentScan = new ContentScan(
                array_map(fn(array $item) => self::toContentInput($item), $evidenceItems),
                is_string($authorIdentifier) && $authorIdentifier !== '' ? $authorIdentifier : null,
                $authorRecord,
                array_values($allResolvedEntities),
                $authenticatedOperator,
                $parsedTopK,
                $parsedThreshold
            );
            PluginManager::dispatchContentScan($contentScan);

            // Return the scanned content, including the classifications and scanning rules provided by the plugins
            $scannedContent = new ScannedContent(
                array_values($allResolvedEntities),
                $authorRecord,
                $contentScan->getAddedClassifications(),
                $contentScan->getScanResults()
            );

            // Only authenticated clients contribute to reputation; anonymous scans must not affect it in any way
            if($authenticatedOperator !== null)
            {
                EntitiesManager::recordScan($scannedContent);
            }

            // Generate a report if auto-reporting is enabled for this scan's outcome.
            if(self::shouldGenerateReport($scannedContent))
            {
                try
                {
                    self::generateReport($scannedContent, $evidenceItems, $contentScan);
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->error('Failed to generate report: ' . $e->getMessage(), $e);
                }
            }

            self::successResponse($scannedContent->toStandardArray(!self::omitEntityMetadata()));
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
         * Converts a validated evidence item into a ContentInput object
         *
         * @param array $item The evidence item
         * @return ContentInput The evidence item as a ContentInput object
         */
        private static function toContentInput(array $item): ContentInput
        {
            return new ContentInput(
                isset($item['text_content']) && is_string($item['text_content']) ? $item['text_content'] : null,
                isset($item['note']) && is_string($item['note']) ? $item['note'] : null,
                isset($item['tag']) && is_string($item['tag']) ? $item['tag'] : null,
                isset($item['confidential']) && (filter_var($item['confidential'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false),
                isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : null
            );
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
         * Resolves the given entity identifier with the optional entity position, returning back a ResolvedEntity
         * object containing the resolved entity and active blacklist records
         *
         * @param string $entityIdentifier The target entity identifier
         * @param ResolvedEntityPosition|null $entityPosition Optional. The entity position
         * @return ResolvedEntity|null Returns the ResolvedEntity record, null if the record was not found
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function resolveEntity(string $entityIdentifier, ?ResolvedEntityPosition $entityPosition=null): ?ResolvedEntity
        {
            $entityRecord = EntitiesManager::resolveNamedEntity($entityIdentifier, $entityPosition);
            if($entityRecord === null)
            {
                return null;
            }

            $activeBlacklists = BlacklistManager::getEntriesByEntity($entityRecord->getUuid());

            // Optionally resolve the parent entity if a relationship is defined
            $parentResolvedEntity = null;
            $parentUuid = $entityRecord->getRelationshipEntity();
            if(!empty($parentUuid))
            {
                try
                {
                    $parentRecord = EntitiesManager::getEntityByUuid($parentUuid);
                    if($parentRecord !== null)
                    {
                        $parentResolvedEntity = new ResolvedEntity($parentRecord,
                            BlacklistManager::getEntriesByEntity($parentRecord->getUuid())
                        );
                    }
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning(sprintf('Failed to resolve parent entity %s for %s: %s', $parentUuid, $entityRecord->getUuid(), $e->getMessage()), $e);
                }
            }

            return new ResolvedEntity($entityRecord, $activeBlacklists, $entityPosition, $parentResolvedEntity);
        }

        /**
         * Determines whether a report should be generated for the scanned content. A scan qualifies when its risk
         * score reaches the auto-report threshold (auto_report), or when its suggested action is CAUTION
         * (auto_report_caution). Both conditions are evaluated in a single decision so a scan that satisfies both
         * only ever produces one report.
         *
         * @param ScannedContent $scannedContent The scanned content results
         * @return bool True if a report should be generated
         */
        private static function shouldGenerateReport(ScannedContent $scannedContent): bool
        {
            $scanningConfiguration = Configuration::getScanningConfiguration();

            if($scanningConfiguration->isAutoReport() && $scannedContent->getRiskScore() >= $scanningConfiguration->getAutoReportThreshold())
            {
                return true;
            }

            return $scanningConfiguration->isAutoReportCaution() && $scannedContent->getSuggestedAction() === SuggestedActionType::CAUTION;
        }

        /**
         * Generates a report based off the scanned content, the caller is responsible for deciding whether the
         * scanned content qualifies for a report (see shouldGenerateReport)
         *
         * @param ScannedContent $scannedContent The scanned content results
         * @param array<int, array> $evidenceItems The evidence items provided in the scan request
         * @param ContentScan|null $contentScan Optional. The content scan with the classifications provided by the plugins
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function generateReport(ScannedContent $scannedContent, array $evidenceItems, ?ContentScan $contentScan=null): void
        {
            // Do not generate if there's no author entity to blame
            if($scannedContent->getAuthorEntity() === null)
            {
                return;
            }

            // Do not generate the report if there is no operator eligible to be automatically assigned it
            if(!OperatorManager::autoAssignOperatorExists())
            {
                return;
            }

            // Generate the report message
            $reportMessage = "Automated Report\n";
            $hasScanResults = false;
            foreach($scannedContent->getScanResults() as $scanningRule => $value)
            {
                if($value == 0.0)
                {
                    continue;
                }

                if(!$hasScanResults)
                {
                    $reportMessage .= "\n";
                    $hasScanResults = true;
                }

                $reportMessage .= sprintf(" - %s: %+.2f points\n", $scanningRule, $value);
            }

            if($scannedContent->getClassification() !== null)
            {
                $reportMessage .= "\n" . $scannedContent->getClassification();
            }

            $suggestedAction = $scannedContent->getSuggestedAction();
            $reportMessage .= sprintf("\nSuggested Action: %s\nRisk Score: %.2f", $suggestedAction?->value ?? 'none', $scannedContent->getRiskScore());

            $systemOperator = OperatorManager::getSystemOperator();

            // Create the report, which is automatically assigned to the next eligible auto assign operator
            $reportUuid = ReportManager::createReport(
                submittingOperator: $systemOperator->getUuid(),
                reportingEntity: $scannedContent->getAuthorEntity()->getEntity()->getUuid(),
                type: IncidentType::SPAM,
                message: $reportMessage,
                automated: true
            );

            // Create an evidence record for each provided evidence item
            $firstEvidenceUuid = null;
            foreach($evidenceItems as $evidenceIndex => $item)
            {
                $textContent = isset($item['text_content']) && is_string($item['text_content']) ? $item['text_content'] : null;
                if($textContent === null || strlen($textContent) === 0)
                {
                    continue;
                }

                $note = isset($item['note']) && is_string($item['note']) ? $item['note'] : null;
                $tag = isset($item['tag']) && is_string($item['tag']) ? $item['tag'] : null;
                $confidential = isset($item['confidential'])
                    ? filter_var($item['confidential'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false
                    : false;
                $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : null;

                // Describe the evidence with the classification a plugin made for this evidence item during the scan
                $itemClassification = $contentScan?->getEvidenceClassifications($evidenceIndex)[0] ?? null;

                $evidenceMessage = $itemClassification !== null
                    ? (string)$itemClassification : sprintf("Risk Score: %f", $scannedContent->getRiskScore());

                $evidenceTag = $tag ?? $itemClassification?->getClassificationFlag()->value ?? $suggestedAction?->value ?? 'scan';
                $evidenceUuid = EvidenceManager::addEvidence(
                    entity: $scannedContent->getAuthorEntity()->getEntity()->getUuid(),
                    operator: $systemOperator->getUuid(),
                    textContent: $textContent,
                    note: $note ?? $evidenceMessage,
                    tag: $evidenceTag,
                    confidential: $confidential,
                    report: $reportUuid,
                    metadata: $metadata
                );

                if($firstEvidenceUuid === null)
                {
                    $firstEvidenceUuid = $evidenceUuid;
                }
            }

            // Create an audit log entry
            AuditLogManager::createEntry(
                type: AuditLogType::REPORT_GENERATED,
                message: sprintf('Generated report %s with a risk score of %f', $reportUuid, $scannedContent->getRiskScore()),
                operatorUuid: $systemOperator->getUuid(),
                entityUuid: $scannedContent->getAuthorEntity()->getEntity()->getUuid(),
                evidenceUuid: $firstEvidenceUuid
            );
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Scan'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Scan content';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Scans one or more content messages for entities, blacklist records, and classifies the content using the enabled plugins. Requires client permissions if authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'scanContent';
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
                                'author' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'UUID, SHA-256 hash, or entity address of the author',
                                ],
                                'evidence' => [
                                    'oneOf' => [
                                        ['$ref' => ContentInput::getReference()],
                                        [
                                            'type' => 'array',
                                            'description' => 'Multiple messages to scan',
                                            'items' => ['$ref' => ContentInput::getReference()],
                                        ],
                                    ],
                                ],
                                'top_k' => [
                                    'type' => ['integer', 'null'],
                                    'description' => 'Number of top classifications to return',
                                ],
                                'threshold' => [
                                    'type' => ['number', 'null'],
                                    'format' => 'float',
                                    'description' => 'Confidence threshold for classification',
                                ],
                            ],
                            'required' => ['evidence'],
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
                    'description' => 'Scanned content results',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ScannedContent::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_CONTENT_EMPTY,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_AUTHENTICATION_REQUIRED,
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
                    'description' => self::ERROR_FAILED_RESOLVE_AUTHOR,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
