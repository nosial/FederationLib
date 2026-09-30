<?php

    namespace FederationLib\Methods\Reports;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\SuccessResponse;
    use InvalidArgumentException;

    class CloseReport extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage reports';
        private const string ERROR_INVALID_CLASSIFICATION = 'Invalid classification flag';
        private const string ERROR_INVALID_BLACKLIST_TYPE = 'Invalid blacklist incident type';
        private const string ERROR_INVALID_UUID = 'Invalid report UUID';
        private const string ERROR_NOT_ASSIGNED = 'Report not assigned to operator';
        private const string ERROR_ALREADY_CLOSED = 'The report is already closed';
        private const string ERROR_FAILED_TO_GET = 'Failed to get report record';
        private const string ERROR_FAILED_UPDATE_CLASSIFICATION = 'Failed to update classification flag for evidence record';
        private const string ERROR_FAILED_CLOSE_REPORT = 'Failed to close the requested report';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            // Get the parameters
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!preg_match('#^/reports/([a-fA-F0-9\-]{36})/close$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            $reportUuid = $matches[1];
            $classificationFlag = FederationServer::getParameter('classification_flag');
            $blacklistIncidentType = FederationServer::getParameter('blacklist_incident_type');
            $blacklistExpires = FederationServer::getParameter('blacklist_expires');

            // Get the report
            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            // Check the classification flag
            if(!empty($classificationFlag))
            {
                $classificationFlag = ClassificationFlag::tryFromCaseInsensitive($classificationFlag);
                if($classificationFlag === null)
                {
                    throw new RequestException(self::ERROR_INVALID_CLASSIFICATION, HttpResponseCode::BAD_REQUEST);
                }
            }
            else
            {
                $classificationFlag = null;
            }

            // Validate blacklist parameters if provided
            $blacklistType = null;
            if(!empty($blacklistIncidentType))
            {
                $blacklistType = IncidentType::tryFromCaseInsensitive($blacklistIncidentType);
                if($blacklistType === null)
                {
                    throw new RequestException(self::ERROR_INVALID_BLACKLIST_TYPE, HttpResponseCode::BAD_REQUEST);
                }
            }

            if($blacklistExpires !== null && $blacklistExpires !== '')
            {
                $blacklistExpires = (int)$blacklistExpires;
                if($blacklistExpires < time())
                {
                    throw new RequestException('Blacklist expiration must be in the future', HttpResponseCode::BAD_REQUEST);
                }
            }
            else
            {
                $blacklistExpires = null;
            }

            // Get the report
            if(!$reportUuid || !Validate::uuid($reportUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $reportRecord = ReportManager::getReport($reportUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_TO_GET, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            if($reportRecord === null)
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::NOT_FOUND);
            }

            // Prevent report management by non-assigned operator
            if($reportRecord->getAssignedOperator() !== $authenticatedOperator->getUuid())
            {
                throw new RequestException(self::ERROR_NOT_ASSIGNED, HttpResponseCode::BAD_REQUEST);
            }

            // Prevent report management of closed reports
            if(!$reportRecord->isOpened())
            {
                throw new RequestException(self::ERROR_ALREADY_CLOSED, HttpResponseCode::BAD_REQUEST);
            }

            // Assign classifications before training. The conditional database update
            // preserves immutable classifications and prevents duplicate training.
            $evidenceRecords = [];
            if($classificationFlag !== null)
            {
                try
                {
                    $evidenceRecords = EvidenceManager::getEvidenceByReport($reportUuid, includeConfidential: true);
                }
                catch(DatabaseOperationException $e)
                {
                    throw new RequestException(self::ERROR_FAILED_TO_GET, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
                }

                foreach($evidenceRecords as $evidenceRecord)
                {
                    try
                    {
                        if(!EvidenceManager::updateClassificationFlag($evidenceRecord->getUuid(), $classificationFlag))
                        {
                            continue;
                        }
                    }
                    catch(InvalidArgumentException $e)
                    {
                        throw new RequestException($e->getMessage(), HttpResponseCode::BAD_REQUEST, $e);
                    }
                    catch(DatabaseOperationException $e)
                    {
                        throw new RequestException(self::ERROR_FAILED_UPDATE_CLASSIFICATION, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
                    }

                    if(($bayesianClient = FederationServer::getBayesianClient()) !== null && $evidenceRecord->getTextContent() !== null)
                    {
                        try
                        {
                            $bayesianClient->learn($evidenceRecord->getTextContent(), $classificationFlag->value);
                        }
                        catch(RequestException $e)
                        {
                            Logger::log()->warning('Bayesian learn failed: ' . $e->getMessage());
                        }
                    }
                }
            }

            try
            {
                ReportManager::closeReport($reportUuid);

                if($classificationFlag !== null)
                {
                    self::applyReputation($reportRecord->getReportingEntity(), $classificationFlag, $evidenceRecords);
                }

                if($blacklistType !== null && $reportRecord->getReportingEntity() !== null)
                {
                    BlacklistManager::blacklistEntity(
                        entityUuid: $reportRecord->getReportingEntity(),
                        operatorUuid: $authenticatedOperator->getUuid(),
                        type: $blacklistType,
                        expires: $blacklistExpires,
                        reportUuid: $reportUuid
                    );
                }

                AuditLogManager::createEntry(AuditLogType::REPORT_CLOSED, sprintf(
                    'Report %s closed by operator %s',
                    $reportUuid,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $reportRecord->getReportingEntity(), null, null, null);
            }
            catch (InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), HttpResponseCode::BAD_REQUEST, $e);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_CLOSE_REPORT, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse();
        }

        /**
         * Applies the configured reputation adjustment for the classification flag to the reported entity and,
         * if enabled, to every existing entity mentioned within the report's evidence. The reported entity is
         * adjusted only once even if its evidence mentions it.
         *
         * @param string|null $reportingEntity The UUID of the reported entity, if any
         * @param ClassificationFlag $classificationFlag The classification flag the report was closed with
         * @param EvidenceRecord[] $evidenceRecords The evidence records linked to the report
         * @throws DatabaseOperationException Thrown if the reported entity's reputation could not be updated
         */
        private static function applyReputation(?string $reportingEntity, ClassificationFlag $classificationFlag, array $evidenceRecords): void
        {
            $scanningConfiguration = Configuration::getScanningConfiguration();

            $delta = $scanningConfiguration->getReportReputation($classificationFlag);
            if($reportingEntity !== null && $delta !== 0)
            {
                EntitiesManager::updateEntityReputation($reportingEntity, $delta);
            }

            $namedEntityDelta = $scanningConfiguration->getReportNamedEntityReputation($classificationFlag);
            if(!$scanningConfiguration->isReportNamedEntityReputationEnabled() || $namedEntityDelta === 0)
            {
                return;
            }

            $namedEntities = [];
            foreach($evidenceRecords as $evidenceRecord)
            {
                if($evidenceRecord->getTextContent() !== null)
                {
                    $namedEntities += EntitiesManager::resolveNamedEntities($evidenceRecord->getTextContent());
                }
            }

            if($reportingEntity !== null)
            {
                unset($namedEntities[$reportingEntity]);
            }

            foreach(array_keys($namedEntities) as $entityUuid)
            {
                try
                {
                    EntitiesManager::updateEntityReputation($entityUuid, $namedEntityDelta);
                }
                catch(DatabaseOperationException $e)
                {
                    // The report is already closed at this point, a failed mention adjustment must not fail the request
                    Logger::log()->warning(sprintf('Failed to update reputation of mentioned entity %s: %s', $entityUuid, $e->getMessage()), $e);
                }
            }
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
            return 'Close a report';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Closes a report with an optional classification flag. When a classification flag is given, the reputation of the reported entity, and of existing entities mentioned in the report\'s evidence, is adjusted according to the host\'s configuration. Only the assigned operator can close a report. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'closeReport';
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
                    'description' => 'UUID of the report to close',
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
                                'classification_flag' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Optional classification flag for the report',
                                    'enum' => ['NORMAL', 'SUSPICIOUS', 'MALICIOUS', null],
                                ],
                                'blacklist_incident_type' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Optional blacklist incident type',
                                    'enum' => ['SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'OTHER', null],
                                ],
                                'blacklist_expires' => [
                                    'type' => ['integer', 'null'],
                                    'description' => 'Optional unix timestamp for blacklist expiration',
                                ],
                            ],
                            'required' => [],
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
                    'description' => 'Report closed successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_UUID . ', ' . self::ERROR_NOT_ASSIGNED . ', ' . self::ERROR_ALREADY_CLOSED . ', invalid classification, invalid blacklist type, or blacklist expiration in the past',
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
                    'description' => 'Report not found',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_FAILED_TO_GET,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
