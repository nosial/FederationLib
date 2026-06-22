<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Classes\Utilities;
    use FederationLib\Interfaces\SerializableInterface;

    class EntityRecord implements SerializableInterface
    {
        private string $uuid;
        private string $host;
        private ?string $id;
        private ?array $metadata;
        private int $created;
        private ?int $updated;

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
            $this->host = $data['host'] ?? '';
            $this->id = (isset($data['id']) && $data['id'] !== '') ? $data['id'] : null;
            $this->metadata = null;

            // Parse the JSON metadata
            if(isset($data['metadata']))
            {
                if(is_array($data['metadata']))
                {
                    $this->metadata = $data['metadata'];
                }
                elseif(is_string($data['metadata']))
                {
                    $metadata = @json_decode($data['metadata'], true);
                    if(is_array($metadata))
                    {
                        $this->metadata = $metadata;
                    }
                }
            }

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['created']) && is_string($data['created']))
            {
                // Check if it's a numeric string (from Redis cache)
                if (is_numeric($data['created']))
                {
                    $this->created = (int)$data['created'];
                }
                else
                {
                    // SQL datetime string - convert using strtotime
                    $this->created = strtotime($data['created']);
                }
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $this->created = $data['created']->getTimestamp();
            }
            elseif (isset($data['created']) && is_int($data['created']))
            {
                $this->created = $data['created'];
            }
            else
            {
                $this->created = time();
            }

            if (isset($data['updated']) && is_string($data['updated']))
            {
                // Check if it's a numeric string (from Redis cache)
                if (is_numeric($data['updated']))
                {
                    $this->updated = (int)$data['updated'];
                }
                else
                {
                    // SQL datetime string - convert using strtotime
                    $this->updated = strtotime($data['updated']);
                }
            }
            elseif (isset($data['updated']) && $data['updated'] instanceof DateTime)
            {
                $this->updated = $data['updated']->getTimestamp();
            }
            elseif (isset($data['updated']) && is_int($data['updated']))
            {
                $this->updated = $data['updated'];
            }
            else
            {
                $this->updated = null;
            }
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
            return Utilities::hashEntity($this->host, $this->id);
        }

        /**
         * Get the host associated with the entity.
         *
         * @return string The host of the entity.
         */
        public function getHost(): string
        {
            return $this->host;
        }


        /**
         * Get the unique identifier of the entity.
         *
         * @return string|null The unique identifier of the entity. Null if the host is the only identifier.
         */
        public function getId(): ?string
        {
            return $this->id;
        }

        /**
         * Gets the optional metadata of the entity
         *
         * @return array|null
         */
        public function getMetadata(): ?array
        {
            return $this->metadata;
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
         * Gets the updated timestmap of the entity, null if the record hasn't been updated
         *
         * @return int|null The timestamp when the record was updated, null otherwise.
         */
        public function getUpdated(): ?int
        {
            return $this->updated;
        }

        /**
         * Get the full address of the entity in the format "id@host" or just "host" if id is null.
         *
         * @return string The full address of the entity.
         */
        public function getAddress(): string
        {
            if($this->id !== null)
            {
                return $this->id . '@' . $this->host;
            }

            return $this->host;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'hash' => $this->getHash(),
                'host' => $this->host,
                'id' => $this->id,
                'metadata' => $this->metadata,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): EntityRecord
        {
            return new self($array);
        }
    }