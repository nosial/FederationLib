<?php

    namespace FederationServer\Objects;

    use FederationServer\Classes\Enums\BlacklistType;
    use FederationServer\Interfaces\SerializableInterface;

    class BlacklistRecord implements SerializableInterface
    {
        private string $uuid;
        private string $operator;
        private string $entity;
        private BlacklistType $type;
        private ?int $expires;
        private int $created;

        /**
         * BlacklistRecord constructor.
         *
         * @param array $data Associative array of blacklist data.
         *                    - 'uuid': string, Unique identifier for the blacklist record.
         *                    - 'operator': string, UUID of the operator who created the record.
         *                    - 'entity': string, Entity being blacklisted (e.g., IP address, domain).
         *                    - 'type': BlacklistType, Type of blacklist (e.g., IP, domain).
         *                    - 'expires': int|null, Timestamp when the blacklist expires, null if permanent.
         *                    - 'created': int, Timestamp when the record was created.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->operator = $data['operator'] ?? '';
            $this->entity = $data['entity'] ?? '';
            $this->type = isset($data['type']) ? BlacklistType::from($data['type']) : BlacklistType::OTHER;
            $this->expires = isset($data['expires']) ? (int)$data['expires'] : null;
            $this->created = isset($data['created']) ? (int)$data['created'] : time();
        }

        /**
         * Get the UUID of the blacklist record.
         *
         * @return string The UUID of the blacklist record.
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the operator UUID who created the blacklist record.
         *
         * @return string The UUID of the operator.
         */
        public function getOperator(): string
        {
            return $this->operator;
        }

        /**
         * Get the entity being blacklisted.
         *
         * @return string The entity being blacklisted (e.g., IP address, domain).
         */
        public function getEntity(): string
        {
            return $this->entity;
        }

        /**
         * Get the type of the blacklist record.
         *
         * @return BlacklistType The type of the blacklist record.
         */
        public function getType(): BlacklistType
        {
            return $this->type;
        }

        /**
         * Get the expiration timestamp of the blacklist record.
         *
         * @return int|null The expiration timestamp, or null if the record is permanent.
         */
        public function getExpires(): ?int
        {
            return $this->expires;
        }

        /**
         * Get the timestamp when the blacklist record was created.
         *
         * @return int The creation timestamp.
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            $data = [
                'uuid' => $this->uuid,
                'operator' => $this->operator,
                'entity' => $this->entity,
                'type' => $this->type->value,
                'created' => $this->created,
            ];

            if($this->expires !== null)
            {
                $data['expires'] = $this->expires;
            }

            return $data;
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SerializableInterface
        {
            if(isset($array['expires']))
            {
                if(is_string($array['expires']))
                {
                    $array['expires'] = strtotime($array['expires']);
                }
                elseif($array['expires'] instanceof \DateTime)
                {
                    $array['expires'] = $array['expires']->getTimestamp();
                }
            }

            if(isset($array['created']))
            {
                if(is_string($array['created']))
                {
                    $array['created'] = strtotime($array['created']);
                }
                elseif($array['created'] instanceof \DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            if(isset($array['type']) && is_string($array['type']))
            {
                $array['type'] = BlacklistType::from($array['type']);
            }

            return new self($array);
        }
    }