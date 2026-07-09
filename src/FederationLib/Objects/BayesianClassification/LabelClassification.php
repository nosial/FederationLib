<?php

    namespace FederationLib\Objects\BayesianClassification;

    use FederationLib\Interfaces\SerializableInterface;

    class LabelClassification implements SerializableInterface
    {
        private string $label;
        private float $posterior;
        private float $probability;
        private float $logScore;
        private ?float $lrProbability;

        /**
         * Constructs a LabelClassification from an array
         *
         * @param array $array Data array with keys: label, posterior, probability, log_score, lr_probability
         */
        public function __construct(array $array)
        {
            $this->label = $array['label'];
            $this->posterior = (float)$array['posterior'];
            $this->probability = (float)$array['probability'];
            $this->logScore = (float)$array['log_score'];
            $this->lrProbability = isset($array['lr_probability']) && $array['lr_probability'] !== null ? (float)$array['lr_probability'] : null;
        }

        /**
         * Returns the classification label name
         *
         * @return string The label name
         */
        public function getLabel(): string
        {
            return $this->label;
        }

        /**
         * Returns the posterior probability
         *
         * @return float The posterior value
         */
        public function getPosterior(): float
        {
            return $this->posterior;
        }

        /**
         * Returns the probability score
         *
         * @return float The probability value
         */
        public function getProbability(): float
        {
            return $this->probability;
        }

        /**
         * Returns the log score
         *
         * @return float The log score value
         */
        public function getLogScore(): float
        {
            return $this->logScore;
        }

        /**
         * Returns the likelihood ratio probability, or null if not available
         *
         * @return float|null The likelihood ratio probability or null
         */
        public function getLrProbability(): ?float
        {
            return $this->lrProbability;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'label' => $this->label,
                'posterior' => $this->posterior,
                'probability' => $this->probability,
                'log_score' => $this->logScore,
                'lr_probability' => $this->lrProbability
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): LabelClassification
        {
            return new self($array);
        }
    }