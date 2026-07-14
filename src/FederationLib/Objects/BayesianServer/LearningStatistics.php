<?php

    namespace FederationLib\Objects\BayesianServer;

    use FederationLib\Interfaces\SerializableInterface;

    class LearningStatistics implements SerializableInterface
    {
        private int $pending;
        private int $capacity;
        private int $workers;
        private int $submitted;
        private int $processed;
        private int $failed;
        private int $rejected;
        private int $rejectedMaxDocs;
        private int $maxDocs;
        private int $currentDocs;

        /**
         * LearningStatistics Constructor
         *
         * @param array $array The LearningStatistics array data
         */
        public function __construct(array $array)
        {
            $this->pending = (int)$array['pending'];
            $this->capacity = (int)$array['capacity'];
            $this->workers = (int)$array['workers'];
            $this->submitted = (int)$array['submitted'];
            $this->processed = (int)$array['processed'];
            $this->failed = (int)$array['failed'];
            $this->rejected = (int)$array['rejected'];
            $this->rejectedMaxDocs = (int)$array['rejected_max_docs'];
            $this->maxDocs = (int)$array['max_docs'];
            $this->currentDocs = (int)$array['current_docs'];
        }

        /**
         * Returns the total number of tasks that are waiting to be processed
         *
         * @return int Tasks currently waiting to be processed
         */
        public function getPending(): int
        {
            return $this->pending;
        }

        /**
         * Returns the maximum queue capacity
         *
         * @return int Maximum queue capacity
         */
        public function getCapacity(): int
        {
            return $this->capacity;
        }

        /**
         * Returns the total number of background learner threads
         *
         * @return int Number of background learner threads
         */
        public function getWorkers(): int
        {
            return $this->workers;
        }

        /**
         * Returns the total number of tasks that were accepted into the queue since the startup
         *
         * @return int Tasks accepted into the queue since startup
         */
        public function getSubmitted(): int
        {
            return $this->submitted;
        }

        /**
         * Returns the total number of completed tasks from the queue since the startup
         *
         * @return int Tasks successfully learned since startup
         */
        public function getProcessed(): int
        {
            return $this->processed;
        }

        /**
         * Returns the total number of tasks that threw exceptions during the learning process
         *
         * @return int Tasks that threw exceptions while learning
         */
        public function getFailed(): int
        {
            return $this->failed;
        }

        /**
         * Returns the total number of tasks that were rejected because the queue was full
         *
         * @return int Tasks refused because queue was full
         */
        public function getRejected(): int
        {
            return $this->rejected;
        }

        /**
         * Returns the total number of tasks that were rejected because of the maximum documents limit
         *
         * @return int Tasks refused because max-docs limit was reached
         */
        public function getRejectedMaxDocs(): int
        {
            return $this->rejectedMaxDocs;
        }

        /**
         * Returns the total number of allowed documents that is allowed to be consumed before the model switches to
         * read-only mode.
         *
         * @return int Max documents the model may learn; 0 = unlimited
         */
        public function getMaxDocs(): int
        {
            return $this->maxDocs;
        }

        /**
         * Returns the total number of documents learnt.
         *
         * @return int Current total documents learned
         */
        public function getCurrentDocs(): int
        {
            return $this->currentDocs;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'pending' => $this->pending,
                'capacity' => $this->capacity,
                'workers' => $this->workers,
                'submitted' => $this->submitted,
                'processed' => $this->processed,
                'failed' => $this->failed,
                'rejected' => $this->rejected,
                'rejected_max_docs' => $this->rejectedMaxDocs,
                'max_docs' => $this->maxDocs,
                'current_docs' => $this->currentDocs
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): LearningStatistics
        {
            return new self($array);
        }
    }