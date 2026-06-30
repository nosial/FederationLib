<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\UploadHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;
    use FederationLib\Objects\ScannedContent\ResolvedEntityPosition;
    use FederationLib\Objects\UploadResult;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use Throwable;

    class ScanContent extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Scanning content is not available to the public, authentication is required';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to scan content, client permissions are required';
        private const string ERROR_CONTENT_EMPTY = 'Content cannot be empty';
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
            $content = FederationServer::getParameter('content');
            $topK = FederationServer::getParameter('top_k');
            $threshold = FederationServer::getParameter('threshold');

            if(empty($content))
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

            // Resolve any detected named entities from the text content (eg; domains, email addresses, etc)
            $resolvedEntities = [];
            foreach(NamedEntityType::extract($content) as $entityIdentifier => $entityPosition)
            {
                try
                {
                    $resolvedEntity = self::resolveEntity($entityIdentifier, $entityPosition);
                    if($resolvedEntity === null)
                    {
                        continue;
                    }

                    $resolvedEntities[] = $resolvedEntity;
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning('Failed to resolve ' . $entityIdentifier . ': ' . $e->getMessage(), $e);
                    continue;
                }
            }

            // Use BayesianServer to detect the content classification level
            $contentClassification = null;
            if(Configuration::getBayesianConfiguration()->isEnabled())
            {
                if($threshold !== null)
                {
                    $threshold = (float)$threshold;
                }

                if($topK !== null)
                {
                    $topK = (int)$topK;
                }

                // Classify the content
                try
                {
                    $contentClassification = self::classifyContent($contentClassification, $threshold, $topK);
                }
                catch (RequestException $e)
                {
                    Logger::log()->error('Classification Error: ' . $e->getMessage(), $e);
                }
            }

            // Read optional metadata for evidence
            $metadata = FederationServer::getParameter('metadata');
            if($metadata !== null && !is_array($metadata))
            {
                $parsedMetadata = json_decode($metadata, true);
                if(json_last_error() === JSON_ERROR_NONE && is_array($parsedMetadata))
                {
                    $metadata = $parsedMetadata;
                }
                else
                {
                    $metadata = null;
                }
            }

            // Return the scanned content
            $scannedContent = new ScannedContent($resolvedEntities, $authorRecord, $contentClassification);

            // Record the scan result into the open reputation window for every involved entity
            EntitiesManager::recordScan($scannedContent);

            // Generate a report if auto-reporting is enabled.
            if(Configuration::getScanningConfiguration()->isAutoReport())
            {
                try
                {
                    self::generateReport($scannedContent, $content, $metadata);
                }
                catch (DatabaseOperationException|RequestException $e)
                {
                    Logger::log()->error('Failed to generate report: ' . $e->getMessage(), $e);
                }
            }

            self::successResponse($scannedContent);
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

            // Classify the content
            $bayesianClassification = FederationServer::getBayesianClient()->classify($content, $topK, $threshold);
            return new ContentClassification(
                ClassificationFlag::from($bayesianClassification->getTopLabel()),
                $bayesianClassification->getConfidence(),
                $bayesianClassification->getLanguageCode()
            );
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
                return null;
            }

            if($entityRecord === null)
            {
                return null;
            }

            $activeBlacklists = BlacklistManager::getEntriesByEntity($entityRecord->getUuid());

            // Optionally resolve the parent entity if a relationship is defined
            $parentResolvedEntity = null;
            $parentUuid = $entityRecord->getRelationshipEntity();
            if($parentUuid !== null)
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
         * Generates a report based off the scanned content, returns the created report UUID record otherwise returns
         * null if auto-reporting conditions are not met
         *
         * @param ScannedContent $scannedContent The scanned content results
         * @param string $content The text input content
         * @param array|null $metadata Optional metadata to associate with the evidence record
         * @throws DatabaseOperationException Thrown if there was a databsae operation error
         * @throws RequestException Thrown if the file upload validation fails
         */
        private static function generateReport(ScannedContent $scannedContent, string $content, ?array $metadata=null): void
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

            // Generate the report message
            $reportMessage = "Automated Report\n";
            if(count($scannedContent->getScanResults()) > 0)
            {
                $reportMessage .= "\n";
                foreach($scannedContent->getScanResults() as $scanningRule => $value)
                {
                    $reportMessage .= sprintf(' - %s: %f%%\n', $scanningRule, $value);
                }
            }
            if($scannedContent->getClassification() !== null)
            {
                $reportMessage .= "\n" . $scannedContent->getClassification();
            }
            $reportMessage .= sprintf("\nSuggested Action: %s\nRisk Score: %f", $scannedContent->getSuggestedAction()->value, $scannedContent->getRiskScore());

            // Generate the evidence message
            if($scannedContent->getClassification() !== null)
            {
                $evidenceMessage = (string)$scannedContent->getClassification();
            }
            else
            {
                $evidenceMessage = sprintf("Risk Score: %f", $scannedContent->getRiskScore());
            }

            $systemOperator = OperatorManager::getSystemOperator();

            // Create the report
            $reportUuid = ReportManager::createReport(
                submittingOperator: $systemOperator->getUuid(),
                reportingEntity: null,
                type: IncidentType::SPAM,
                message: $reportMessage,
                automated: true
            );

            // Create the evidence
            $evidenceUuid = EvidenceManager::addEvidence(
                entity: $scannedContent->getAuthorEntity()->getEntity()->getUuid(),
                operator: $systemOperator->getUuid(),
                textContent: $content,
                note: $evidenceMessage,
                tag: $scannedContent->getClassification()?->getClassificationFlag()->value ?? $scannedContent->getSuggestedAction()->value,
                report: $reportUuid,
                metadata: $metadata
            );

            // Handle the optional file attachment uploads, referencing the evidence uuid for the upload
            $uploadResults = [];
            if(isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE)
            {
                $uploadInfo = UploadHandler::validateUpload();
                try
                {
                    UploadHandler::finalizeUpload($uploadInfo);
                    FileAttachmentManager::createRecord(
                        uuid: $uploadInfo['uuid'],
                        evidence: $evidenceUuid,
                        fileMime: $uploadInfo['mime_type'],
                        fileName: $uploadInfo['original_name'],
                        fileSize: $uploadInfo['size']
                    );

                    $uploadResults[] = new UploadResult($uploadInfo['uuid'],
                        Configuration::getServerConfiguration()->getBaseUrl() . '/attachments/' . $uploadInfo['uuid']
                    );
                }
                catch (Throwable $e)
                {
                    if(file_exists($uploadInfo['destination_path']))
                    {
                        @unlink($uploadInfo['destination_path']);
                    }

                    Logger::log()->error(sprintf('Failed to process file upload for evidence %s: %s', $evidenceUuid, $e->getMessage()), $e);
                }
                finally
                {
                    UploadHandler::cleanupTempFiles($uploadInfo);
                }
            }

            $fileAttachmentUuid = !empty($uploadResults) ? $uploadResults[0]->getUuid() : null;

            // Create an audit log entry
            AuditLogManager::createEntry(
                type: AuditLogType::REPORT_GENERATED,
                message: sprintf('Generated report %s with a risk score of %f', $reportUuid, $scannedContent->getRiskScore()),
                operatorUuid: $systemOperator->getUuid(),
                entityUuid: $scannedContent->getAuthorEntity()->getEntity()->getUuid(),
                evidenceUuid: $evidenceUuid,
                fileAttachmentUuid: $fileAttachmentUuid
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
            return 'Scans content for entities, blacklist records, and classifies the content using Bayesian analysis. Requires client permissions if authenticated.';
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
                                'content' => [
                                    'type' => 'string',
                                    'description' => 'The content to scan',
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
                                'metadata' => [
                                    'type' => 'object',
                                    'description' => 'Optional metadata to associate with the evidence record',
                                    'nullable' => true,
                                ],
                            ],
                            'required' => ['content'],
                        ],
                    ],
                    'multipart/form-data' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'author' => [
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the author',
                                    'nullable' => true,
                                ],
                                'content' => [
                                    'type' => 'string',
                                    'description' => 'The content to scan',
                                ],
                                'metadata' => [
                                    'type' => 'string',
                                    'description' => 'Optional metadata to associate with the evidence record (JSON-encoded object)',
                                    'nullable' => true,
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
                                'file' => [
                                    'type' => 'string',
                                    'format' => 'binary',
                                    'description' => 'Optional file attachment to associate with the evidence record',
                                ],
                            ],
                            'required' => ['content'],
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
