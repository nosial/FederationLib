<?php

    namespace FederationLib\Objects\BayesianServer;

    use FederationLib\Interfaces\SerializableInterface;

    class ServerStatistics implements SerializableInterface
    {
        private float $defaultThreshold;
        private float $smoothingAlpha;
        private bool $cjkBigrams;
        private int $minTokenLength;
        private int $maxTokenLength;
        private int $currentMemoryBytes;
        private int $availableMemoryBytes;
        private int $modelMemoryBytes;
        private int $modelMemoryLimitBytes;
        private bool $readOnly;
        private bool $mml;
        private float $mmlConfidenceThreshold;

        /**
         * ServerStatistics Public Constructor
         *
         * @param array $array ServerStatistics array data
         */
        public function __construct(array $array)
        {
            $this->defaultThreshold = $array['default_threshold'];
            $this->smoothingAlpha = $array['smoothing_alpha'];
            $this->cjkBigrams = $array['cjk_bigrams'];
            $this->minTokenLength = $array['min_token_length'];
            $this->maxTokenLength = $array['max_token_length'];
            $this->currentMemoryBytes = $array['current_memory_bytes'];
            $this->availableMemoryBytes = $array['available_memory_bytes'];
            $this->modelMemoryBytes = $array['model_memory_bytes'];
            $this->modelMemoryLimitBytes = $array['model_memory_limit_bytes'];
            $this->readOnly = $array['read_only'];
            $this->mml = $array['mml'];
            $this->mmlConfidenceThreshold = $array['mml_confidence_threshold'];
        }

        /**
         * Returns the default multi-label decision threshold
         *
         * @return float Default multi-label decision threshold
         */
        public function getDefaultThreshold(): float
        {
            return $this->defaultThreshold;
        }

        /**
         * Returns the additive smoothing constant
         *
         * @return float Additive smoothing constant
         */
        public function getSmoothingAlpha(): float
        {
            return $this->smoothingAlpha;
        }

        /**
         * Returns True if CJK character bigrams are enabled
         *
         * @return bool Whether CJK character bigrams are enabled
         */
        public function getCjkBigrams(): bool
        {
            return $this->cjkBigrams;
        }

        /**
         * Returns the shortest retained token length
         *
         * @return int Shortest retained token length
         */
        public function getMinTokenLength(): int
        {
            return $this->minTokenLength;
        }

        /**
         * Returns the longest retained token length
         *
         * @return int Longest retained token length
         */
        public function getMaxTokenLength(): int
        {
            return $this->maxTokenLength;
        }

        /**
         * Returns the current JVM heap usage of the BayesianServer
         *
         * @return int Current JVM heap usage (total - free)
         */
        public function getCurrentMemoryBytes(): int
        {
            return $this->currentMemoryBytes;
        }

        /**
         * Returns the maximum number of bytes of memory available for the JVM to use
         *
         * @return int Maximum heap JVM will use
         */
        public function getAvailableMemoryBytes(): int
        {
            return $this->availableMemoryBytes;
        }

        /**
         * Returns the estimated memory usage by the loaded label token maps
         *
         * @return int Estimated memory used by loaded label token maps
         */
        public function getModelMemoryBytes(): int
        {
            return $this->modelMemoryBytes;
        }

        /**
         * Returns the configured model memory limit
         *
         * @return int Configured model memory limit; 0 = unlimited
         */
        public function getModelMemoryLimitBytes(): int
        {
            return $this->modelMemoryLimitBytes;
        }

        /**
         * Returns True if the server is read-only mode
         *
         * @return bool Whether the server is in read-only mode
         */
        public function isReadOnly(): bool
        {
            return $this->readOnly;
        }

        /**
         * Returns True if multimodel language mode is enabled
         *
         * @return bool Whether Multi-Model Language mode is enabled
         */
        public function isMmlEnabled(): bool
        {
            return $this->mml;
        }

        /**
         * Returns the detection confidence threshold for MML routing if MML mode is enabled
         *
         * @return float Detection confidence threshold for MML routing
         */
        public function getMmlConfidenceThreshold(): float
        {
            return $this->mmlConfidenceThreshold;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'default_threshold' => $this->defaultThreshold,
                'smoothing_alpha' => $this->smoothingAlpha,
                'cjk_bigrams' => $this->cjkBigrams,
                'min_token_length' => $this->minTokenLength,
                'max_token_length' => $this->maxTokenLength,
                'current_memory_bytes' => $this->currentMemoryBytes,
                'available_memory_bytes' => $this->availableMemoryBytes,
                'model_memory_bytes' => $this->modelMemoryBytes,
                'model_memory_limit_bytes' => $this->modelMemoryLimitBytes,
                'read_only' => $this->readOnly,
                'mml' => $this->mml,
                'mml_confidence_threshold' => $this->mmlConfidenceThreshold
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ServerStatistics
        {
            return new self($array);
        }
    }