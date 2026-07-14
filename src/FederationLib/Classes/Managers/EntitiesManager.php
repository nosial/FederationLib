<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ScannedContent;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Redis;
    use RedisException;
    use Symfony\Component\Uid\Uuid;

    class EntitiesManager
    {
        private const string CACHE_PREFIX = 'entity:';
        private const string REPUTATION_WINDOW_PREFIX = 'reputation_window:';
        private const string REPUTATION_ACTIVE_SET = 'reputation_window:active';

        /**
         * Registers a new entity with the given ID and domain.
         *
         * @param string $host The host of the entity
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific domain
         * @param array|null $metadata Optional. Metadata to associate with the entity
         * @return string The UUID of the registered entity
         * @throws InvalidArgumentException If the ID exceeds 255 characters or if the domain is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function registerEntity(string $host, ?string $id=null, ?array $metadata=null): string
        {
            if($id !== null && strlen($id) > 255)
            {
                throw new InvalidArgumentException("Entity ID cannot exceed 255 characters.");
            }

            if(!Validate::host($host))
            {
                throw new InvalidArgumentException('A valid Entity host/domain must be provided');
            }

            if(strlen($host) > 255)
            {
                throw new InvalidArgumentException("Host cannot exceed 255 characters.");
            }

            if($metadata !== null && !Validate::entityMetadata($metadata))
            {
                throw new InvalidArgumentException('Invalid entity metadata provided');
            }

            $uuid = Uuid::v7()->toRfc4122();
            $hash = Utilities::hashEntity($host, $id);

            try
            {
                if($metadata !== null)
                {
                    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt = DatabaseConnection::getConnection()->prepare(
                        "INSERT INTO entities (uuid, hash, id, host, metadata) VALUES (:uuid, :hash, :id, :host, :metadata)"
                    );
                    $stmt->bindParam(':metadata', $metadataJson);
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare(
                        "INSERT INTO entities (uuid, hash, id, host) VALUES (:uuid, :hash, :id, :host)"
                    );
                }

                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':hash', $hash);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':host', $host);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to register entity: " . $e->getMessage(), $e->getCode(), $e);
            }

            return $uuid;
        }

        /**
         * Updates the metadata of an existing entity, merging new values with existing ones.
         * Only updates the database when changes are detected. New keys are added, existing
         * keys are overwritten, but no existing keys are removed.
         *
         * @param string $entityUuid The UUID of the entity to update
         * @param array $metadata The new metadata to merge into the existing metadata
         * @return bool True if changes were applied, False if no changes were needed
         * @throws InvalidArgumentException If the metadata is invalid or entity is not found
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function updateEntityMetadata(string $entityUuid, array $metadata): bool
        {
            if(!Validate::entityMetadata($metadata))
            {
                throw new InvalidArgumentException('Invalid entity metadata provided');
            }

            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            $existingMetadata = $entity->getMetadata() ?? [];
            $mergedMetadata = array_merge($existingMetadata, $metadata);
            $existingJson = json_encode($existingMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mergedJson = json_encode($mergedMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if($mergedJson === $existingJson)
            {
                return false;
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET metadata=:metadata, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':metadata', $mergedJson);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity metadata: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
            }

            return true;
        }

        /**
         * Updates the entity's relationship
         *
         * @param string $entityUuid The entity to update
         * @param string $targetEntityUuid The target entity of the relationship (the parent)
         * @param EntityRelationshipType $type The relationship type
         * @return bool True if the relationship was updated successfully, False otherwise
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function assignEntityRelationship(string $entityUuid, string $targetEntityUuid, EntityRelationshipType $type): bool
        {
            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            $targetEntity = self::getEntityByUuid($targetEntityUuid);
            if($targetEntity === null)
            {
                throw new InvalidArgumentException('Target Entity not found');
            }

            $typeString = $type->value;

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET relationship_entity=:relationship_entity, relationship_type=:relationship_type, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':relationship_entity', $targetEntityUuid);
                $stmt->bindParam(':relationship_type', $typeString);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity relationship: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
            }

            return true;
        }

        /**
         * Clears the entity's relationship information
         *
         * @param string $entityUuid The entity UUID to clear relationship data from
         * @return bool True if the operation was successful, False otherwise.
         * @throws DatabaseOperationException Thrown if there was an databsae operation error.
         */
        public static function clearEntityRelationship(string $entityUuid): bool
        {
            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET relationship_entity=null, relationship_type=null, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity relationship: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
            }

            return true;
        }

        /**
         * Retrieves an entity by its ID and domain.
         *
         * @param string $host The host of the entity.
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific domain
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntity(string $host, ?string $id=null): ?EntityRecord
        {
            if(!is_null($id))
            {
                if(strlen($id) < 1)
                {
                    throw new InvalidArgumentException("Entity ID cannot be empty if provided");
                }

                if(strlen($id) > 255)
                {
                    throw new InvalidArgumentException("Entity ID cannot exceed 255 characters.");
                }
            }

            if(!Validate::host($host))
            {
                throw new InvalidArgumentException('A valid Entity host/domain must be provided');
            }
            elseif(strlen($host) > 255)
            {
                throw new InvalidArgumentException("Host cannot exceed 255 characters.");
            }

            // Try cache first
            $hash = Utilities::hashEntity($host, $id);

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return new EntityRecord($data);
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE hash=:hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$data)
                {
                    return null;
                }

                $data = new EntityRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by domain: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0,
                );

                // Create a pointer from hash to UUID for quick lookups
                // Only set the hash pointer if the entity record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::CACHE_PREFIX, $hash),
                    expire: Configuration::getRedisConfiguration()->getEntitiesCacheTtl(),
                    value: $data->getUuid()
                );
            }

            return $data;
        }

        /**
         * Retrieves an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByUuid(string $uuid): ?EntityRecord
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            if(!Validate::uuid($uuid))
            {
                throw new InvalidArgumentException('A valid Entity UUID must be provided');
            }

            if(self::isCachingEnabled())
            {
                $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                if($data !== null)
                {
                    return new EntityRecord($data);
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if(!$data)
                {
                    return null;
                }

                $data = new EntityRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0,
                );

                // Create a pointer from hash to UUID for quick lookups
                // Only set the hash pointer if the entity record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::CACHE_PREFIX, Utilities::hashEntity($data->getHost(), $data->getId())),
                    expire: Configuration::getRedisConfiguration()->getEntitiesCacheTtl(),
                    value: $data->getUuid()
                );
            }

            return $data;
        }

        /**
         * Retrieves an entity by its SHA-256 hash.
         *
         * @param string $hash The SHA-256 hash of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByHash(string $hash): ?EntityRecord
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return new EntityRecord($data);
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE hash = :hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();
                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$data)
                {
                    return null;
                }

                $data = new EntityRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by hash: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0,
                );

                // Create a pointer from hash to UUID for quick lookups
                // Only set the hash pointer if the entity record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::CACHE_PREFIX, $hash),
                    expire: Configuration::getRedisConfiguration()->getEntitiesCacheTtl(),
                    value: $data->getUuid()
                );
            }

            return $data;
        }

        /**
         * Checks if an entity exists by its ID and domain.
         *
         * @param string $host The host/domain of the entity
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific host
         * @return bool True if the entity exists, false otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExists(string $host, ?string $id=null): bool
        {
            if(!is_null($id) && strlen($id) < 1)
            {
                throw new InvalidArgumentException("Entity ID must be provided.");
            }

            if(!Validate::host($host))
            {
                throw new InvalidArgumentException('A valid Entity host/domain must be provided');
            }

            $hash = Utilities::hashEntity($host, $id);

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    // Verify that the actual entity record still exists in cache
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return true;
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE hash=:hash LIMIT 1");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();

                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check entity existence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an entity exists by its UUID.
         *
         * @param string $uuid The UUID of the entity.
         * @return bool True if the entity exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExistsByUuid(string $uuid): bool
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            if(!Validate::uuid($uuid))
            {
                throw new InvalidArgumentException("Entity UUID must be valid.");
            }

            if(self::isCachingEnabled())
            {
                if(RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $uuid)))
                {
                    return true;
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check entity existence by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an entity exists by its hash.
         *
         * @param string $hash The hash of the entity.
         * @return bool True if the entity exists, false otherwise.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExistsByHash(string $hash): bool
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    // Verify that the actual entity record still exists in cache
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return true;
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE hash = :hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();
                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check entity existence by hash: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEntity(string $uuid): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                // Remove from cache
                if(self::isCachingEnabled())
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($data !== null)
                    {
                        $hash = Utilities::hashEntity($data['host'], $data['id'] ?? null);
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }

                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    
                    // Handle cascading cache deletions for data that should be deleted with entity
                    RedisConnection::deleteRecordsByField(BlacklistManager::CACHE_PREFIX, 'entity', $uuid);
                    RedisConnection::deleteRecordsByField(AuditLogManager::CACHE_PREFIX, 'entity', $uuid);
                }
            }
        }

        /**
         * Deletes an entity by its ID and domain.
         *
         * @param string $host The host of the entity to delete.
         * @param string|null $id Optional. The ID of the entity if associated with a specific domain
         * @throws InvalidArgumentException If the ID or host is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEntityById(string $host, ?string $id=null): void
        {
            if(strlen($host) < 1 || ($id !== null && strlen($id) < 1))
            {
                throw new InvalidArgumentException("Entity ID and host must be provided.");
            }

            // Remove from cache
            $hash = Utilities::hashEntity($host, $id);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE hash=:hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity by ID and host: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    if($cachedUuid !== false && strlen($cachedUuid) > 0)
                    {
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                        
                        // Handle cascading cache deletions for data that should be deleted with entity
                        RedisConnection::deleteRecordsByField(BlacklistManager::CACHE_PREFIX, 'entity', $cachedUuid);
                        RedisConnection::deleteRecordsByField(AuditLogManager::CACHE_PREFIX, 'entity', $cachedUuid);
                    }

                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                }
            }
        }

        /**
         * Deletes an entity by its hash.
         *
         * @param string $hash The SHA-256 hash of the entity to delete.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEntityByHash(string $hash): void
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE hash = :hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity by hash: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    if($cachedUuid !== false && strlen($cachedUuid) > 0)
                    {
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                        
                        // Handle cascading cache deletions for data that should be deleted with entity
                        RedisConnection::deleteRecordsByField(BlacklistManager::CACHE_PREFIX, 'entity', $cachedUuid);
                        RedisConnection::deleteRecordsByField(AuditLogManager::CACHE_PREFIX, 'entity', $cachedUuid);
                    }

                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                }
            }
        }

        /**
         * Retrieves a list of entities with pagination.
         *
         * @param int $limit The maximum number of entities to retrieve per page.
         * @param int $page The page number to retrieve.
         * @return EntityRecord[] An array of EntityRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntities(int $limit=100, int $page=1): array
        {
            if($limit < 1)
            {
                $limit = 100;
            }
            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $entities = [];
                while($row = $stmt->fetch(PDO::FETCH_ASSOC))
                {
                    $entities[] = new EntityRecord($row);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entities: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $entities, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0
                );
            }

            return $entities;
        }

        /**
         * Returns the total number of entities in the database.
         *
         * @return int The total number of entities.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities");
                $stmt->execute();
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count entities: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Updates the reputation score for an entity atomically. The delta is added to the existing
         * reputation and clamped to the configured bounds. The reputation cache is invalidated
         * after the update so subsequent reads fetch the fresh value.
         *
         * @param string $entityUuid The UUID of the entity to update
         * @param int $delta The amount to add to the current reputation (positive or negative)
         * @return void
         * @throws DatabaseOperationException If there is an error executing the SQL statement
         */
        public static function updateEntityReputation(string $entityUuid, int $delta): void
        {
            try
            {
                $minBound = Configuration::getScanningConfiguration()->getReputationMinBound();
                $maxBound = Configuration::getScanningConfiguration()->getReputationMaxBound();
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET reputation = GREATEST(:minBound, LEAST(:maxBound, reputation + :delta)), reputation_last_updated = :updated WHERE uuid = :uuid"
                );
                $stmt->bindParam(':minBound', $minBound, PDO::PARAM_INT);
                $stmt->bindParam(':maxBound', $maxBound, PDO::PARAM_INT);
                $stmt->bindParam(':delta', $delta, PDO::PARAM_INT);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity reputation: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
            }
        }

        /**
         * Records a completed scan result into the open reputation window for the author entity.
         *
         * Author-specific and classification signals are attributed to the author entity.
         * If the author entity has a parent entity, the same points are also attributed to the parent entity.
         *
         * @param ScannedContent $scannedContent The fully constructed scan result
         */
        public static function recordScan(ScannedContent $scannedContent): void
        {
            $redis = self::getReputationRedis();
            if($redis === null)
            {
                return;
            }

            $now = time();
            $scanResults = $scannedContent->getScanResults();

            if($scannedContent->getAuthorEntity() !== null)
            {
                $authorUuid = $scannedContent->getAuthorEntity()->getEntity()->getUuid();
                $authorPoints = 0.0;

                foreach(ScanningRules::cases() as $rule)
                {
                    if($rule->isAuthorRule() || $rule->isClassificationRule())
                    {
                        $authorPoints += $scanResults[$rule->name] ?? 0.0;
                    }
                }

                self::accumulateReputation($authorUuid, $authorPoints, $now, $redis);
                self::closeReputationWindow($authorUuid, $redis);

                $authorParent = $scannedContent->getAuthorEntity()->getParentEntity();
                if($authorParent !== null)
                {
                    $parentUuid = $authorParent->getEntity()->getUuid();
                    self::accumulateReputation($parentUuid, $authorPoints, $now, $redis);
                    self::closeReputationWindow($parentUuid, $redis);
                }
            }
        }

        /**
         * Clears the reputation score of an entity back to 0, clearing the reputation window
         *
         * @param string $uuid The target entity UUID
         * @param bool $affectParent True to affect parent entities recursively, False otherwise
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function clearReputation(string $uuid, bool $affectParent=false): void
        {
            $entity = self::getEntityByUuid($uuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET reputation=0, reputation_last_updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clear entity reputation: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
            }

            $redis = self::getReputationRedis();
            if($redis !== null)
            {
                $windowKey = self::REPUTATION_WINDOW_PREFIX . $uuid;
                $redis->del($windowKey);
                $redis->sRem(self::REPUTATION_ACTIVE_SET, $uuid);
            }

            if($affectParent)
            {
                $parentUuid = $entity->getRelationshipEntity();
                if($parentUuid !== null)
                {
                    self::clearReputation($parentUuid, $affectParent);
                }
            }
        }

        /**AuditLogManager
         * Checks whether a single entity's reputation window has elapsed and, if so, computes the
         * reputation delta, persists it to SQL, and cleans up the Redis window data.
         *
         * @param string $entityUuid The entity UUID
         * @param Redis $redis The Redis connection
         * @return bool True if the window was closed, False otherwise
         */
        private static function closeReputationWindow(string $entityUuid, Redis $redis): bool
        {
            $key = self::REPUTATION_WINDOW_PREFIX . $entityUuid;

            try
            {
                $data = $redis->hGetAll($key);
            }
            catch (RedisException $e)
            {
                Logger::log()->error(sprintf('Failed to read reputation window for %s: %s', $entityUuid, $e->getMessage()), $e);
                return false;
            }

            if(empty($data))
            {
                try
                {
                    $redis->sRem(self::REPUTATION_ACTIVE_SET, $entityUuid);
                }
                catch (RedisException $e)
                {
                    Logger::log()->error(sprintf('Failed to clean up stale reputation window set entry for %s: %s', $entityUuid, $e->getMessage()), $e);
                }

                return false;
            }

            $windowDuration = Configuration::getScanningConfiguration()->getReputationWindowDuration();
            $windowStart = (int)($data['window_start'] ?? 0);
            $now = time();

            if($now - $windowStart < $windowDuration)
            {
                return false;
            }

            $accumulatedPoints = (float)($data['accumulated_points'] ?? 0.0);
            $scanCount = (int)($data['scan_count'] ?? 0);
            $maxDelta = Configuration::getScanningConfiguration()->getReputationMaxDelta();
            $minDelta = Configuration::getScanningConfiguration()->getReputationMinDelta();
            $scalingFactor = Configuration::getScanningConfiguration()->getReputationScalingFactor();

            $delta = (int)round($accumulatedPoints * $scalingFactor);
            $delta = max($minDelta, min($maxDelta, $delta));

            try
            {
                if($delta !== 0)
                {
                    self::updateEntityReputation($entityUuid, $delta);

                    Logger::log()->debug(sprintf('Reputation window closed for %s: %+d delta (%d scans, %.2f accumulated points)',
                        $entityUuid, $delta, $scanCount, $accumulatedPoints
                    ));
                }

                $redis->del($key);
                $redis->sRem(self::REPUTATION_ACTIVE_SET, $entityUuid);
                return true;
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->error(sprintf('Failed to persist reputation for %s: %s', $entityUuid, $e->getMessage()), $e);
                return false;
            }
            catch (RedisException $e)
            {
                Logger::log()->error(sprintf('Failed to clean up reputation window for %s: %s', $entityUuid, $e->getMessage()), $e);
                return false;
            }
        }

        /**
         * Atomically accumulates scan points into an entity's open window.
         * Creates the window if it does not yet exist.
         *
         * @param string $entityUuid The entity UUID
         * @param float $points The points to add
         * @param int $now Current Unix timestamp
         * @param Redis $redis The Redis connection
         */
        private static function accumulateReputation(string $entityUuid, float $points, int $now, Redis $redis): void
        {
            $key = self::REPUTATION_WINDOW_PREFIX . $entityUuid;

            try
            {
                $exists = $redis->exists($key);
                if(!$exists)
                {
                    $redis->hMSet($key, [
                        'window_start' => $now,
                        'scan_count' => 0,
                        'accumulated_points' => 0.0,
                        'last_scan_at' => 0,
                    ]);
                }

                $redis->hIncrByFloat($key, 'accumulated_points', $points);
                $redis->hIncrBy($key, 'scan_count', 1);
                $redis->hMSet($key, ['last_scan_at' => $now]);
                $redis->sAdd(self::REPUTATION_ACTIVE_SET, $entityUuid);
            }
            catch (RedisException $e)
            {
                Logger::log()->error(sprintf('Failed to accumulate reputation for %s: %s', $entityUuid, $e->getMessage()), $e);
            }
        }

        /**
         * Returns the Redis connection for reputation window operations or null if unavailable.
         *
         * @return Redis|null
         */
        private static function getReputationRedis(): ?Redis
        {
            return RedisConnection::getConnection();
        }

        /**
         * Retrieves entity records older than the specified TTL.
         *
         * @param int $ttl The TTL in seconds to look back
         * @param int $limit The maximum number of records to return
         * @param int $page The page number for pagination
         * @return array[] An array of raw entity record data
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getOldRecords(int $ttl, int $limit=1000, int $page=1): array
        {
            if($ttl <= 0)
            {
                throw new InvalidArgumentException('TTL must be greater than zero.');
            }

            if($limit < 1 || $limit > 10000)
            {
                throw new InvalidArgumentException('Limit must be between 1 and 10000.');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than zero.');
            }

            $timestamp = date('Y-m-d H:i:s', time() - $ttl);
            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT * FROM entities WHERE created < :timestamp ORDER BY created ASC LIMIT :limit OFFSET :offset"
                );
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old entity records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes entity records older than the specified TTL.
         * Related evidence, reports, and blacklist records are cascade-deleted by the database.
         *
         * @param int $ttl The TTL in seconds after which entity records are considered old
         * @return int The number of deleted records
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function cleanEntries(int $ttl): int
        {
            if($ttl <= 0)
            {
                throw new InvalidArgumentException('TTL must be greater than zero.');
            }

            $timestamp = date('Y-m-d H:i:s', time() - $ttl);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE created < :timestamp");
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->execute();
                return $stmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean entity records: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                    RedisConnection::clearRecords(BlacklistManager::CACHE_PREFIX);
                    RedisConnection::clearRecords(AuditLogManager::CACHE_PREFIX);
                }
            }
        }

        /**
         * Searches entities by a LIKE pattern across uuid, host, and id columns.
         *
         * @param string $likePattern The SQL LIKE pattern to search with.
         * @param int $limit The maximum number of results to return.
         * @param int $page The page number for pagination.
         * @return EntityRecord[] An array of matching EntityRecord objects.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function searchEntities(string $likePattern, int $limit, int $page): array
        {
            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT * FROM entities WHERE uuid LIKE :q ESCAPE '\\\\' OR host LIKE :q ESCAPE '\\\\' OR id LIKE :q ESCAPE '\\\\' ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset"
                );
                $stmt->bindValue(':q', $likePattern);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                return array_map(fn($row) => new EntityRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to search entities: ' . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves the top threats (entities with the lowest reputation scores).
         *
         * @param int $limit The maximum number of entities to return.
         * @return EntityRecord[] An array of EntityRecord objects ordered by reputation ascending.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function getTopThreats(int $limit = 10): array
        {
            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities ORDER BY reputation ASC, uuid DESC LIMIT :limit");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return array_map(fn($row) => new EntityRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to retrieve top threats: ' . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Returns True if caching & entity caching is enabled for this class
         *
         * @return bool True if caching & caching for entities is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEntitiesCacheEnabled();
        }
    }
