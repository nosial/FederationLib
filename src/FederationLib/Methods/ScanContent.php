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
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;
    use FederationLib\Objects\ScannedContent\ResolvedEntityPosition;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use InvalidArgumentException;

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
            $allClassifications = [];

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

                // Use BayesianServer to detect the content classification level
                if(Configuration::getBayesianConfiguration()->isEnabled())
                {
                    try
                    {
                        $classification = self::classifyContent($textContent, $parsedThreshold, $parsedTopK);
                        if($classification !== null)
                        {
                            $allClassifications[] = $classification;
                        }
                    }
                    catch (RequestException $e)
                    {
                        Logger::log()->error('Classification Error: ' . $e->getMessage(), $e);
                    }
                }
            }

            // Return the scanned content
            $scannedContent = new ScannedContent(
                array_values($allResolvedEntities),
                $authorRecord,
                $allClassifications
            );

            // Record the scan result into the open reputation window for every involved entity
            EntitiesManager::recordScan($scannedContent);

            // Generate a report if auto-reporting is enabled.
            if(Configuration::getScanningConfiguration()->isAutoReport())
            {
                try
                {
                    self::generateReport($scannedContent, $evidenceItems);
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->error('Failed to generate report: ' . $e->getMessage(), $e);
                }
            }

            self::successResponse($scannedContent->toStandardArray(!self::omitEntityMetadata()));
        }

        /**
         * Classifies the content and returns the ContentClassification object if the classification succeeds
         *
         * @param string $content The content to classify
         * @param float|null $threshold Optional. Confidence threshold
         * @param int|null $topK Optional. The number of choices to limit to
         * @return ContentClassification|null The classification result, null if the content cannot be classified at the moment
         * @throws RequestException Thrown if BayesianClient fails to send a request to BayesianServer
         */
        private static function classifyContent(string $content, ?float $threshold, ?int $topK): ?ContentClassification
        {
            $serverStatus = FederationServer::getBayesianClient()->getStatus();

            // If we have less than 10 training documents, we skip the classification
            if($serverStatus->getModel()->getTotalDocuments() < 10)
            {
                Logger::log()->warning('Skipping classification, not enough training documents');
                return null;
            }

            // Verify that we have all labels before running a classification call
            foreach($serverStatus->getModel()->getLabels() as $labelStatistic)
            {
                $classificationFlag = ClassificationFlag::tryFrom($labelStatistic->getLabel());

                // Avoid classifying on malformed models, could lead to massive incorrect predictions
                if($classificationFlag === null)
                {
                    Logger::log()->error('Malformed Bayesian model, unknown label: ' . $labelStatistic->getLabel() . '. A new model needs to be created');
                    return null;
                }

                // Allow for labels to have enough training documents to reasonably classify
                if($labelStatistic->getDocumentCount() < 10)
                {
                    Logger::log()->warning('Skipping classification, not enough training documents for ' . $labelStatistic->getLabel());
                    return null;
                }
            }

            // Avoid classification if we didn't identify all labels yet
            if($serverStatus->getModel()->getLabelCount() !== 3)
            {
                Logger::log()->warning('Skipping classification, not enough training data');
                return null;
            }

            $bayesianClassification = FederationServer::getBayesianClient()->classify($content, $topK, $threshold);

            // If we want to only classify content for known tokens
            if(Configuration::getBayesianConfiguration()->classifyKnownTokens())
            {
                // Return null if the number of unknown tokens is greater than the recognized tokens
                if($bayesianClassification->getUnknownTokenCount() > $bayesianClassification->getKnownTokens())
                {
                    Logger::log()->warning('Skipping classification, too many unknown tokens');
                    return null;
                }
            }

            return new ContentClassification(
                ClassificationFlag::from($bayesianClassification->getTopLabel()),
                $bayesianClassification->getConfidence(),
                $bayesianClassification->getLanguageCode()
            );
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
            if(strlen($entityIdentifier) < 1)
            {
                return null;
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
                $entityRecord = self::resolveEntityByIdentifier($entityIdentifier, $entityPosition);
            }

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
         * Resolves a raw named-entity identifier (domain, URL, email, IPv4 or IPv6) to an EntityRecord by hashing
         * the canonical host (and optional id) the same way pushEntity stores it.
         *
         * @param string $entityIdentifier The raw identifier extracted from the content
         * @param ResolvedEntityPosition|null $entityPosition Optional position metadata carrying the entity type
         * @return EntityRecord|null The matching entity record, or null if none exists
         * @throws DatabaseOperationException Thrown if there was a database exception
         */
        private static function resolveEntityByIdentifier(string $entityIdentifier, ?ResolvedEntityPosition $entityPosition=null): ?EntityRecord
        {
            $host = null;
            $id = null;

            if($entityPosition !== null)
            {
                $type = $entityPosition->getType();

                switch($type)
                {
                    case NamedEntityType::URL:
                        $host = parse_url($entityIdentifier, PHP_URL_HOST);
                        break;

                    case NamedEntityType::EMAIL:
                        $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                        if($parsedAddress !== null)
                        {
                            $host = $parsedAddress['host'];
                            $id = $parsedAddress['id'];
                        }
                        break;

                    case NamedEntityType::DOMAIN:
                    case NamedEntityType::IPv4:
                    case NamedEntityType::IPv6:
                        $host = $entityIdentifier;
                        break;
                }
            }
            else
            {
                if(Utilities::isEntityAddress($entityIdentifier))
                {
                    $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                    $host = $parsedAddress['host'];
                    $id = $parsedAddress['id'];
                }
                elseif(Validate::url($entityIdentifier))
                {
                    $host = parse_url($entityIdentifier, PHP_URL_HOST);
                }
                elseif(Validate::domain($entityIdentifier) || Validate::ipv4($entityIdentifier) || Validate::ipv6($entityIdentifier))
                {
                    $host = $entityIdentifier;
                }
            }

            if($host === null || $host === '')
            {
                return null;
            }

            try
            {
                return EntitiesManager::getEntityByHash(Utilities::hashEntity($host, $id));
            }
            catch (InvalidArgumentException $e)
            {
                Logger::log()->warning('Failed to resolve entity by identifier ' . $entityIdentifier . ': ' . $e->getMessage(), $e);
                return null;
            }
        }

        /**
         * Generates a report based off the scanned content, returns the created report UUID record otherwise returns
         * null if auto-reporting conditions are not met
         *
         * @param ScannedContent $scannedContent The scanned content results
         * @param array<int, array> $evidenceItems The evidence items provided in the scan request
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function generateReport(ScannedContent $scannedContent, array $evidenceItems): void
        {
            // Do not generate the report if it's less than the required threshold
            if($scannedContent->getRiskScore() < Configuration::getScanningConfiguration()->getAutoReportThreshold())
            {
                return;
            }

            // Do not generate if there's no author entity to blame
            if($scannedContent->getAuthorEntity() === null)
            {
                return;
            }

            // Do not generate the report if there is no operator eligible to be automatically assigned it
            $assignedOperator = OperatorManager::getRandomAutoAssignOperator();
            if($assignedOperator === null)
            {
                return;
            }

            // Generate the report message
            $reportMessage = "Automated Report\n";
            if(count($scannedContent->getScanResults()) > 0)
            {
                $reportMessage .= "\n";
                foreach($scannedContent->getScanResults() as $scanningRule => $value)
                {
                    $reportMessage .= sprintf(" - %s: %f%%\n", $scanningRule, $value);
                }
            }

            if($scannedContent->getClassification() !== null)
            {
                $reportMessage .= "\n" . $scannedContent->getClassification();
            }

            $suggestedAction = $scannedContent->getSuggestedAction();
            $reportMessage .= sprintf("\nSuggested Action: %s\nRisk Score: %f", $suggestedAction?->value ?? 'none', $scannedContent->getRiskScore());

            $systemOperator = OperatorManager::getSystemOperator();

            // Create the report
            $reportUuid = ReportManager::createReport(
                submittingOperator: $systemOperator->getUuid(),
                reportingEntity: null,
                type: IncidentType::SPAM,
                message: $reportMessage,
                automated: true
            );

            // Assign the report to the randomly selected auto assign operator
            ReportManager::assignOperator($reportUuid, $assignedOperator->getUuid());

            // Create an evidence record for each provided evidence item
            $firstEvidenceUuid = null;
            foreach($evidenceItems as $item)
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

                // Classify the individual content for the evidence tag/note
                $itemClassification = null;
                if(Configuration::getBayesianConfiguration()->isEnabled())
                {
                    try
                    {
                        $itemClassification = self::classifyContent($textContent, null, null);
                    }
                    catch (RequestException $e)
                    {
                        Logger::log()->error('Failed to classify evidence content: ' . $e->getMessage(), $e);
                    }
                }

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
            return 'Scans one or more content messages for entities, blacklist records, and classifies the content using Bayesian analysis. Requires client permissions if authenticated.';
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
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the author',
                                    'nullable' => true,
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
                                    'type' => 'integer',
                                    'description' => 'Number of top classifications to return',
                                    'nullable' => true,
                                ],
                                'threshold' => [
                                    'type' => 'number',
                                    'format' => 'float',
                                    'description' => 'Confidence threshold for classification',
                                    'nullable' => true,
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
