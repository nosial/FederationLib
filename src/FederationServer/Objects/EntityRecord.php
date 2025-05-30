<?php

    namespace FederationServer\Objects;

    use DateTime;
    use FederationServer\Interfaces\SerializableInterface;

    class EntityRecord implements SerializableInterface
    {
        private string $uuid;
        private string $id;
        private string $domain;
        private int $created;

        /**
         * EntityRecord constructor.
         *
         * @param array $data Associative array of entity data.
         *                    - 'uuid': string, Unique identifier for the entity record.
         *                    - 'id': string, Identifier for the entity (e.g., IP address, domain).
         *                    - 'domain': string, Domain associated with the entity.
         *                    - 'created': int, Timestamp when the record was created.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->id = $data['id'] ?? '';
            $this->domain = $data['domain'] ?? '';
            $this->created = isset($data['created']) ? (int)$data['created'] : time();
        }

        /**
         * Get the UUID of the entity.
         *
         * @return string The UUID of the entity.
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the unique identifier of the entity.
         *
         * @return string The unique identifier of the entity.
         */
        public function getId(): string
        {
            return $this->id;
        }

        /**
         * Get the domain associated with the entity.
         *
         * @return string The domain of the entity.
         */
        public function getDomain(): string
        {
            return $this->domain;
        }

        /**
         * Get the creation timestamp of the entity record.
         *
         * @return int The timestamp when the record was created.
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
            return [
                'uuid' => $this->uuid,
                'id' => $this->id,
                'domain' => $this->domain,
                'created' => $this->created,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SerializableInterface
        {
            if(isset($array['created']))
            {
                if(is_string($array['created))']))
                {
                    $array['created'] = strtotime($array['created']);
                }
                elseif($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            return new self($array);
        }
    }