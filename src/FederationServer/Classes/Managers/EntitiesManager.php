<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\RedisConnection;
    use FederationServer\Exceptions\CacheOperationException;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\EntityRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;
    use RedisException;
    use Throwable;

    class EntitiesManager
    {
        private const string ENTITY_CACHE_PREFIX = 'entity_';

        /**
         * Registers a new entity with the given ID and domain.
         *
         * @param string $id The ID of the entity.
         * @param string|null $domain The domain of the entity, can be null.
         * @throws InvalidArgumentException If the ID exceeds 255 characters or if the domain is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function registerEntity(string $id, ?string $domain=null): string
        {
            if(strlen($id) > 255)
            {
                throw new InvalidArgumentException("Entity ID cannot exceed 255 characters.");
            }
            if(!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && $domain !== null)
            {
                throw new InvalidArgumentException("Invalid domain format.");
            }
            if(strlen($domain) > 255)
            {
                throw new InvalidArgumentException("Domain cannot exceed 255 characters.");
            }

            $uuid = Uuid::v4()->toRfc4122();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO entities (uuid, hash, id, domain) VALUES (:uuid, :hash, :id, :domain)");
                $hash = hash('sha256', is_null($domain) ? $id : sprintf('%s@%s', $id, $domain));
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':hash', $hash);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':domain', $domain);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to register entity: " . $e->getMessage(), $e->getCode(), $e);
            }

            // Cache the entity if caching is enabled and pre-caching is enabled
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                // If the limit has not been exceeded, cache the entity record
                if (!RedisConnection::limitExceeded(self::ENTITY_CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
                {
                    $entity = self::getEntity($id, $domain);
                    if ($entity !== null)
                    {
                        RedisConnection::setCacheRecord($entity, self::getCacheKey($entity->getUuid()), Configuration::getRedisConfiguration()->getEntitiesCacheTtl());
                    }
                }
            }

            return $uuid;
        }

        /**
         * Retrieves an entity by its ID and domain.
         *
         * @param string $id The ID of the entity.
         * @param string|null $domain The domain of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getEntity(string $id, ?string $domain): ?EntityRecord
        {
            if(strlen($id) < 1)
            {
                throw new InvalidArgumentException("Entity ID and domain must be provided.");
            }

            if(!is_null($domain) && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            {
                throw new InvalidArgumentException("Invalid domain format.");
            }

            // Try cache first
            $hash = hash('sha256', is_null($domain) ? $id : sprintf('%s@%s', $id, $domain));
            if(self::isCachingEnabled())
            {
                if (RedisConnection::cacheRecordExists(self::getCacheKey($hash)))
                {
                    $cached = RedisConnection::getRecordFromCache(self::getCacheKey($hash));
                    if (is_array($cached) && !empty($cached))
                    {
                        return new EntityRecord($cached);
                    }
                }
            }

            try
            {
                if(is_null($domain))
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE id = :id");
                    $stmt->bindParam(':id', $id);
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE id = :id AND domain = :domain");
                    $stmt->bindParam(':id', $id);
                    $stmt->bindParam(':domain', $domain);
                }

                $stmt->execute();
                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if($data)
                {
                    $entity = new EntityRecord($data);
                    // Cache the entity
                    if(self::isCachingEnabled())
                    {
                        RedisConnection::setCacheRecord($entity, self::getCacheKey($entity->getHash()), Configuration::getRedisConfiguration()->getEntitiesCacheTtl());
                    }

                    return $entity;
                }

                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by domain: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getEntityByUuid(string $uuid): ?EntityRecord
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            if(self::isCachingEnabled())
            {
                if (RedisConnection::cacheRecordExists(self::getCacheKey($uuid)))
                {
                    $cached = RedisConnection::getRecordFromCache(self::getCacheKey($uuid));
                    if (is_array($cached) && !empty($cached))
                    {
                        return new EntityRecord($cached);
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data)
                {
                    $entity = new EntityRecord($data);
                    if(self::isCachingEnabled())
                    {
                        RedisConnection::setCacheRecord($entity, self::getCacheKey($entity->getUuid()), Configuration::getRedisConfiguration()->getEntitiesCacheTtl());
                    }
                    return $entity;
                }
                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves an entity by its SHA-256 hash.
         *
         * @param string $hash The SHA-256 hash of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getEntityByHash(string $hash): ?EntityRecord
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            if(self::isCachingEnabled())
            {
                if (RedisConnection::cacheRecordExists(self::getCacheKey($hash)))
                {
                    $cached = RedisConnection::getRecordFromCache(self::getCacheKey($hash));
                    if (is_array($cached) && !empty($cached))
                    {
                        return new EntityRecord($cached);
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE hash = :hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data)
                {
                    $entity = new EntityRecord($data);
                    if(self::isCachingEnabled())
                    {
                        RedisConnection::setCacheRecord($entity, self::getCacheKey($entity->getHash()), Configuration::getRedisConfiguration()->getEntitiesCacheTtl());
                    }
                    return $entity;
                }
                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by hash: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an entity exists by its ID and domain.
         *
         * @param string $id The ID of the entity.
         * @param string|null $domain The domain of the entity, can be null.
         * @return bool True if the entity exists, false otherwise.
         * @throws InvalidArgumentException If the ID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExists(string $id, ?string $domain): bool
        {
            if(strlen($id) < 1)
            {
                throw new InvalidArgumentException("Entity ID must be provided.");
            }

            if(!is_null($domain) && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            {
                throw new InvalidArgumentException("Invalid domain format.");
            }

            try
            {
                if(is_null($domain))
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE id = :id");
                    $stmt->bindParam(':id', $id);
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE id = :id AND domain = :domain");
                    $stmt->bindParam(':id', $id);
                    $stmt->bindParam(':domain', $domain);
                }

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
         * @throws CacheOperationException If there is an error during the caching operation.
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

            // Remove from cache
            if(self::isCachingEnabled())
            {
                try {
                    if (RedisConnection::cacheRecordExists(self::getCacheKey($uuid))) {
                        RedisConnection::getConnection()->del(self::getCacheKey($uuid));
                    }
                } catch (RedisException $e) {
                    throw new CacheOperationException("Failed to delete entity from cache", 0, $e);
                }
            }
        }

        /**
         * Deletes an entity by its ID and domain.
         *
         * @param string $id The ID of the entity to delete.
         * @param string $domain The domain of the entity to delete.
         * @throws InvalidArgumentException If the ID or domain is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function deleteEntityById(string $id, string $domain): void
        {
            if(strlen($id) < 1 || strlen($domain) < 1)
            {
                throw new InvalidArgumentException("Entity ID and domain must be provided.");
            }

            // Remove from cache
            $hash = hash('sha256', sprintf('%s@%s', $id, $domain));
            if(self::isCachingEnabled())
            {
                try
                {
                    if (RedisConnection::cacheRecordExists(self::getCacheKey($hash))) {
                        RedisConnection::getConnection()->del(self::getCacheKey($hash));
                    }
                } catch (RedisException $e) {
                    throw new CacheOperationException("Failed to delete entity by id/domain from cache", 0, $e);
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE id = :id AND domain = :domain");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':domain', $domain);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity by ID and domain: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes an entity by its hash.
         *
         * @param string $hash The SHA-256 hash of the entity to delete.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function deleteEntityByHash(string $hash): void
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            // Remove from cache
            if(self::isCachingEnabled())
            {
                try
                {
                    if (RedisConnection::cacheRecordExists(self::getCacheKey($hash)))
                    {
                        RedisConnection::getConnection()->del(self::getCacheKey($hash));
                    }
                }
                catch (RedisException $e)
                {
                    throw new CacheOperationException("Failed to delete entity by hash from cache", 0, $e);
                }
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
                return $entities;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entities: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Returns True if caching & entity caching is enabled for this class
         *
         * @return bool True if caching & caching for entities is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            // Ignore Configuration errors as requested
            try
            {
                return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEntitiesCacheEnabled();
            }
            catch (Throwable $e)
            {
                Logger::log()->error("Failed to check if caching is enabled: " . $e->getMessage(), $e);
                return false;
            }
        }

        /**
         * Returns the cache key based off the given entity UUID or hash
         *
         * @param string $key The Entity UUID or hash
         * @return string The returned cache key
         */
        private static function getCacheKey(string $key): string
        {
            return sprintf("%s%s", self::ENTITY_CACHE_PREFIX, $key);
        }
    }
