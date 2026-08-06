<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class OperatorCreated implements SerializableInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private string $accessToken;

        /**
         * The OperatorCreated constructor.
         *
         * @param string $uuid The UUID of the created operator
         * @param string $accessToken The raw Access Token of the created operator
         */
        public function __construct(string $uuid, string $accessToken)
        {
            $this->uuid = $uuid;
            $this->accessToken = $accessToken;
        }

        /**
         * Get the UUID of the created operator.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the raw Access Token of the created operator.
         *
         * @return string
         */
        public function getAccessToken(): string
        {
            return $this->accessToken;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'access_token' => $this->accessToken,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): OperatorCreated
        {
            if(!isset($array['uuid']) || !isset($array['access_token']))
            {
                throw new \InvalidArgumentException('Invalid array format for OperatorCreated');
            }

            return new self($array['uuid'], $array['access_token']);
        }

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the created operator'],
                'access_token' => ['type' => 'string', 'description' => 'The raw Access Token for the created operator. Returned only at creation time; it is never stored in plaintext, the server persists only its SHA-256 hash.'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'access_token'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/OperatorCreated';
        }
    }