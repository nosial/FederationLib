<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\SerializableInterface;

    class BayesianLearn implements SerializableInterface
    {
        private bool $accepted;
        private int $submitted;
        private int $rejected;
        private int $pending;
        private int $currentDocs;
        private int $maxDocs;
        private int $rejectedMaxDocs;

        /**
         * BayesianLearnResponse Constructor
         *
         * @param array $array The learn response array data
         */
        public function __construct(array $array)
        {
            $this->accepted = (bool)$array['accepted'];
            $this->submitted = (int)$array['submitted'];
            $this->rejected = (int)$array['rejected'];
            $this->pending = (int)$array['pending'];
            $this->currentDocs = (int)$array['current_docs'];
            $this->maxDocs = (int)$array['max_docs'];
            $this->rejectedMaxDocs = (int)$array['rejected_max_docs'];
        }

        /**
         * Returns True if every task was enqueued
         *
         * @return bool True when every task was enqueued
         */
        public function isAccepted(): bool
        {
            return $this->accepted;
        }

        /**
         * Returns the total number of tasks that were accepted
         *
         * @return int Number of tasks accepted
         */
        public function getSubmitted(): int
        {
            return $this->submitted;
        }

        /**
         * Returns the total number of tasks that were refused because the queue was full
         *
         * @return int Number refused because the queue was full
         */
        public function getRejected(): int
        {
            return $this->rejected;
        }

        /**
         * Returns the total number of tasks that are currently waiting in the queue
         *
         * @return int Total tasks currently waiting in the queue
         */
        public function getPending(): int
        {
            return $this->pending;
        }

        /**
         * Returns the current total documents learned
         *
         * @return int Current total documents learned
         */
        public function getCurrentDocs(): int
        {
            return $this->currentDocs;
        }

        /**
         * Returns the configured max document limit
         *
         * @return int Configured max document limit; 0 = unlimited
         */
        public function getMaxDocs(): int
        {
            return $this->maxDocs;
        }

        /**
         * Returns the total number of documents rejected due to max-docs limit
         *
         * @return int Documents rejected due to max-docs limit
         */
        public function getRejectedMaxDocs(): int
        {
            return $this->rejectedMaxDocs;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'accepted' => $this->accepted,
                'submitted' => $this->submitted,
                'rejected' => $this->rejected,
                'pending' => $this->pending,
                'current_docs' => $this->currentDocs,
                'max_docs' => $this->maxDocs,
                'rejected_max_docs' => $this->rejectedMaxDocs
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): BayesianLearn
        {
            return new self($array);
        }
    }
