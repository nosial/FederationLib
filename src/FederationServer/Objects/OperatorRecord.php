<?php
    
    namespace FederationServer\Objects;
    
    use DateTime;
    use FederationServer\Interfaces\SerializableInterface;
    
    class OperatorRecord implements SerializableInterface
    {
        private string $uuid;
        private string $apiKey;
        private string $name;
        private bool $disabled;
        private bool $manageOperators;
        private bool $manageBlacklist;
        private bool $isClient;
        private int $created;
        private int $updated;

        /**
         * OperatorRecord constructor.
         *
         * @param array $data Associative array of operator data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->apiKey = $data['api_key'] ?? '';
            $this->name = $data['name'] ?? '';
            $this->disabled = (bool)$data['disabled'] ?? false;
            $this->manageOperators = (bool)$data['manage_operators'] ?? false;
            $this->manageBlacklist = (bool)$data['manage_blacklist'] ?? false;
            $this->isClient = (bool)$data['is_client'] ?? false;
            $this->created = (int)$data['created'] ?? time();
            $this->updated = (int)$data['updated'] ?? time();
        }

        /**
         * Get the UUID of the operator.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the API key of the operator.
         *
         * @return string
         */
        public function getApiKey(): string
        {
            return $this->apiKey;
        }

        /**
         * Get the name of the operator.
         *
         * @return string
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Check if the operator is disabled.
         *
         * @return bool
         */
        public function isDisabled(): bool
        {
            return $this->disabled;
        }

        /**
         * Check if the operator can manage other operators.
         *
         * @return bool
         */
        public function canManageOperators(): bool
        {
            return $this->manageOperators;
        }

        /**
         * Check if the operator can manage the blacklist.
         *
         * @return bool
         */
        public function canManageBlacklist(): bool
        {
            return $this->manageBlacklist;
        }

        /**
         * Check if the operator is a client.
         *
         * @return bool
         */
        public function isClient(): bool
        {
            return $this->isClient;
        }

        /**
         * Get the creation timestamp.
         *
         * @return int
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * Get the last updated timestamp.
         *
         * @return int
         */
        public function getUpdated(): int
        {
            return $this->updated;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'api_key' => $this->apiKey,
                'name' => $this->name,
                'disabled' => $this->disabled,
                'manage_operators' => $this->manageOperators,
                'manage_blacklist' => $this->manageBlacklist,
                'is_client' => $this->isClient,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SerializableInterface
        {
            if(isset($array['created']) )
            {
                if(is_string($array['created']))
                {
                    $array['created'] = strtotime($array['created']);
                }
                if($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }


            if(isset($array['updated']) )
            {
                if(is_string($array['updated']))
                {
                    $array['updated'] = strtotime($array['updated']);
                }
                if($array['updated'] instanceof DateTime)
                {
                    $array['updated'] = $array['updated']->getTimestamp();
                }
            }

            return new self($array);
        }
    }

