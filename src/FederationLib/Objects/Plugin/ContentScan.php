<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Classes\Configuration;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Exceptions\ContentScanRejectedException;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\ScannedContent\Detection;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;
    use InvalidArgumentException;

    class ContentScan
    {
        /** @var ContentInput[] */
        private array $evidence;
        private ?string $authorIdentifier;
        private ?ResolvedEntity $authorEntity;
        /** @var ResolvedEntity[] */
        private array $resolvedEntities;
        private ?OperatorRecord $authenticatedOperator;
        private ?int $topK;
        private ?float $threshold;
        /** @var array<string, float> */
        private array $scanResults;
        /** @var array<int, array{classification: ContentClassification, evidence_index: int|null}> */
        private array $addedClassifications;
        /** @var IncidentType[]|null */
        private ?array $concerns;
        /** @var Detection[] */
        private array $detections;
        /** @var array<int, string[]> */
        private array $resolvedAddresses;
        /** @var array<int, ScanFile[]> */
        private array $files;

        /**
         * ContentScan constructor.
         *
         * @param ContentInput[] $evidence The evidence (content) that was provided to be scanned
         * @param string|null $authorIdentifier The author identifier that was provided, if any
         * @param ResolvedEntity|null $authorEntity The resolved author entity, null if not provided or not found
         * @param ResolvedEntity[] $resolvedEntities The entities resolved from the content
         * @param OperatorRecord|null $authenticatedOperator The authenticated operator, null if the request is anonymous
         * @param int|null $topK The top_k parameter that was provided, if any
         * @param float|null $threshold The threshold parameter that was provided, if any
         * @param IncidentType[]|null $concerns The effective concerns of the request, null to accept every concern
         * @param array<int, string[]> $resolvedAddresses The addresses the host resolved for each evidence item itself
         * @param array<int, ScanFile[]> $files The files submitted with each evidence item, keyed by evidence index
         */
        public function __construct(array $evidence, ?string $authorIdentifier, ?ResolvedEntity $authorEntity, array $resolvedEntities,
            ?OperatorRecord $authenticatedOperator=null, ?int $topK=null, ?float $threshold=null, ?array $concerns=null, array $resolvedAddresses=[],
            array $files=[])
        {
            $this->files = array_map(fn(array $evidenceFiles) => array_values($evidenceFiles), $files);
            $this->resolvedAddresses = $resolvedAddresses;
            $this->concerns = $concerns !== null ? array_values($concerns) : null;
            $this->detections = [];
            $this->evidence = array_values($evidence);
            $this->authorIdentifier = $authorIdentifier;
            $this->authorEntity = $authorEntity;
            $this->resolvedEntities = array_values($resolvedEntities);
            $this->authenticatedOperator = $authenticatedOperator;
            $this->topK = $topK;
            $this->threshold = $threshold;
            $this->scanResults = [];
            $this->addedClassifications = [];
        }

        /**
         * Returns the evidence (content) that was provided to be scanned, in the order it was provided
         *
         * @return ContentInput[] The evidence
         */
        public function getEvidence(): array
        {
            return $this->evidence;
        }

        /**
         * Returns the text content of every evidence item that contains text content
         *
         * @return string[] The text contents
         */
        public function getTextContents(): array
        {
            $textContents = [];
            foreach($this->evidence as $evidence)
            {
                if($evidence->getTextContent() !== null && strlen($evidence->getTextContent()) > 0)
                {
                    $textContents[] = $evidence->getTextContent();
                }
            }

            return $textContents;
        }

        /**
         * Returns the author identifier that was provided with the request (UUID, SHA-256 hash or entity address)
         *
         * @return string|null The author identifier, null if not provided
         */
        public function getAuthorIdentifier(): ?string
        {
            return $this->authorIdentifier;
        }

        /**
         * Returns the resolved author entity along with its active blacklist records and parent entity
         *
         * @return ResolvedEntity|null The author entity, null if not provided or not found
         */
        public function getAuthorEntity(): ?ResolvedEntity
        {
            return $this->authorEntity;
        }

        /**
         * Returns the entities that were found in the content and resolved to known entities
         *
         * @return ResolvedEntity[] The resolved entities
         */
        public function getResolvedEntities(): array
        {
            return $this->resolvedEntities;
        }

        /**
         * Returns the operator that requested the scan
         *
         * @return OperatorRecord|null The authenticated operator, null if the request is anonymous
         */
        public function getAuthenticatedOperator(): ?OperatorRecord
        {
            return $this->authenticatedOperator;
        }

        /**
         * Returns the top_k parameter that was provided with the request
         *
         * @return int|null The top_k parameter, null if not provided
         */
        public function getTopK(): ?int
        {
            return $this->topK;
        }

        /**
         * Returns the threshold parameter that was provided with the request
         *
         * @return float|null The threshold parameter, null if not provided
         */
        public function getThreshold(): ?float
        {
            return $this->threshold;
        }

        /**
         * Returns the effective concerns of the request: the concerns the client requested, or the default concerns of
         * the host when it requested none
         *
         * @return IncidentType[] The effective concerns
         */
        public function getConcerns(): array
        {
            return $this->concerns ?? IncidentType::cases();
        }

        /**
         * Returns whether the given concern is an effective concern of the request, only contributions for an
         * effective concern affect the result
         *
         * @param IncidentType $concern The concern
         * @return bool True if the concern is effective
         */
        public function isConcernEffective(IncidentType $concern): bool
        {
            return $this->concerns === null || in_array($concern, $this->concerns, true);
        }

        /**
         * Returns the publicly routable IP addresses the host resolved itself for an evidence item: those of the name
         * of a HOSTNAME, or of the host of a URL or of the page a WEB_PAGE declares. Unlike the context of the
         * content, these were determined by the host and not asserted by the client (OFD "Client Assertions").
         *
         * @param int $evidenceIndex The index of the evidence item (see getEvidence())
         * @return string[] The resolved addresses, empty if none were resolved or DNS lookups are disabled
         */
        public function getResolvedAddresses(int $evidenceIndex): array
        {
            return $this->resolvedAddresses[$evidenceIndex] ?? [];
        }

        /**
         * Returns the files submitted with an evidence item, in the order the client named them, eg; the images of a
         * message. FederationLib interprets none of them itself; a handler that interprets files must treat every file
         * as hostile (see ScanFile::getPath()). A file the handler cannot interpret is ignored, never an error.
         *
         * @param int $evidenceIndex The index of the evidence item (see getEvidence())
         * @return ScanFile[] The files, empty if the evidence item carries none
         */
        public function getFiles(int $evidenceIndex): array
        {
            return $this->files[$evidenceIndex] ?? [];
        }

        /**
         * Returns whether any evidence item carries files
         *
         * @return bool True if files were submitted for scanning
         */
        public function hasFiles(): bool
        {
            return array_any($this->files, fn(array $evidenceFiles) => count($evidenceFiles) > 0);
        }

        /**
         * Returns whether the host permits retrieving the URLs submitted for scanning, which makes it contact addresses
         * chosen by the requester. Disabled by default; a handler must not retrieve a URL unless this returns true, and
         * must then guard the retrieval as URL uploads are guarded (OFD "Remote Retrieval").
         *
         * @return bool True if URL retrieval is permitted
         */
        public function isUrlRetrievalAllowed(): bool
        {
            return Configuration::getRemoteRetrievalConfiguration()->isScansEnabled();
        }

        /**
         * Adds a detection, a finding about one of the evidence items with respect to a concern, included in the
         * detections of the scan result. Discarded when the concern is not an effective concern of the request.
         *
         * A detection that relies on the context of the content, or on anything else the client asserted that the
         * handler has not verified (OFD "Client Assertions"), must be added with $verified set to false.
         *
         * @param IncidentType $concern The concern the detection relates to
         * @param int $evidenceIndex The index of the evidence item (see getEvidence()) the detection relates to
         * @param string $source The name of the mechanism that made the detection, eg; the name of a filter list
         * @param string|null $entityUuid Optional. The UUID of the entity the detection relates to
         * @param float|null $confidence Optional. The confidence of the detection
         * @param bool $verified Whether the detection relies only on information that was verified
         * @param int|null $fileIndex Optional. The index of the file of the evidence item (see getFiles()) the detection
         *                            relates to, null when it does not relate to a file
         * @return void
         * @throws InvalidArgumentException If the evidence index or the file index does not exist or the source is empty
         */
        public function addDetection(IncidentType $concern, int $evidenceIndex, string $source, ?string $entityUuid=null, ?float $confidence=null, bool $verified=true, ?int $fileIndex=null): void
        {
            if(!isset($this->evidence[$evidenceIndex]))
            {
                throw new InvalidArgumentException(sprintf('The evidence index %d does not exist, %d evidence item(s) were provided', $evidenceIndex, count($this->evidence)));
            }

            if($fileIndex !== null && !isset($this->getFiles($evidenceIndex)[$fileIndex]))
            {
                throw new InvalidArgumentException(sprintf('The file index %d does not exist, the evidence item %d carries %d file(s)', $fileIndex, $evidenceIndex, count($this->getFiles($evidenceIndex))));
            }

            if(trim($source) === '')
            {
                throw new InvalidArgumentException('The source of a detection must not be empty');
            }

            if($confidence !== null && !is_finite($confidence))
            {
                throw new InvalidArgumentException('The confidence of a detection must be a finite number');
            }

            if(!$this->isConcernEffective($concern))
            {
                return;
            }

            $this->detections[] = new Detection($concern, $evidenceIndex, $source, $entityUuid, $confidence, $verified, $fileIndex);
        }

        /**
         * Returns the detections added so far
         *
         * @return Detection[] The detections
         */
        public function getDetections(): array
        {
            return $this->detections;
        }

        /**
         * Adds points to a scanning rule, the scanning rule is included in the scan results and contributes to the
         * risk score in the same way FederationLib's own scanning rules do: positive points lower the risk score and
         * negative points raise it. Adding points to the same scanning rule more than once accumulates them.
         *
         * Scanning rule names should be prefixed to avoid conflicting with other plugins, eg; `ABUSEIPDB_REPORTED`.
         *
         * The points are discarded when the concern of the scanning rule is not an effective concern of the request.
         *
         * @param string $rule The scanning rule name, uppercase letters, digits and underscores (eg; ABUSEIPDB_REPORTED)
         * @param float $points The points to add to the scanning rule
         * @param IncidentType $concern Optional. The concern the scanning rule assesses (default SPAM)
         * @return void
         * @throws InvalidArgumentException If the scanning rule name is invalid or is one of FederationLib's own scanning rules
         */
        public function addScanResult(string $rule, float $points, IncidentType $concern=IncidentType::SPAM): void
        {
            if(!preg_match('/^[A-Z][A-Z0-9_]*$/', $rule))
            {
                throw new InvalidArgumentException(sprintf('Invalid scanning rule name "%s", only uppercase letters, digits and underscores are allowed', $rule));
            }

            if(array_key_exists($rule, ScanningRules::newTable()))
            {
                throw new InvalidArgumentException(sprintf('The scanning rule "%s" is one of FederationLib\'s own scanning rules', $rule));
            }

            if(!is_finite($points))
            {
                throw new InvalidArgumentException(sprintf('The points of the scanning rule "%s" must be a finite number', $rule));
            }

            if(!$this->isConcernEffective($concern))
            {
                return;
            }

            $this->scanResults[$rule] = ($this->scanResults[$rule] ?? 0.0) + $points;
        }

        /**
         * Returns the scanning rules added by the event handlers so far
         *
         * @return array<string, float> The scanning rules mapped to their points
         */
        public function getScanResults(): array
        {
            return $this->scanResults;
        }

        /**
         * Adds a classification of the content, the classification is included in the scan results: it determines the
         * `classification` of the response (the worst flag with the average confidence of all classifications) and
         * applies the CLASSIFICATION_NORMAL,
         * CLASSIFICATION_SUSPICIOUS or CLASSIFICATION_MALICIOUS scanning rule weighted by its confidence.
         *
         * @param ContentClassification $classification The classification
         * The classification is discarded when its concern is not an effective concern of the request.
         *
         * @param int|null $evidenceIndex Optional. The index of the evidence item (see getEvidence()) the classification
         *                                belongs to, used to describe the evidence of automatically generated reports
         * @param IncidentType $concern Optional. The concern the classification assesses (default SPAM)
         * @return void
         * @throws InvalidArgumentException If the evidence index does not exist or the confidence is not between 0 and 1
         */
        public function addClassification(ContentClassification $classification, ?int $evidenceIndex=null, IncidentType $concern=IncidentType::SPAM): void
        {
            if($evidenceIndex !== null && !isset($this->evidence[$evidenceIndex]))
            {
                throw new InvalidArgumentException(sprintf('The evidence index %d does not exist, %d evidence item(s) were provided', $evidenceIndex, count($this->evidence)));
            }

            if(!is_finite($classification->getConfidence()) || $classification->getConfidence() < 0.0 || $classification->getConfidence() > 1.0)
            {
                throw new InvalidArgumentException('The confidence of a classification must be between 0 and 1');
            }

            if(!$this->isConcernEffective($concern))
            {
                return;
            }

            $this->addedClassifications[] = ['classification' => $classification, 'evidence_index' => $evidenceIndex];
        }

        /**
         * Returns the classifications added by the event handlers so far
         *
         * @return ContentClassification[] The added classifications
         */
        public function getAddedClassifications(): array
        {
            return array_map(fn(array $entry) => $entry['classification'], $this->addedClassifications);
        }

        /**
         * Returns the classifications added by the event handlers for the given evidence item
         *
         * @param int $evidenceIndex The index of the evidence item (see getEvidence())
         * @return ContentClassification[] The classifications of the evidence item
         */
        public function getEvidenceClassifications(int $evidenceIndex): array
        {
            return array_values(array_map(fn(array $entry) => $entry['classification'],
                array_filter($this->addedClassifications, fn(array $entry) => $entry['evidence_index'] === $evidenceIndex)
            ));
        }

        /**
         * Returns the scanning rules and classifications added by the event handlers so far, used by the
         * PluginManager to discard the results of an event handler that failed
         *
         * @internal
         * @return array The current state
         */
        public function getState(): array
        {
            return ['scan_results' => $this->scanResults, 'added_classifications' => $this->addedClassifications, 'detections' => $this->detections];
        }

        /**
         * Restores the scanning rules and classifications added by the event handlers to a previous state
         *
         * @internal
         * @param array $state A state returned by getState()
         * @return void
         */
        public function restoreState(array $state): void
        {
            $this->scanResults = $state['scan_results'] ?? [];
            $this->addedClassifications = $state['added_classifications'] ?? [];
            $this->detections = $state['detections'] ?? [];
        }

        /**
         * Rejects the content scan request, the client receives an error response with the given message and HTTP
         * status code, the remaining event handlers are not executed and nothing about the scan is recorded
         *
         * @param string $message The reason the request was rejected
         * @param HttpResponseCode $code The HTTP status code of the response, must be a 4xx client error (default 403)
         * @return never
         * @throws ContentScanRejectedException Always
         * @throws InvalidArgumentException If the HTTP status code is not a 4xx client error
         */
        public function reject(string $message, HttpResponseCode $code=HttpResponseCode::FORBIDDEN): never
        {
            if($code->value < 400 || $code->value > 499)
            {
                throw new InvalidArgumentException(sprintf('A content scan can only be rejected with a 4xx client error, %d given', $code->value));
            }

            throw new ContentScanRejectedException($message, $code);
        }
    }
