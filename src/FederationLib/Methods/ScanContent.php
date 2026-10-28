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
    use FederationLib\Classes\Managers\ReputationManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ContentType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\ScanFile;
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
        private const string ERROR_CONTENT_EMPTY = 'At least one evidence record must contain text content or a file';
        private const string ERROR_EVIDENCE_JSON = 'The evidence part of a multipart request must carry the JSON text of the evidence';
        private const array PARAMETERS = ['author', 'evidence', 'top_k', 'threshold', 'concerns'];
        private const string ERROR_INVALID_AUTHOR = 'The author must be an entity identifier, and a telephone entity the international number as 1 to 15 digits without a leading + or separators, such as 15550123456@e164.arpa';
        private const string ERROR_CONCERNS_INVALID = 'Concerns must be a non-empty array of concerns this server can assess';
        private const string ERROR_FAILED_RESOLVE_AUTHOR = 'Failed to resolve author entity';

        /**
         * The source of the detections FederationLib makes from the active blacklist records of resolved entities
         */
        private const string DETECTION_SOURCE_BLACKLIST = 'blacklist';
        private const string DNS_CACHE_PREFIX = 'dns_addresses:';

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

            // Get the parameters, a request that carries files is a multipart/form-data body whose evidence and concerns
            // parts carry the JSON text of their values (OFD "Scanned files")
            $authorIdentifier = FederationServer::getParameter('author');
            $evidenceInput = FederationServer::getParameter('evidence');
            $concernsInput = FederationServer::getParameter('concerns');
            if(str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data'))
            {
                if(is_string($evidenceInput))
                {
                    $evidenceInput = json_decode($evidenceInput, true);
                    if(!is_array($evidenceInput))
                    {
                        throw new RequestException(self::ERROR_EVIDENCE_JSON, HttpResponseCode::BAD_REQUEST);
                    }
                }

                if(is_string($concernsInput))
                {
                    $concernsInput = json_decode($concernsInput, true) ?? $concernsInput;
                }
            }

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
            // interprets every content type it defines, so every content type is supported for scanning. Scan Content
            // extends the object with the files member, naming the parts that carry its files.
            $contentInputs = [];
            $fileParts = [];
            foreach($evidenceItems as $index => $item)
            {
                try
                {
                    if(is_array($item) && array_key_exists('files', $item))
                    {
                        $fileParts[$index] = self::parseFilesMember($item['files']);
                        unset($item['files']);
                    }

                    $contentInputs[] = ContentInput::fromRequest($item);
                }
                catch(InvalidArgumentException $e)
                {
                    throw new RequestException(sprintf('%s at index %d', $e->getMessage(), $index), HttpResponseCode::BAD_REQUEST, $e);
                }
            }

            $files = self::collectFiles($fileParts, $authenticatedOperator);
            if(!array_any($contentInputs, fn(ContentInput $input) => $input->getTextContent() !== null) && count($fileParts) === 0)
            {
                throw new RequestException(self::ERROR_CONTENT_EMPTY, HttpResponseCode::BAD_REQUEST);
            }

            $concerns = self::parseConcerns($concernsInput);

            // First, resolve the author entity
            $authorRecord = null;
            if(!empty($authorIdentifier))
            {
                try
                {
                    $authorRecord = self::resolveEntities([[$authorIdentifier, null, true]])[0];
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

            $resolvedAddresses = [];
            $resolved = self::resolveScannedEntities($contentInputs, $resolvedAddresses);
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
                $resolvedAddresses,
                $files
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
         * Resolves the entities of each evidence item according to its content type, remembering the first item each
         * entity was found in and whether it was found in the content itself or only in the unverified context the
         * client asserted. Every entity of the scan is resolved at once, and then added in the order it was found in.
         *
         * @param ContentInput[] $contentInputs The evidence items
         * @param array<int, string[]> $resolvedAddresses Receives the addresses the host resolved for each evidence item
         * @return array<string, array{entity: ResolvedEntity, index: int, verified: bool}> The resolved entities keyed
         *                                                                                  by entity UUID
         */
        private static function resolveScannedEntities(array $contentInputs, array &$resolvedAddresses): array
        {
            $lookups = [];
            $origins = [];
            $dnsLookups = 0;
            foreach($contentInputs as $index => $contentInput)
            {
                foreach(self::getContentCandidates($contentInput) as [$entityIdentifier, $entityPosition, $inContent])
                {
                    $lookups[] = [$entityIdentifier, $entityPosition, $inContent];
                    $origins[] = ['index' => $index, 'verified' => $inContent, 'fallback' => true];
                }

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
                        $lookups[] = [$address, new ResolvedEntityPosition(0, 0, str_contains($address, ':') ? NamedEntityType::IPv6 : NamedEntityType::IPv4), false];
                        $origins[] = ['index' => $index, 'verified' => $verified, 'fallback' => false];
                    }
                }
            }

            $resolvedEntities = self::resolveContentLookups($lookups, array_keys(array_filter($origins, fn(array $origin) => $origin['fallback'])));

            $resolved = [];
            foreach($origins as $key => $origin)
            {
                if(isset($resolvedEntities[$key]))
                {
                    self::addResolvedEntity($resolved, $resolvedEntities[$key], $origin['index'], $origin['verified']);
                }
            }

            return $resolved;
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
         * Determines the entity candidates of an evidence item according to its content type: a HOSTNAME or URL is
         * itself the entity, the named entities of any other content type are extracted from its text, and the URL a
         * WEB_PAGE declares in its context is resolved as an assertion of the client.
         *
         * @param ContentInput $contentInput The evidence item
         * @return array<int, array{0: string, 1: ResolvedEntityPosition, 2: bool}> The identifier and position of each
         *                                                                           candidate, with whether it was
         *                                                                           found in the content itself rather
         *                                                                           than only in its context
         */
        private static function getContentCandidates(ContentInput $contentInput): array
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

            return $candidates;
        }

        /**
         * Resolves the entity lookups of a scan at once. A name that has no entity of its own may still belong to a
         * registrable domain that has one, eg; a subdomain of an advertising network looked up on a device, so the
         * registrable domains of the candidates that may fall back to one and have no entity are resolved in a second
         * batch. A lookup that cannot be resolved because of a database error is left unresolved.
         *
         * @param array<int, array{0: string, 1: ResolvedEntityPosition, 2: bool}> $lookups The identifier, position
         *        and whether the position is included in the ResolvedEntity of each lookup
         * @param int[] $fallbacks The keys of the lookups that fall back to their registrable domain
         * @return array<int, ResolvedEntity> The resolved entity of each lookup that has one, keyed like the lookups
         */
        private static function resolveContentLookups(array $lookups, array $fallbacks): array
        {
            if($lookups === [])
            {
                return [];
            }

            try
            {
                $resolvedEntities = self::resolveEntities($lookups);
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->warning(sprintf('Failed to resolve %d entities: %s', count($lookups), $e->getMessage()), $e);
                return [];
            }

            $registrableLookups = [];
            foreach($fallbacks as $key)
            {
                if($resolvedEntities[$key] === null)
                {
                    $registrableLookup = self::getRegistrableDomainLookup(...$lookups[$key]);
                    if($registrableLookup !== null)
                    {
                        $registrableLookups[$key] = $registrableLookup;
                    }
                }
            }

            if($registrableLookups !== [])
            {
                try
                {
                    foreach(self::resolveEntities($registrableLookups) as $key => $resolvedEntity)
                    {
                        $resolvedEntities[$key] = $resolvedEntity;
                    }
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning(sprintf('Failed to resolve %d registrable domains: %s', count($registrableLookups), $e->getMessage()), $e);
                }
            }

            return array_filter($resolvedEntities);
        }

        /**
         * Determines the lookup of the registrable domain of a DNS name or of the host of a URL, used when the name
         * itself has no entity
         *
         * @param string $entityIdentifier The DNS name or URL
         * @param ResolvedEntityPosition $entityPosition The position of the name or URL in the content
         * @param bool $withPosition Whether the position is returned, false for a name that is not in the content
         * @return array{0: string, 1: ResolvedEntityPosition, 2: bool}|null The lookup of the registrable domain, null
         *                                                                    if the name has no other registrable domain
         */
        private static function getRegistrableDomainLookup(string $entityIdentifier, ResolvedEntityPosition $entityPosition, bool $withPosition): ?array
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

            return [$registrableDomain, $position ?? new ResolvedEntityPosition(0, 0, NamedEntityType::DOMAIN), $position !== null];
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
            $name = strtolower(rtrim($name, '.'));

            // A host MAY reuse the results of an earlier resolution (OFD "Scan Content"), the addresses of a name,
            // including a name that does not resolve, are cached for the TTL of cached results
            $cacheKey = null;
            if(Configuration::getRedisConfiguration()->isEnabled() && RedisConnection::getResultCacheTtl() > 0)
            {
                $cacheKey = self::DNS_CACHE_PREFIX . $name;
                try
                {
                    $cached = RedisConnection::getCachedSearchResults($cacheKey);
                    if($cached !== null)
                    {
                        return $cached;
                    }
                }
                catch(CacheOperationException $e)
                {
                    Logger::log()->warning('Failed to retrieve the cached addresses of ' . $name . ': ' . $e->getMessage(), $e);
                }
            }

            $addresses = self::resolveAddresses($name);
            if($cacheKey !== null)
            {
                try
                {
                    RedisConnection::cacheSearchResults($cacheKey, $addresses);
                }
                catch(CacheOperationException $e)
                {
                    Logger::log()->warning('Failed to cache the addresses of ' . $name . ': ' . $e->getMessage(), $e);
                }
            }

            return $addresses;
        }

        /**
         * Resolves the publicly routable IPv4 and IPv6 addresses of a DNS name (see lookupAddresses())
         *
         * @param string $name The lowercase DNS name without a trailing dot
         * @return string[] The addresses
         */
        private static function resolveAddresses(string $name): array
        {
            $records = @dns_get_record($name . '.', DNS_A | DNS_AAAA);
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
         * Parses the files member of a ContentInput object: a non-empty array naming the parts that carry its files
         * (OFD "Scanned files")
         *
         * @param mixed $files The files member
         * @return string[] The names of the parts, in order
         * @throws InvalidArgumentException If the member is not a non-empty array of permitted part names
         */
        private static function parseFilesMember(mixed $files): array
        {
            if(!is_array($files) || !array_is_list($files) || count($files) === 0)
            {
                throw new InvalidArgumentException('The evidence member "files" must be a non-empty array of part names');
            }

            foreach($files as $name)
            {
                if(!is_string($name) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1 || in_array($name, self::PARAMETERS, true))
                {
                    throw new InvalidArgumentException('The evidence member "files" must name parts of 1 to 64 letters, digits, "_" or "-" that are not parameters of the method');
                }
            }

            return $files;
        }

        /**
         * Collects the files the request carries for each evidence item, validating them against the files members
         * of the evidence and the limits of the server. Every server accepts files, but one that does not scan them
         * (scanning.max_files is 0) or does not scan them for this requester (SCAN_FILES is not public) ignores them,
         * as it does files of a media type it does not interpret. The files are left in the temporary upload
         * directory, from which PHP removes them when the request ends, so that nothing is stored unless the server
         * records the content as evidence (OFD "Scanned files").
         *
         * @param array<int, string[]> $fileParts The names of the parts that carry the files of each evidence item
         * @param OperatorRecord|null $authenticatedOperator The authenticated operator, null if the request is anonymous
         * @return array<int, ScanFile[]> The files of each evidence item that carries files, keyed by evidence index
         * @throws RequestException If the files are invalid
         */
        private static function collectFiles(array $fileParts, ?OperatorRecord $authenticatedOperator): array
        {
            if(count($fileParts) === 0 && count($_FILES) === 0)
            {
                return [];
            }

            // Every part that carries a file is named by exactly one files member, exactly once
            $named = [];
            foreach($fileParts as $names)
            {
                foreach($names as $name)
                {
                    if(isset($named[$name]))
                    {
                        throw new RequestException(sprintf('The part "%s" is named by more than one file', $name), HttpResponseCode::BAD_REQUEST);
                    }

                    $named[$name] = true;
                }
            }

            foreach(array_keys($_FILES) as $part)
            {
                if(!isset($named[$part]))
                {
                    throw new RequestException(sprintf('The part "%s" carries a file that no evidence names in its files member', $part), HttpResponseCode::BAD_REQUEST);
                }
            }

            foreach(array_keys($named) as $name)
            {
                if(!isset($_FILES[$name]))
                {
                    throw new RequestException(sprintf('The part "%s" must carry a single file', $name), HttpResponseCode::BAD_REQUEST);
                }
            }

            $scanningConfiguration = Configuration::getScanningConfiguration();
            if(!$scanningConfiguration->isFilesEnabled())
            {
                return [];
            }

            if(!Configuration::getServerConfiguration()->isScanFilesPublic() && ($authenticatedOperator === null || !$authenticatedOperator->hasClientPermissions()))
            {
                return [];
            }

            if(count($named) > $scanningConfiguration->getMaxFiles())
            {
                throw new RequestException(sprintf('A scan request may carry at most %d file(s)', $scanningConfiguration->getMaxFiles()), HttpResponseCode::BAD_REQUEST);
            }

            $files = [];
            foreach($fileParts as $index => $names)
            {
                foreach($names as $fileIndex => $name)
                {
                    $files[$index][] = self::createScanFile($name, $fileIndex, $scanningConfiguration->getMaxFileSize());
                }
            }

            return $files;
        }

        /**
         * Validates the file carried by a part of the request and describes it for the plugins. The media type is
         * determined from the content of the file, never taken from the client.
         *
         * @param string $name The name of the part
         * @param int $fileIndex The position of the file within the files of its evidence item
         * @param int $maxFileSize The maximum size of a file in bytes
         * @return ScanFile The file
         * @throws RequestException If the part does not carry a single valid file
         */
        private static function createScanFile(string $name, int $fileIndex, int $maxFileSize): ScanFile
        {
            $file = $_FILES[$name] ?? null;
            if(!is_array($file) || !isset($file['tmp_name']) || !is_string($file['tmp_name']))
            {
                throw new RequestException(sprintf('The part "%s" must carry a single file', $name), HttpResponseCode::BAD_REQUEST);
            }

            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int)($file['size'] ?? 0) > $maxFileSize)
            {
                throw new RequestException(sprintf('The file of the part "%s" exceeds the maximum size of %d bytes', $name, $maxFileSize), HttpResponseCode::BAD_REQUEST);
            }

            if($error !== UPLOAD_ERR_OK || $file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name']) || is_link($file['tmp_name']))
            {
                throw new RequestException(sprintf('The file of the part "%s" could not be received', $name), HttpResponseCode::BAD_REQUEST);
            }

            clearstatcache(true, $file['tmp_name']);
            $size = (int)filesize($file['tmp_name']);
            if($size <= 0)
            {
                throw new RequestException(sprintf('The file of the part "%s" is empty', $name), HttpResponseCode::BAD_REQUEST);
            }

            if($size > $maxFileSize)
            {
                throw new RequestException(sprintf('The file of the part "%s" exceeds the maximum size of %d bytes', $name, $maxFileSize), HttpResponseCode::BAD_REQUEST);
            }

            $fileName = Utilities::getSafeFileName(is_string($file['name'] ?? null) ? $file['name'] : '');
            if($fileName === '' || $fileName === 'unnamed')
            {
                $fileName = $name;
            }

            return new ScanFile($fileIndex, $fileName, $file['tmp_name'], $size, strtolower(Utilities::detectMimeType($file['tmp_name'])));
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
         * Resolves several entity identifiers, each with its optional entity position, at once, returning back a
         * ResolvedEntity object for each containing the resolved entity, its active blacklist records and its parent
         * entity. The entities, their parents and their blacklist records are each retrieved with a single query or
         * cache round trip, however many identifiers are resolved.
         *
         * @param array<array-key, array{0: string, 1: ResolvedEntityPosition|null, 2: bool}> $lookups The identifier,
         *        the optional entity position, also used to interpret the identifier, and whether the position is
         *        included in the ResolvedEntity, false when the entity was not found in the content itself (eg; an
         *        address the host resolved), of each lookup
         * @return array<array-key, ResolvedEntity|null> The ResolvedEntity of each lookup, null if the record was not
         *                                               found, keyed like the lookups
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        private static function resolveEntities(array $lookups): array
        {
            $entityRecords = EntitiesManager::resolveNamedEntityList(array_map(fn(array $lookup) => [$lookup[0], $lookup[1]], $lookups));
            $foundRecords = array_filter($entityRecords);
            if($foundRecords === [])
            {
                return $entityRecords;
            }

            // Optionally resolve the parent entities if a relationship is defined
            $parentUuids = array_values(array_unique(array_filter(array_map(fn(EntityRecord $entityRecord) => $entityRecord->getRelationshipEntity(), $foundRecords))));
            $parentRecords = [];
            if($parentUuids !== [])
            {
                try
                {
                    $parentRecords = EntitiesManager::getEntitiesByUuids($parentUuids);
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning(sprintf('Failed to resolve parent entities %s: %s', implode(', ', $parentUuids), $e->getMessage()), $e);
                }
            }

            $activeBlacklists = BlacklistManager::getActiveEntriesPerEntity(array_merge(
                array_map(fn(EntityRecord $entityRecord) => $entityRecord->getUuid(), array_values($foundRecords)),
                array_keys($parentRecords)
            ));

            $results = [];
            foreach($lookups as $key => [, $entityPosition, $withPosition])
            {
                $entityRecord = $entityRecords[$key];
                if($entityRecord === null)
                {
                    $results[$key] = null;
                    continue;
                }

                $parentResolvedEntity = null;
                $parentRecord = $parentRecords[$entityRecord->getRelationshipEntity() ?? ''] ?? null;
                if($parentRecord !== null)
                {
                    $parentResolvedEntity = new ResolvedEntity($parentRecord, $activeBlacklists[$parentRecord->getUuid()] ?? []);
                }

                $results[$key] = new ResolvedEntity($entityRecord, $activeBlacklists[$entityRecord->getUuid()] ?? [], $withPosition ? $entityPosition : null, $parentResolvedEntity);
            }

            return $results;
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

            // Create an evidence record for each provided evidence item that carries text content or files
            $firstEvidenceUuid = null;
            foreach($contentInputs as $evidenceIndex => $contentInput)
            {
                $textContent = $contentInput->getTextContent();
                $files = $contentScan?->getFiles($evidenceIndex) ?? [];
                if(($textContent === null || strlen($textContent) === 0) && count($files) === 0)
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

                // The files submitted with the content are kept only as attachments of the evidence created from it
                // (OFD "Scanned files"), a file that could not be stored does not prevent the report
                foreach($files as $file)
                {
                    try
                    {
                        $attachmentUuid = FileAttachmentManager::storeFile($file->getPath(), $evidenceUuid, $file->getMimeType(), $file->getFileName());
                        AuditLogManager::createEntry(
                            type: AuditLogType::ATTACHMENT_UPLOADED,
                            message: sprintf('File %s recorded from scanned content (size: %d bytes) Type %s', $file->getFileName(), $file->getSize(), $file->getMimeType()),
                            operatorUuid: $systemOperator->getUuid(),
                            entityUuid: $scannedContent->getAuthorEntity()->getEntity()->getUuid(),
                            evidenceUuid: $evidenceUuid,
                            fileAttachmentUuid: $attachmentUuid
                        );
                    }
                    catch(DatabaseOperationException $e)
                    {
                        Logger::log()->error(sprintf('Failed to record the scanned file %s with evidence %s: %s', $file->getFileName(), $evidenceUuid, $e->getMessage()), $e);
                    }
                }

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
            return 'Scans one or more content items for entities and blacklist records and classifies the content using the enabled plugins, interpreting each item according to its content type and assessing only the requested concerns. Items may carry files, such as the images of a message, in a multipart/form-data request whose evidence part carries the JSON text of the evidence and whose files are named in the files member of each item; files are only interpreted by plugins that support their media type, other files are accepted but do not affect the result, as are all files when the server does not scan files from the requester. Requires client permissions unless scanning is public.';
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
                    'multipart/form-data' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'author' => [
                                    'type' => 'string',
                                    'description' => 'UUID, SHA-256 hash, or entity address of the author',
                                ],
                                'evidence' => [
                                    'type' => 'string',
                                    'description' => 'The JSON text of one evidence object or an array of evidence objects; each object may name the parts that carry its files in a files member, an array of part names',
                                ],
                                'top_k' => ['type' => 'integer', 'description' => 'Number of top classifications to return'],
                                'threshold' => ['type' => 'number', 'format' => 'float', 'description' => 'Confidence threshold for classification'],
                                'concerns' => ['type' => 'string', 'description' => 'The JSON text of the array of concerns to assess'],
                            ],
                            'additionalProperties' => [
                                'type' => 'string',
                                'format' => 'binary',
                                'description' => 'A file named in the files member of an evidence object, the part name is 1 to 64 letters, digits, "_" or "-"',
                            ],
                            'required' => ['evidence'],
                        ],
                    ],
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
