<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\StandardObjectInterface;

    class EntityRecord implements StandardObjectInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private string $host;
        private ?string $id;
        private ?array $metadata;
        private bool $whitelisted;
        private int $reputation;
        private ?int $reputationLastUpdated;
        private ?string $relationshipEntity;
        private ?EntityRelationshipType $relationshipType;
        private int $created;
        private ?int $updated;

        /**
         * EntityRecord constructor.
         *
         * @param array $data Associative array of entity data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->host = $data['host'] ?? '';
            $this->id = (isset($data['id']) && $data['id'] !== '') ? $data['id'] : null;
            $this->metadata = null;
            $this->reputation = (int)$data['reputation'] ?? 0;
            $this->whitelisted = (bool)$data['whitelisted'] ?? false;
            $this->relationshipEntity = $data['relationship_entity'] ?? null;

            if(isset($data['relationship_type']))
            {
                if(is_string($data['relationship_type']))
                {
                    $this->relationshipType = EntityRelationshipType::tryFrom($data['relationship_type']);
                }
                elseif($data['relationship_type'] instanceof EntityRelationshipType)
                {
                    $this->relationshipType = $data['relationship_type'];
                }
            }

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

            if (isset($data['reputation_last_updated']) && is_string($data['reputation_last_updated']))
            {
                // Check if it's a numeric string (from Redis cache)
                if (is_numeric($data['reputation_last_updated']))
                {
                    $this->reputationLastUpdated = (int)$data['reputation_last_updated'];
                }
                else
                {
                    // SQL datetime string - convert using strtotime
                    $this->reputationLastUpdated = strtotime($data['reputation_last_updated']);
                }
            }
            elseif (isset($data['reputation_last_updated']) && $data['reputation_last_updated'] instanceof DateTime)
            {
                $this->reputationLastUpdated = $data['reputation_last_updated']->getTimestamp();
            }
            elseif (isset($data['reputation_last_updated']) && is_int($data['reputation_last_updated']))
            {
                $this->reputationLastUpdated = $data['reputation_last_updated'];
            }
            else
            {
                $this->reputationLastUpdated = null;
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
         * Returns True if the entity is whitelisted by the server
         *
         * @return bool True if the entiy is whitelisted
         */
        public function isWhitelisted(): bool
        {
            return $this->whitelisted;
        }

        /**
         * Returns the reputation score of the entity, with a max value of -1000 and 1000.
         * Negative values = Bad reputation score
         * Positive values = Good reputation score
         *
         * @return int The reputation score of the entity
         */
        public function getReputation(): int
        {
            return $this->reputation;
        }

        /**
         * Returns the Unix timestamp of when the reputation score of the entity was last updated
         *
         * @return int|null The Unix timestamp of the last time the reputation score was updated
         */
        public function getReputationLastUpdated(): ?int
        {
            return $this->reputationLastUpdated;
        }

        /**
         * Returns the entity UUID of the target entity for the relationship
         *
         * @return string|null
         */
        public function getRelationshipEntity(): ?string
        {
            return $this->relationshipEntity;
        }

        /**
         * Returns the relationship type of the target entity
         *
         * @return EntityRelationshipType|null
         */
        public function getRelationshipType(): ?EntityRelationshipType
        {
            return $this->relationshipType;
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
         * Gets the updated timestamp of the entity, null if the record hasn't been updated
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
                'whitelisted' => $this->whitelisted,
                'reputation' => $this->reputation,
                'reputation_last_updated' => $this->reputationLastUpdated,
                'relationship_entity' => $this->relationshipEntity,
                'relationship_type' => $this->relationshipType?->value ?? null,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public function toStandardArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'hash' => $this->getHash(),
                'host' => $this->host,
                'id' => $this->id,
                'metadata' => $this->metadata,
                'reputation' => $this->reputation,
                'relationship_entity' => $this->relationshipEntity,
                'relationship_type' => $this->relationshipType?->value ?? null,
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
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Unique identifier for the entity'],
                'hash' => ['type' => 'string', 'description' => 'SHA-256 hash of the entity'],
                'host' => ['type' => 'string', 'description' => 'Hostname or domain of the entity'],
                'id' => ['type' => 'string', 'description' => 'Local-part identifier (email username)', 'nullable' => true],
                'metadata' => ['type' => 'object', 'description' => 'Additional entity metadata', 'nullable' => true],
                'whitelisted' => ['type' => 'boolean', 'description' => 'Whether the entity is whitelisted'],
                'reputation' => ['type' => 'integer', 'description' => 'Reputation score between -1000 and 1000'],
                'reputation_last_updated' => ['type' => 'integer', 'description' => 'Unix timestamp of last reputation update', 'nullable' => true],
                'relationship_entity' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the related entity', 'nullable' => true],
                'relationship_type' => ['type' => 'string', 'description' => 'Type of relationship with the related entity', 'nullable' => true],
                'created' => ['type' => 'integer', 'description' => 'Unix timestamp when the entity was created'],
                'updated' => ['type' => 'integer', 'description' => 'Unix timestamp when the entity was last updated'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'hash', 'host', 'whitelisted', 'reputation', 'created', 'updated'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/EntityRecord';
        }
    }