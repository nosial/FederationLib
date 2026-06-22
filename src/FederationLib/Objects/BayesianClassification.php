<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Objects\BayesianClassification\LabelClassification;
    use InvalidArgumentException;

    class BayesianClassification implements SerializableInterface
    {
        /**
         * @var LabelClassification[]
         */
        private array $labels;
        private string $topLabel;
        private float $topProbability;
        private array $predictedLabels;
        private float $threshold;
        private int $totalTokens;
        private int $knownTokens;
        private int $unknownTokenCount;
        private float $modelVersion;
        private string $scoringMethod;
        private string $languageCode;
        private float $confidence;
        private float $processingTimeMs;

        public function __construct(array $array)
        {
            $this->labels = [];
            $this->topLabel = $array['top_label'];
            $this->topProbability = (float)$array['top_probability'];
            $this->predictedLabels = $array['predicted_labels'];
            $this->threshold = (float)$array['threshold'];
            $this->totalTokens = (int)$array['total_tokens'];
            $this->knownTokens = (int)$array['known_tokens'];
            $this->unknownTokenCount = (int)$array['unknown_token_count'];
            $this->modelVersion = (float)$array['model_version'];
            $this->scoringMethod = $array['scoring_method'];
            $this->languageCode = $array['language_code'];
            $this->confidence = (float)$array['confidence'];
            $this->processingTimeMs = (float)$array['processing_time_ms'];

            if(isset($array['labels']))
            {
                if(is_string($array['labels']) && $array['labels'] !== '')
                {
                    $array['labels'] = json_decode($array['labels'], true);
                }

                foreach($array['labels'] as $classificationEntry)
                {
                    if($classificationEntry instanceof LabelClassification)
                    {
                        $this->labels[] = $classificationEntry;
                    }
                    elseif(is_array($classificationEntry))
                    {
                        $this->labels[] = LabelClassification::fromArray($classificationEntry);
                    }
                    elseif($classificationEntry === null)
                    {
                        $this->labels = [];
                    }
                    else
                    {
                        throw new InvalidArgumentException('Unexpected type: ' . gettype($classificationEntry));
                    }
                }
            }
        }

        public function getLabels(): array
        {
            return $this->labels;
        }

        public function getTopLabel(): string
        {
            return $this->topLabel;
        }

        public function getTopProbability(): float
        {
            return $this->topProbability;
        }

        public function getPredictedLabels(): array
        {
            return $this->predictedLabels;
        }

        public function getThreshold(): float
        {
            return $this->threshold;
        }

        public function getTotalTokens(): int
        {
            return $this->totalTokens;
        }

        public function getKnownTokens(): int
        {
            return $this->knownTokens;
        }

        public function getUnknownTokenCount(): int
        {
            return $this->unknownTokenCount;
        }

        public function getModelVersion(): float
        {
            return $this->modelVersion;
        }

        public function getScoringMethod(): string
        {
            return $this->scoringMethod;
        }

        public function getLanguageCode(): string
        {
            return $this->languageCode;
        }

        public function getConfidence(): float
        {
            return $this->confidence;
        }

        public function getProcessingTimeMs(): float
        {
            return $this->processingTimeMs;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'labels' => array_map(fn($label) => $label->toArray(), $this->labels),
                'top_label' => $this->topLabel,
                'top_probability' => $this->topProbability,
                'predicted_labels' => $this->predictedLabels,
                'threshold' => $this->threshold,
                'total_tokens' => $this->totalTokens,
                'known_tokens' => $this->knownTokens,
                'unknown_token_count' => $this->unknownTokenCount,
                'model_version' => $this->modelVersion,
                'scoring_method' => $this->scoringMethod,
                'language_code' => $this->languageCode,
                'confidence' => $this->confidence,
                'processing_time_ms' => $this->processingTimeMs
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): BayesianClassification
        {
            return new self($array);
        }
    }