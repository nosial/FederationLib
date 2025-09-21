<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\QueriedBlacklistRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;

    class EntitiesManager
    {
        private const string CACHE_PREFIX = 'entity:';

        /**
         * Registers a new entity with the given ID and domain.
         *
         * @param string $host The host of the entity
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific domain
         * @throws InvalidArgumentException If the ID exceeds 255 characters or if the domain is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function registerEntity(string $host, ?string $id=null): string
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

            $uuid = Uuid::v4()->toRfc4122();
            $hash = Utilities::hashEntity($host, $id);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO entities (uuid, hash, id, host) VALUES (:uuid, :hash, :id, :host)");
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
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities ORDER BY created DESC LIMIT :limit OFFSET :offset");
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
         * Queries an entity by its hash and retrieves all associated blacklist records, evidence, and audit logs.
         *
         * @param EntityRecord $entityRecord The entity record to query
         * @param bool|null $includeConfidential Whether to include confidential evidence records. Defaults to true. (Note this does not exclude blacklist records, evidence records will be shown as null if they are confidential)
         * @param bool|null $includeLifted Whether to include lifted blacklist records. Defaults to true.
         * @return EntityQueryResult An EntityQueryResult object containing the entity record, queried blacklist records, evidence records, and audit logs.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function queryEntity(EntityRecord $entityRecord, ?bool $includeConfidential=true, ?bool $includeLifted=true): EntityQueryResult
        {
            // Build all the queried blacklist records
            $queriedBlacklistRecords = [];
            foreach(BlacklistManager::getEntriesByEntity($entityRecord->getUuid(), includeLifted: $includeLifted) as $blacklistRecord)
            {
                $evidenceRecord = EvidenceManager::getEvidence($blacklistRecord->getUuid());
                if(!$includeConfidential && ($evidenceRecord === null || $evidenceRecord->isConfidential()))
                {
                    $evidenceRecord = null; // Set to null if evidence is confidential or not available
                }

                $fileAttachments = [];
                if($evidenceRecord !== null)
                {
                    // We automatically include the file attachments if the evidence record exists, because the
                    // above check already checks for existence and confidentiality
                    $fileAttachments = FileAttachmentManager::getRecordsByEvidence($evidenceRecord->getUuid());
                }

                $queriedBlacklistRecords[] = new QueriedBlacklistRecord($blacklistRecord, $evidenceRecord, $fileAttachments);
            }

            // Finally return the full EntityQueryResult object
            return new EntityQueryResult(
                $entityRecord, $queriedBlacklistRecords,
                EvidenceManager::getEvidenceByEntity($entityRecord->getUuid()),
                AuditLogManager::getEntriesByEntity($entityRecord->getUuid())
            );
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
         * Returns True if caching & entity caching is enabled for this class
         *
         * @return bool True if caching & caching for entities is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEntitiesCacheEnabled();
        }
    }
