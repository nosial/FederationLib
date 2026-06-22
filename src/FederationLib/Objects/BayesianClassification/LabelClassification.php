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

        public function __construct(array $array)
        {
            $this->label = $array['label'];
            $this->posterior = (float)$array['posterior'];
            $this->probability = (float)$array['probability'];
            $this->logScore = (float)$array['log_score'];
            $this->lrProbability = $array['lr_probability'] ?? null;
        }

        public function getLabel(): string
        {
            return $this->label;
        }

        public function getPosterior(): float
        {
            return $this->posterior;
        }

        public function getProbability(): float
        {
            return $this->probability;
        }

        public function getLogScore(): float
        {
            return $this->logScore;
        }

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