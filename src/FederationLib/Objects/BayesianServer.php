<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Objects\BayesianServer\LearningStatistics;
    use FederationLib\Objects\BayesianServer\ModelStatistics;
    use FederationLib\Objects\BayesianServer\ServerStatistics;

    class BayesianServer implements SerializableInterface
    {
        private int $uptimeSeconds;
        private ModelStatistics $model;
        private LearningStatistics $learning;
        private ServerStatistics $server;

        /**
         * BayesianServer Constructor
         *
         * @param array $array array data of BayesianServer
         */
        public function __construct(array $array)
        {
            $this->uptimeSeconds = (int)$array['uptime_seconds'];
            $this->model = ModelStatistics::fromArray($array['model']);
            $this->learning = LearningStatistics::fromArray($array['learning']);
            $this->server = ServerStatistics::fromArray($array['server']);
        }

        /**
         * Returns the total number of seconds the server has been running for
         *
         * @return int The total number of seconds the server has been running for
         */
        public function getUptimeSeconds(): int
        {
            return $this->uptimeSeconds;
        }

        /**
         * Returns model statistics
         *
         * @return ModelStatistics Model statistics
         */
        public function getModel(): ModelStatistics
        {
            return $this->model;
        }

        /**
         * Returns learning statistics
         *
         * @return LearningStatistics Learning statistics
         */
        public function getLearning(): LearningStatistics
        {
            return $this->learning;
        }

        /**
         * Returns server statistics
         *
         * @return ServerStatistics Server statistics
         */
        public function getServer(): ServerStatistics
        {
            return $this->server;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uptime_seconds' => $this->uptimeSeconds,
                'model' => $this->model->toArray(),
                'learning' => $this->learning->toArray(),
                'server' => $this->server->toArray()
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): BayesianServer
        {
            return new self($array);
        }
    }