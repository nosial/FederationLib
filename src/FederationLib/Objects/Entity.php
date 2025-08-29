<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Interfaces\SerializableInterface;

    class Entity implements SerializableInterface
    {
        private string $uuid;
        private string $id;
        private ?string $domain;
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
            $this->domain = $data['domain'] ?? null;

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['created']) && is_string($data['created']))
            {
                $data['created'] = strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $data['created'] = $data['created']->getTimestamp();
            }
            else
            {
                $data['created'] = $data['created'] ?? time();
            }

            $this->created = (int)($data['created'] ?? time());
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
         * Returns the hash of the entity record.
         *
         * @return string The SHA-256 hash of the entity record.
         */
        public function getHash(): string
        {
            if($this->domain === null)
            {
                return hash('sha256', $this->id);
            }
            else
            {
                return hash('sha256', sprintf('%s@%s', $this->id, $this->domain));
            }
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
         * @return string|null The domain of the entity. null if the entity is universal (e.g., IP address).
         */
        public function getDomain(): ?string
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
                'hash' => $this->getHash(),
                'id' => $this->id,
                'domain' => $this->domain,
                'created' => $this->created,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): Entity
        {
            if(isset($array['created']))
            {
                if(is_string($array['created']))
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