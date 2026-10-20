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
    use FederationLib\Classes\Managers\ReputationManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ContentType;
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
    use InvalidArgumentException;

    class ScanContent extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Scanning content is not available to the public, authentication is required';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to scan content, client permissions are required';
        private const string ERROR_EVIDENCE_REQUIRED = 'Evidence is required';
        private const string ERROR_EVIDENCE_INVALID = 'Evidence must be a single evidence object or an array of evidence objects';
        private const string ERROR_CONTENT_EMPTY = 'At least one evidence record must contain text content';
        private const string ERROR_INVALID_AUTHOR = 'The author must be an entity identifier, and a telephone entity the international number as 1 to 15 digits without a leading + or separators, such as 15550123456@e164.arpa';
        private const string ERROR_CONCERNS_INVALID = 'Concerns must be a non-empty array of concerns this server can assess';
        private const string ERROR_FAILED_RESOLVE_AUTHOR = 'Failed to resolve author entity';

        /**
         * The source of the detections FederationLib makes from the active blacklist records of resolved entities
         */
        private const string DETECTION_SOURCE_BLACKLIST = 'blacklist';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(Configuration::getServerConfiguration()->isScanContentPublic());

            if(!Configuration::getServerConfiguration()->isScanContentPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            if(!Configuration::getServerConfiguration()->isScanContentPublic() && !$authenticatedOperator->hasClientPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            // Get the parameters
            $authorIdentifier = FederationServer::getParameter('author');
            $evidenceInput = FederationServer::getParameter('evidence');
            $topK = FederationServer::getIntegerParameter('top_k');
            $threshold = FederationServer::getParameter('threshold');
            if($threshold !== null && !is_int($threshold) && !is_float($threshold) && !(is_string($threshold) && is_numeric($threshold)))
            {
                throw new RequestException('The threshold parameter must be a number', HttpResponseCode::BAD_REQUEST);
            }

            // A telephone author must be a canonical telephone entity, a malformed number is rejected rather than left
            // unresolved (OFD "Telephone Entities")
            if($authorIdentifier !== null && (!is_string($authorIdentifier) || !Validate::telephoneAddress($authorIdentifier)))
            {
                throw new RequestException(self::ERROR_INVALID_AUTHOR, HttpResponseCode::BAD_REQUEST);
            }

            if(!is_array($evidenceInput) || empty($evidenceInput))
            {
                throw new RequestException(self::ERROR_EVIDENCE_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $evidenceItems = self::normalizeEvidence($evidenceInput);
            if($evidenceItems === null)
            {
                throw new RequestException(self::ERROR_EVIDENCE_INVALID, HttpResponseCode::BAD_REQUEST);
            }

            // Each evidence entry is a ContentInput object, validated with its content type and context. FederationLib
            // interprets every content type it defines, so every content type is supported for scanning.
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

            if(!array_any($contentInputs, fn(ContentInput $input) => $input->getTextContent() !== null))
            {
                throw new RequestException(self::ERROR_CONTENT_EMPTY, HttpResponseCode::BAD_REQUEST);
            }

            $concerns = self::parseConcerns(FederationServer::getParameter('concerns'));

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

                // A hidden telephone entity is only resolved by its number, never by the UUID found in other records
                if($authorRecord !== null && $authorRecord->getEntity()->isTelephone() && Utilities::isUuid((string)$authorIdentifier) && self::hideTelephoneEntities())
                {
                    $authorRecord = null;
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
                $parsedTopK = $topK;
            }

            // Resolve the entities of each evidence item according to its content type, remembering the first item
            // each entity was found in and whether it was found in the content itself or only in the unverified
            // context the client asserted
            /** @var array<string, array{entity: ResolvedEntity, index: int, verified: bool}> $resolved */
            $resolved = [];
            $resolvedAddresses = [];
            $dnsLookups = 0;
            foreach($contentInputs as $index => $contentInput)
            {
                foreach(self::resolveContentEntities($contentInput) as [$resolvedEntity, $verified])
                {
                    self::addResolvedEntity($resolved, $resolvedEntity, $index, $verified);
                }

                // The host determines the addresses of the names it is given itself, rather than relying on the client
                // (OFD "Client Assertions"); retrieving URLs is never done by FederationLib itself
                $resolvedAddresses[$index] = [];
                foreach(self::getLookupNames($contentInput) as [$name, $verified])
                {
                    if(!Configuration::getScanningConfiguration()->isDnsLookupsEnabled() || $dnsLookups >= Configuration::getScanningConfiguration()->getDnsLookupLimit())
                    {
                        break;
                    }

                    $dnsLookups++;
                    foreach(self::lookupAddresses($name) as $address)
                    {
                        $resolvedAddresses[$index][] = $address;
                        try
                        {
                            $resolvedEntity = self::resolveEntity($address, new ResolvedEntityPosition(0, 0, str_contains($address, ':') ? NamedEntityType::IPv6 : NamedEntityType::IPv4), withPosition: false);
                            if($resolvedEntity !== null)
                            {
                                self::addResolvedEntity($resolved, $resolvedEntity, $index, $verified);
                            }
                        }
                        catch (DatabaseOperationException $e)
                        {
                            Logger::log()->warning('Failed to resolve ' . $address . ': ' . $e->getMessage(), $e);
                        }
                    }
                }
            }

            $allResolvedEntities = array_map(fn(array $entry) => $entry['entity'], $resolved);

            // Let the plugins scan the content, they may classify it, add their own scanning rules and detections or
            // reject the request before anything about the scan is recorded
            $contentScan = new ContentScan(
                $contentInputs,
                is_string($authorIdentifier) && $authorIdentifier !== '' ? $authorIdentifier : null,
                $authorRecord,
                array_values($allResolvedEntities),
                $authenticatedOperator,
                $parsedTopK,
                $parsedThreshold,
                $concerns,
                $resolvedAddresses
            );

            // Every active blacklist record of an effective concern on an entity resolved from the content is a detection
            foreach($resolved as $entry)
            {
                self::addBlacklistDetections($contentScan, $entry['entity'], $entry['index'], $entry['verified']);
            }

            PluginManager::dispatchContentScan($contentScan);

            // Return the scanned content, including the classifications, scanning rules and detections provided by
            // the plugins, with respect to the effective concerns only
            $scannedContent = new ScannedContent(
                array_values($allResolvedEntities),
                $authorRecord,
                $contentScan->getAddedClassifications(),
                $contentScan->getScanResults(),
                $contentScan->getDetections(),
                $concerns
            );

            // Only authenticated clients contribute to reputation; anonymous scans must not affect it in any way
            if($authenticatedOperator !== null && $authenticatedOperator->hasClientPermissions())
            {
                ReputationManager::recordScan($scannedContent);
            }

            // Generate a report if auto-reporting is enabled for this scan's outcome.
            if(self::shouldGenerateReport($scannedContent))
            {
                try
                {
                    self::generateReport($scannedContent, $contentInputs, $contentScan);
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->error('Failed to generate report: ' . $e->getMessage(), $e);
                }
            }

            self::successResponse($scannedContent->toStandardArray(!self::omitEntityMetadata(), self::hideTelephoneEntities()));
        }

        /**
         * Parses the concerns parameter into the effective concerns of the request: the default concerns of the host
         * when it is absent, otherwise a non-empty array of concerns the host can assess (OFD "Concerns")
         *
         * @param mixed $concernsInput The concerns parameter
         * @return IncidentType[] The effective concerns
         * @throws RequestException If the concerns parameter is invalid
         */
        private static function parseConcerns(mixed $concernsInput): array
        {
            if($concernsInput === null)
            {
                return Configuration::getScanningConfiguration()->getDefaultConcerns();
            }

            // The concerns of Scan Content are an array of strings
            $concerns = is_array($concernsInput) ? IncidentType::parseList($concernsInput) : null;
            if(empty($concerns) || array_any($concerns, fn(IncidentType $concern) => !in_array($concern, Configuration::getScanningConfiguration()->getConcerns(), true)))
            {
                throw new RequestException(self::ERROR_CONCERNS_INVALID, HttpResponseCode::BAD_REQUEST);
            }

            return $concerns;
        }

        /**
         * Resolves the entities of an evidence item according to its content type: a HOSTNAME or URL is itself the
         * entity, the named entities of any other content type are extracted from its text, and the URL a WEB_PAGE
         * declares in its context is resolved as an assertion of the client.
         *
         * @param ContentInput $contentInput The evidence item
         * @return array<int, array{0: ResolvedEntity, 1: bool}> The resolved entities, each with whether it was found
         *                                                        in the content itself rather than only in its context
         */
        private static function resolveContentEntities(ContentInput $contentInput): array
        {
            $textContent = $contentInput->getTextContent();
            $candidates = [];

            if($textContent !== null)
            {
                switch($contentInput->getContentType())
                {
                    case ContentType::HOSTNAME:
                        $candidates[] = [$textContent, new ResolvedEntityPosition(0, strlen($textContent), NamedEntityType::DOMAIN), true];
                        break;

                    case ContentType::URL:
                        $candidates[] = [$textContent, new ResolvedEntityPosition(0, strlen($textContent), NamedEntityType::URL), true];
                        break;

                    default:
                        // The region an SMS was received in interprets the numbers it mentions in national format
                        $region = $contentInput->getContentType() === ContentType::SMS ? ($contentInput->getContext()['region'] ?? null) : null;
                        foreach(NamedEntityType::extract($textContent, $region) as $entityIdentifier => $entityPosition)
                        {
                            $candidates[] = [(string)$entityIdentifier, $entityPosition, true];
                        }
                        break;
                }
            }

            $pageUrl = $contentInput->getContentType() === ContentType::WEB_PAGE ? ($contentInput->getContext()['url'] ?? null) : null;
            if(is_string($pageUrl))
            {
                $candidates[] = [$pageUrl, new ResolvedEntityPosition(0, 0, NamedEntityType::URL), false];
            }

            $results = [];
            foreach($candidates as [$entityIdentifier, $entityPosition, $inContent])
            {
                try
                {
                    // A name that has no entity of its own may still belong to a registrable domain that has one, eg; a
                    // subdomain of an advertising network looked up on a device
                    $resolvedEntity = self::resolveEntity($entityIdentifier, $entityPosition, withPosition: $inContent)
                        ?? self::resolveRegistrableDomain($entityIdentifier, $entityPosition, $inContent);
                    if($resolvedEntity !== null)
                    {
                        $results[] = [$resolvedEntity, $inContent];
                    }
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning('Failed to resolve ' . $entityIdentifier . ': ' . $e->getMessage(), $e);
                }
            }

            return $results;
        }

        /**
         * Resolves the registrable domain of a DNS name or of the host of a URL, when the name itself has no entity
         *
         * @param string $entityIdentifier The DNS name or URL
         * @param ResolvedEntityPosition $entityPosition The position of the name or URL in the content
         * @param bool $withPosition Whether the position is returned, false for a name that is not in the content
         * @return ResolvedEntity|null The resolved registrable domain, null if it has no entity
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function resolveRegistrableDomain(string $entityIdentifier, ResolvedEntityPosition $entityPosition, bool $withPosition): ?ResolvedEntity
        {
            $host = match($entityPosition->getType())
            {
                NamedEntityType::DOMAIN => $entityIdentifier,
                NamedEntityType::URL => parse_url($entityIdentifier, PHP_URL_HOST),
                default => null,
            };

            if(!is_string($host) || $host === '')
            {
                return null;
            }

            $host = strtolower(rtrim($host, '.'));
            $registrableDomain = Utilities::getRegistrableDomain($host);
            if($registrableDomain === null || $registrableDomain === $host)
            {
                return null;
            }

            $position = null;
            if($withPosition && $entityPosition->getType() === NamedEntityType::DOMAIN)
            {
                // The registrable domain is the end of the name
                $position = new ResolvedEntityPosition($entityPosition->getOffset() + $entityPosition->getLength() - strlen($registrableDomain), strlen($registrableDomain), NamedEntityType::DOMAIN);
            }

            return self::resolveEntity($registrableDomain, $position ?? new ResolvedEntityPosition(0, 0, NamedEntityType::DOMAIN), withPosition: $position !== null);
        }

        /**
         * Returns the DNS names of an evidence item that the host resolves itself: the name of a HOSTNAME, and the
         * host of a URL or of the page a WEB_PAGE declares
         *
         * @param ContentInput $contentInput The evidence item
         * @return array<int, array{0: string, 1: bool}> The names, each with whether it was found in the content itself
         */
        private static function getLookupNames(ContentInput $contentInput): array
        {
            $textContent = $contentInput->getTextContent();
            $names = match($contentInput->getContentType())
            {
                ContentType::HOSTNAME => $textContent !== null ? [[$textContent, true]] : [],
                ContentType::URL => $textContent !== null ? [[parse_url($textContent, PHP_URL_HOST), true]] : [],
                ContentType::WEB_PAGE => [[parse_url((string)($contentInput->getContext()['url'] ?? ''), PHP_URL_HOST), false]],
                default => [],
            };

            return array_values(array_filter($names, fn(array $name) => is_string($name[0]) && Validate::dnsName(strtolower(rtrim($name[0], '.')))));
        }

        /**
         * Resolves the publicly routable IPv4 and IPv6 addresses of a DNS name. Addresses that are not publicly
         * routable are never used, and a name that cannot be resolved yields no addresses rather than an error.
         *
         * @param string $name The DNS name
         * @return string[] The addresses
         */
        private static function lookupAddresses(string $name): array
        {
            $records = @dns_get_record(strtolower(rtrim($name, '.')) . '.', DNS_A | DNS_AAAA);
            if(!is_array($records))
            {
                return [];
            }

            $addresses = [];
            foreach($records as $record)
            {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if(!is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
                {
                    continue;
                }

                // Entity hosts are canonical, IPv6 addresses in their compressed lowercase form
                $packed = @inet_pton($address);
                if($packed !== false)
                {
                    $addresses[inet_ntop($packed)] = true;
                }
            }

            return array_keys($addresses);
        }

        /**
         * Adds a resolved entity, keeping the first evidence item it was found in, and marking it verified when it
         * was found in the content of any evidence item
         *
         * @param array $resolved The resolved entities keyed by entity UUID
         * @param ResolvedEntity $resolvedEntity The resolved entity
         * @param int $index The index of the evidence item it was found in
         * @param bool $verified Whether it was found in the content itself rather than in the client's context
         * @return void
         */
        private static function addResolvedEntity(array &$resolved, ResolvedEntity $resolvedEntity, int $index, bool $verified): void
        {
            $uuid = $resolvedEntity->getEntity()->getUuid();
            if(!isset($resolved[$uuid]))
            {
                $resolved[$uuid] = ['entity' => $resolvedEntity, 'index' => $index, 'verified' => $verified];
                return;
            }

            if($verified && !$resolved[$uuid]['verified'])
            {
                $resolved[$uuid] = ['entity' => $resolvedEntity, 'index' => $index, 'verified' => true];
            }
        }

        /**
         * Adds a detection for every incident type of the active blacklist records of a resolved entity and of its
         * parent entity, the content scan discards those that are not an effective concern
         *
         * @param ContentScan $contentScan The content scan
         * @param ResolvedEntity $resolvedEntity The resolved entity
         * @param int $index The index of the evidence item the entity was found in
         * @param bool $verified Whether the entity was found in the content itself rather than in the client's context
         * @return void
         */
        private static function addBlacklistDetections(ContentScan $contentScan, ResolvedEntity $resolvedEntity, int $index, bool $verified): void
        {
            foreach([$resolvedEntity, $resolvedEntity->getParentEntity()] as $entity)
            {
                if($entity === null || $entity->getEntity()->isWhitelisted())
                {
                    continue;
                }

                $types = [];
                foreach($entity->getActiveBlacklists() as $blacklistRecord)
                {
                    $types[$blacklistRecord->getType()->value] = $blacklistRecord->getType();
                }

                foreach($types as $type)
                {
                    $contentScan->addDetection($type, $index, self::DETECTION_SOURCE_BLACKLIST, $entity->getEntity()->getUuid(), null, $verified);
                }
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
         * Resolves the given entity identifier with the optional entity position, returning back a ResolvedEntity
         * object containing the resolved entity and active blacklist records
         *
         * @param string $entityIdentifier The target entity identifier
         * @param ResolvedEntityPosition|null $entityPosition Optional. The entity position, also used to interpret the identifier
         * @param bool $withPosition Whether the position is included in the ResolvedEntity, false when the entity was
         *                           not found in the content itself (eg; an address the host resolved)
         * @return ResolvedEntity|null Returns the ResolvedEntity record, null if the record was not found
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function resolveEntity(string $entityIdentifier, ?ResolvedEntityPosition $entityPosition=null, bool $withPosition=true): ?ResolvedEntity
        {
            $entityRecord = EntitiesManager::resolveNamedEntity($entityIdentifier, $entityPosition);
            if($entityRecord === null)
            {
                return null;
            }

            $activeBlacklists = BlacklistManager::getActiveEntriesByEntities([$entityRecord->getUuid()]);

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
                            BlacklistManager::getActiveEntriesByEntities([$parentRecord->getUuid()])
                        );
                    }
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning(sprintf('Failed to resolve parent entity %s for %s: %s', $parentUuid, $entityRecord->getUuid(), $e->getMessage()), $e);
                }
            }

            return new ResolvedEntity($entityRecord, $activeBlacklists, $withPosition ? $entityPosition : null, $parentResolvedEntity);
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
         * @param ContentInput[] $contentInputs The evidence items provided in the scan request
         * @param ContentScan|null $contentScan Optional. The content scan with the classifications provided by the plugins
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function generateReport(ScannedContent $scannedContent, array $contentInputs, ?ContentScan $contentScan=null): void
        {
            // Do not generate if there's no author entity to blame
            if($scannedContent->getAuthorEntity() === null)
            {
                return;
            }

            // Do not generate the report if there is no operator eligible to be automatically assigned it
            if(!OperatorManager::autoAssignOperatorExists(automated: true))
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

            // Evidence the host creates about a telephone entity, or in support of a report against one, is always
            // confidential (OFD "Population of the Database")
            $authorIsTelephone = $scannedContent->getAuthorEntity()->getEntity()->isTelephone();

            // Create an evidence record for each provided evidence item
            $firstEvidenceUuid = null;
            foreach($contentInputs as $evidenceIndex => $contentInput)
            {
                $textContent = $contentInput->getTextContent();
                if($textContent === null || strlen($textContent) === 0)
                {
                    continue;
                }

                $note = $contentInput->getNote();
                $tag = $contentInput->getTag();
                $confidential = $authorIsTelephone || $contentInput->isConfidential();
                $metadata = $contentInput->getMetadata();

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
                    metadata: $metadata,
                    contentType: $contentInput->getContentType(),
                    context: $contentInput->getContext(),
                    automated: true
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
                evidenceUuid: $firstEvidenceUuid,
                reportUuid: $reportUuid
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
            return 'Scans one or more content items for entities and blacklist records and classifies the content using the enabled plugins, interpreting each item according to its content type and assessing only the requested concerns. Requires client permissions unless scanning is public.';
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
                                'concerns' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string', 'enum' => array_map(fn(IncidentType $type) => $type->value, Configuration::getScanningConfiguration()->getConcerns())],
                                    'minItems' => 1,
                                    'description' => 'The concerns to assess, only these affect the classification, risk score, suggested action and detections. Defaults to the default concerns of the server',
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
                    'description' => self::ERROR_CONTENT_EMPTY . ', ' . lcfirst(self::ERROR_CONCERNS_INVALID) . ', or ' . lcfirst(self::ERROR_INVALID_AUTHOR),
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
