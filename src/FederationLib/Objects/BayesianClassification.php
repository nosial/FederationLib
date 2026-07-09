<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\ClassificationFlag;
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

        /**
         * Constructs a BayesianClassification from an array
         *
         * @param array $array Data array with classification fields
         */
        public function __construct(array $array)
        {
            $this->labels = [];
            $this->topLabel = !empty($array['top_label']) ? $array['top_label'] : ClassificationFlag::NORMAL->value;
            $this->topProbability = (float)($array['top_probability'] ?? 0.0);
            $this->predictedLabels = $array['predicted_labels'] ?? [];
            $this->threshold = (float)($array['threshold'] ?? 0.5);
            $this->totalTokens = (int)($array['total_tokens'] ?? 0);
            $this->knownTokens = (int)($array['known_tokens'] ?? 0);
            $this->unknownTokenCount = (int)($array['unknown_token_count'] ?? 0);
            $this->modelVersion = (float)($array['model_version'] ?? 0.0);
            $this->scoringMethod = $array['scoring_method'] ?? 'naive_bayes';
            $this->languageCode = $array['language_code'] ?? 'unknown';
            $this->confidence = (float)($array['confidence'] ?? 0.0);
            $this->processingTimeMs = (float)($array['processing_time_ms'] ?? 0.0);

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

        /**
         * Returns the list of label classifications
         *
         * @return LabelClassification[] Array of label classifications
         */
        public function getLabels(): array
        {
            return $this->labels;
        }

        /**
         * Returns the top predicted label
         *
         * @return string The top label name
         */
        public function getTopLabel(): string
        {
            return $this->topLabel;
        }

        /**
         * Returns the probability of the top label
         *
         * @return float Top label probability
         */
        public function getTopProbability(): float
        {
            return $this->topProbability;
        }

        /**
         * Returns the list of predicted labels
         *
         * @return array List of predicted label names
         */
        public function getPredictedLabels(): array
        {
            return $this->predictedLabels;
        }

        /**
         * Returns the classification threshold
         *
         * @return float The threshold value
         */
        public function getThreshold(): float
        {
            return $this->threshold;
        }

        /**
         * Returns the total number of tokens
         *
         * @return int Total token count
         */
        public function getTotalTokens(): int
        {
            return $this->totalTokens;
        }

        /**
         * Returns the number of known tokens
         *
         * @return int Known token count
         */
        public function getKnownTokens(): int
        {
            return $this->knownTokens;
        }

        /**
         * Returns the number of unknown tokens
         *
         * @return int Unknown token count
         */
        public function getUnknownTokenCount(): int
        {
            return $this->unknownTokenCount;
        }

        /**
         * Returns the model version
         *
         * @return float The model version number
         */
        public function getModelVersion(): float
        {
            return $this->modelVersion;
        }

        /**
         * Returns the scoring method used
         *
         * @return string The scoring method name
         */
        public function getScoringMethod(): string
        {
            return $this->scoringMethod;
        }

        /**
         * Returns the language code
         *
         * @return string The language code (e.g., 'en', 'de')
         */
        public function getLanguageCode(): string
        {
            return $this->languageCode;
        }

        /**
         * Returns the confidence score
         *
         * @return float The confidence value
         */
        public function getConfidence(): float
        {
            return $this->confidence;
        }

        /**
         * Returns the processing time in milliseconds
         *
         * @return float Processing time in ms
         */
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