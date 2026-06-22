<?php

    namespace FederationLib\Objects\BayesianServer;

    use FederationLib\Interfaces\SerializableInterface;
    use InvalidArgumentException;

    class ModelStatistics implements SerializableInterface
    {
        private int $totalDocuments;
        private int $labelCount;
        private int $vocabularySize;
        private int $totalTokenOccurrences;
        private int $totalDocumentTokens;
        private float $smoothingAlpha;
        private float $averageDocumentLength;
        private float $averageTokensPerLabel;
        private float $tokenDensity;
        private int $modelVersion;
        private bool $bm25Enabled;
        private bool $onlineLrEnabled;
        private float $lrInitialLearningRate;
        private float $lrDecayRate;
        private float $bm25K1;
        private float $bm25B;
        /**
         * @var LabelStatistic[]
         */
        private array $labels;

        /**
         * ModelStatistics Public Constructor
         *
         * @param array $array The ModelStatistics array data
         */
        public function __construct(array $array)
        {
            $this->totalDocuments = (int)$array['total_documents'];
            $this->labelCount = (int)$array['label_count'];
            $this->vocabularySize = (int)$array['vocabulary_size'];
            $this->totalTokenOccurrences = (int)$array['total_token_occurrences'];
            $this->totalDocumentTokens = (int)$array['total_document_tokens'];
            $this->smoothingAlpha = (float)$array['smoothing_alpha'];
            $this->averageDocumentLength = (float)$array['average_document_length'];
            $this->averageTokensPerLabel = (float)$array['average_tokens_per_label'];
            $this->tokenDensity = (float)$array['token_density'];
            $this->modelVersion = (int)$array['model_version'];
            $this->bm25Enabled = (bool)$array['bm25_enabled'];
            $this->onlineLrEnabled = (bool)$array['online_lr_enabled'];
            $this->lrInitialLearningRate = (float)$array['lr_initial_learning_rate'];
            $this->lrDecayRate = (float)$array['lr_decay_rate'];
            $this->bm25K1 = (float)$array['bm25_k1'];
            $this->bm25B = (float)$array['bm25_b'];
            $this->labels = [];

            if(isset($array['labels']))
            {
                if(is_string($array['labels']) && $array['labels'] !== '')
                {
                    $array['labels'] = json_decode($array['labels'], true);
                }

                foreach($array['labels'] as $label)
                {
                    if($label instanceof LabelStatistic)
                    {
                        $this->labels[] = $label;
                    }
                    elseif(is_array($label))
                    {
                        $this->labels[] = LabelStatistic::fromArray($label);
                    }
                    else
                    {
                        throw new InvalidArgumentException('Unexpected type: ' . gettype($label));
                    }
                }
            }
        }

        /**
         * Returns the total number of documents learnt
         *
         * @return int Documents learned (multi-label counted once)
         */
        public function getTotalDocuments(): int
        {
            return $this->totalDocuments;
        }

        /**
         * Returns the total number of distinct labels
         *
         * @return int Number of distinct labels
         */
        public function getLabelCount(): int
        {
            return $this->labelCount;
        }

        /**
         * Returns the total number of distinct tokens across the whole model
         *
         * @return int Distinct tokens across the whole model
         */
        public function getVocabularySize(): int
        {
            return $this->vocabularySize;
        }

        /**
         * Returns the sum of all token occurrences across every label
         *
         * @return int Sum of all token occurrences across every label
         */
        public function getTotalTokenOccurrences(): int
        {
            return $this->totalTokenOccurrences;
        }

        /**
         * Returns the total number of tokens across all documents
         *
         * @return int Total tokens across all documents
         */
        public function getTotalDocumentTokens(): int
        {
            return $this->totalDocumentTokens;
        }

        /**
         * Returns the configured additive smoothing constant
         *
         * @return float Additive smoothing constant in effect
         */
        public function getSmoothingAlpha(): float
        {
            return $this->smoothingAlpha;
        }

        /**
         * Returns the mean tokens per document
         *
         * @return float Mean tokens per document
         */
        public function getAverageDocumentLength(): float
        {
            return $this->averageDocumentLength;
        }

        /**
         * Returns the mean token occurrence per label
         *
         * @return float Mean token occurrences per label
         */
        public function getAverageTokensPerLabel(): float
        {
            return $this->averageTokensPerLabel;
        }

        /**
         * Returns the ratio of distinct tokens to total occurrences
         *
         * @return float Ratio of distinct tokens to total occurrences
         */
        public function getTokenDensity(): float
        {
            return $this->tokenDensity;
        }

        /**
         * Returns the model version at snapshot time
         *
         * @return int Model version at snapshot time
         */
        public function getModelVersion(): int
        {
            return $this->modelVersion;
        }

        /**
         * Returns True if BM25 term weighting is enabled
         *
         * @return bool Whether BM25 term weighting is enabled
         */
        public function isBm25Enabled(): bool
        {
            return $this->bm25Enabled;
        }

        /**
         * Returns True if online logistic regression is enabled
         *
         * @return bool Whether online logistic regression is enabled
         */
        public function isOnlineLrEnabled(): bool
        {
            return $this->onlineLrEnabled;
        }

        /**
         * Returns the initial SGD learning rate for online LR
         *
         * @return float Initial SGD learning rate for online LR
         */
        public function getLrInitialLearningRate(): float
        {
            return $this->lrInitialLearningRate;
        }

        /**
         * Returns the configured learning rate decay factor
         *
         * @return float Learning rate decay factor
         */
        public function getLrDecayRate(): float
        {
            return $this->lrDecayRate;
        }

        /**
         * Returns the BM25 term frequency saturation configuration parameter
         *
         * @return float BM25 term frequency saturation parameter
         */
        public function getBm25K1(): float
        {
            return $this->bm25K1;
        }

        /**
         * Returns the BM25 document length normalization configuration parameter
         *
         * @return float BM25 document length normalization parameter
         */
        public function getBm25B(): float
        {
            return $this->bm25B;
        }

        /**
         * Returns the per-label statistic breakdown, these items are sorted by document count in descending order.
         *
         * @return LabelStatistic[] Per-label breakdown, sorted by document count descending
         */
        public function getLabels(): array
        {
            return $this->labels;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'total_documents' => $this->totalDocuments,
                'label_count' => $this->labelCount,
                'vocabulary_size' => $this->vocabularySize,
                'total_token_occurances' => $this->totalTokenOccurrences,
                'total_document_tokens' => $this->totalDocumentTokens,
                'smoothing_alpha' => $this->smoothingAlpha,
                'average_document_length' => $this->averageDocumentLength,
                'average_tokens_per_label' => $this->averageTokensPerLabel,
                'token_density' => $this->tokenDensity,
                'model_version' => $this->modelVersion,
                'bm25_enabled' => $this->bm25Enabled,
                'online_lr_enabled' => $this->onlineLrEnabled,
                'lr_initial_learning_rate' => $this->lrInitialLearningRate,
                'lr_decay_rate' => $this->lrDecayRate,
                'bm25_k1' => $this->bm25K1,
                'bm25_b' => $this->bm25B,
                'labels' => array_map(fn($label) => $label->toArray(), $this->labels),
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ModelStatistics
        {
            return new self($array);
        }
    }