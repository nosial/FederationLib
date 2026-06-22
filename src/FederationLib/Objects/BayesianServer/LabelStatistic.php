<?php

    namespace FederationLib\Objects\BayesianServer;

    use FederationLib\Interfaces\SerializableInterface;

    class LabelStatistic implements SerializableInterface
    {
        private string $label;
        private int $documentCount;
        private int $totalTokens;
        private int $distinctTokens;
        private float $documentFraction;
        private float $avgTokenFrequency;

        /**
         * LabelStatistic public constructor
         *
         * @param array $array The array data of LabelStaistic
         */
        public function __construct(array $array)
        {
            $this->label = $array['label'];
            $this->documentCount = (int)$array['document_count'];
            $this->totalTokens = (int)$array['total_tokens'];
            $this->distinctTokens = (int)$array['distinct_tokens'];
            $this->documentFraction = (float)$array['document_fraction'];
            $this->avgTokenFrequency = (float)$array['avg_token_frequency'];
        }

        /**
         * Returns the label name
         *
         * @return string The label name
         */
        public function getLabel(): string
        {
            return $this->label;
        }

        /**
         * Returns the total number of documents included in this label
         *
         * @return int Documents that included this label
         */
        public function getDocumentCount(): int
        {
            return $this->documentCount;
        }

        /**
         * Returns the total token occurrences attributed to this label
         *
         * @return int Total token occurrences attributed to this label
         */
        public function getTotalTokens(): int
        {
            return $this->totalTokens;
        }

        /**
         * Returns the distinct number of tokens attributed to this label
         *
         * @return int Distinct tokens attributed to this label
         */
        public function getDistinctTokens(): int
        {
            return $this->distinctTokens;
        }

        /**
         * Returns the proportion of documents that include this label
         *
         * @return float Proportion of documents that include this label
         */
        public function getDocumentFraction(): float
        {
            return $this->documentFraction;
        }

        /**
         * Returns the average token frequency
         *
         * @return float Mean occurrences per distinct token for this label
         */
        public function getAvgTokenFrequency(): float
        {
            return $this->avgTokenFrequency;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'label' => $this->label,
                'document_count' => $this->documentCount,
                'total_tokens' => $this->totalTokens,
                'distinct_tokens' => $this->distinctTokens,
                'document_fraction' => $this->documentFraction,
                'avg_token_frequency' => $this->avgTokenFrequency
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): LabelStatistic
        {
            return new self($array);
        }
    }