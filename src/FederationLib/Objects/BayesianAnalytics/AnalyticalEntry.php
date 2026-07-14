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
        private ?bool $success;
        private ?string $rejectedReason;
        private int $textLength;

        /**
         * Constructs an AnalyticalEntry from an array
         *
         * @param array $array Data array with analytical entry fields
         */
        public function __construct(array $array)
        {
            $this->timestamp = (int)$array['timestamp'];
            $this->type = BayesianEventType::tryFrom($array['type'] ?? '') ?? BayesianEventType::UNKNOWN;
            $this->languageCode = $array['language_code'] ?? 'und';
            $this->labels = $array['labels'] ?? [];
            $this->tokenCount = (int)($array['token_count'] ?? -1);
            $this->confidence = (float)($array['confidence'] ?? -1.0);
            $this->processingTimeMs = (int)($array['processing_time_ms'] ?? -1);
            $this->modelVersion = (int)($array['model_version'] ?? -1);
            $this->success = $array['success'] ?? null;
            $this->rejectedReason = $array['rejected_reason'] ?? null;
            $this->textLength = (int)($array['text_length'] ?? -1);
        }

        /**
         * Returns the timestamp of the analytical entry
         *
         * @return int Unix timestamp
         */
        public function getTimestamp(): int
        {
            return $this->timestamp;
        }

        /**
         * Returns the event type
         *
         * @return BayesianEventType The event type
         */
        public function getType(): BayesianEventType
        {
            return $this->type;
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
         * Returns the classification labels
         *
         * @return array List of classification labels
         */
        public function getLabels(): array
        {
            return $this->labels;
        }

        /**
         * Returns the token count
         *
         * @return int Number of tokens
         */
        public function getTokenCount(): int
        {
            return $this->tokenCount;
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
         * @return int Processing time in ms
         */
        public function getProcessingTimeMs(): int
        {
            return $this->processingTimeMs;
        }

        /**
         * Returns the model version
         *
         * @return int The model version number
         */
        public function getModelVersion(): int
        {
            return $this->modelVersion;
        }

        /**
         * Returns whether the operation was successful
         *
         * @return bool|null True if successful, False otherwise, null if not applicable
         */
        public function isSuccess(): ?bool
        {
            return $this->success;
        }

        /**
         * Returns the rejection reason if the entry was rejected
         *
         * @return string|null The rejection reason or null
         */
        public function getRejectedReason(): ?string
        {
            return $this->rejectedReason;
        }

        /**
         * Returns the text length
         *
         * @return int The text length in characters
         */
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