<?php

    namespace FederationLib\Objects\BayesianAnalytics;

    use FederationLib\Enums\BayesianEventType;
    use FederationLib\Interfaces\SerializableInterface;

    class AnalyticalEntry implements SerializableInterface
    {
        private int $timestamp;
        private BayesianEventType $type;
        private string $languageCode;
        private array $labels;
        private int $tokenCount;
        private float $confidence;
        private int $processingTimeMs;
        private int $modelVersion;
        private bool $success;
        private ?string $rejectedReason;
        private int $textLength;

        public function __construct(array $array)
        {
            $this->timestamp = (int)$array['timestamp'];
            $this->type = BayesianEventType::tryFrom($array['type']) ?? BayesianEventType::UNKNOWN;
            $this->languageCode = $array['language_code'];
            $this->labels = $array['labels'];
            $this->tokenCount = $array['token_count'];
            $this->confidence = $array['confidence'];
            $this->processingTimeMs = $array['processing_time_ms'];
            $this->modelVersion = (int)$array['model_version'];
            $this->success = $array['success'];
            $this->rejectedReason = $array['rejected_reason'];
            $this->textLength = $array['text_length'];
        }

        public function getTimestamp(): int
        {
            return $this->timestamp;
        }

        public function getType(): BayesianEventType
        {
            return $this->type;
        }

        public function getLanguageCode(): string
        {
            return $this->languageCode;
        }

        public function getLabels(): array
        {
            return $this->labels;
        }

        public function getTokenCount(): int
        {
            return $this->tokenCount;
        }

        public function getConfidence(): float
        {
            return $this->confidence;
        }

        public function getProcessingTimeMs(): int
        {
            return $this->processingTimeMs;
        }

        public function getModelVersion(): int
        {
            return $this->modelVersion;
        }

        public function isSuccess(): bool
        {
            return $this->success;
        }

        public function getRejectedReason(): ?string
        {
            return $this->rejectedReason;
        }

        public function getTextLength(): int
        {
            return $this->textLength;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'timestamp' => $this->timestamp,
                'type' => $this->type->value,
                'language_code' => $this->languageCode,
                'labels' => $this->labels,
                'token_count' => $this->tokenCount,
                'confidence' => $this->confidence,
                'processing_time_ms' => $this->processingTimeMs,
                'model_version' => $this->modelVersion,
                'success' => $this->success,
                'rejected_reason' => $this->rejectedReason ?? null,
                'text_length' => $this->textLength
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): AnalyticalEntry
        {
            return new self($array);
        }
    }