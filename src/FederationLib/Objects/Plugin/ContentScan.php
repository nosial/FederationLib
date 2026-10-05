<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Exceptions\ContentScanRejectedException;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;
    use InvalidArgumentException;

    /**
     * A content scan request as seen by the CONTENT_SCAN event handlers, contains everything that was provided with
     * the request and everything FederationLib found so far, and collects the scanning rules added by the handlers.
     */
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
         */
        public function __construct(array $evidence, ?string $authorIdentifier, ?ResolvedEntity $authorEntity, array $resolvedEntities,
            ?OperatorRecord $authenticatedOperator=null, ?int $topK=null, ?float $threshold=null)
        {
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
         * Adds points to a scanning rule, the scanning rule is included in the scan results and contributes to the
         * risk score in the same way FederationLib's own scanning rules do: positive points lower the risk score and
         * negative points raise it. Adding points to the same scanning rule more than once accumulates them.
         *
         * Scanning rule names should be prefixed to avoid conflicting with other plugins, eg; `ABUSEIPDB_REPORTED`.
         *
         * @param string $rule The scanning rule name, uppercase letters, digits and underscores (eg; ABUSEIPDB_REPORTED)
         * @param float $points The points to add to the scanning rule
         * @return void
         * @throws InvalidArgumentException If the scanning rule name is invalid or is one of FederationLib's own scanning rules
         */
        public function addScanResult(string $rule, float $points): void
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
         * @param int|null $evidenceIndex Optional. The index of the evidence item (see getEvidence()) the classification
         *                                belongs to, used to describe the evidence of automatically generated reports
         * @return void
         * @throws InvalidArgumentException If the evidence index does not exist or the confidence is not between 0 and 1
         */
        public function addClassification(ContentClassification $classification, ?int $evidenceIndex=null): void
        {
            if($evidenceIndex !== null && !isset($this->evidence[$evidenceIndex]))
            {
                throw new InvalidArgumentException(sprintf('The evidence index %d does not exist, %d evidence item(s) were provided', $evidenceIndex, count($this->evidence)));
            }

            if(!is_finite($classification->getConfidence()) || $classification->getConfidence() < 0.0 || $classification->getConfidence() > 1.0)
            {
                throw new InvalidArgumentException('The confidence of a classification must be between 0 and 1');
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
            return ['scan_results' => $this->scanResults, 'added_classifications' => $this->addedClassifications];
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
