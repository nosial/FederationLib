<?php

    namespace FederationServer\Classes;

    use FederationServer\Exceptions\CacheOperationException;
    use FederationServer\Interfaces\SerializableInterface;
    use FederationServer\Objects\Operator;
    use Redis;
    use RedisException;

    class RedisConnection
    {
        private static ?Redis $redis = null;

        /**
         * Get the Redis connection instance. If it does not exist, create it using the configuration.
         *
         * @return Redis|null Returns Redis instance if enabled, otherwise null.
         * @throws RedisException
         */
        public static function getConnection(): ?Redis
        {
            if (self::$redis === null)
            {
                $redisConfig = Configuration::getRedisConfiguration();

                if (!$redisConfig->isEnabled())
                {
                    return null;
                }

                $redis = new Redis();
                $redis->connect($redisConfig->getHost(), $redisConfig->getPort());
                if ($redisConfig->getPassword() !== null)
                {
                    $redis->auth($redisConfig->getPassword());
                }
                $redis->select($redisConfig->getDatabase());

                self::$redis = $redis;
            }

            return self::$redis;
        }

        /**
         * Count Redis keys that match a given prefix pattern.
         *
         * @param string $prefix The prefix to match keys against.
         * @return int The number of keys matching the prefix.
         * @throws RedisException If there is an error connecting to Redis.
         */
        public static function countKeys(string $prefix): int
        {
            $redis = self::getConnection();

            if ($redis === null)
            {
                return 0;
            }

            // Ensure the prefix has a wildcard for pattern matching
            $pattern = $prefix . '*';
            $count = 0;
            $iterator = null;

            // Use SCAN for efficient iteration without storing keys in memory
            do
            {
                $keys = $redis->scan($iterator, $pattern, 100);
                if ($keys !== false)
                {
                    $count += count($keys);
                }
            }
            while ($iterator !== 0);
            return $count;
        }

        /**
         * Check if the number of keys with a given prefix exceeds a specified limit.
         *
         * @param string $prefix The prefix to check against.
         * @param int $limit The maximum number of keys allowed.
         * @return bool True if the limit is exceeded, false otherwise.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function limitExceeded(string $prefix, int $limit): bool
        {
            if($limit <= 0)
            {
                return false;
            }

            try
            {
                return self::countKeys($prefix) >= $limit;
            }
            catch (RedisException $e)
            {
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to check key limit for prefix '%s'", $prefix), $e->getCode(), $e);
                }

                return false; // If the operation fails, we assume the limit is not exceeded
            }
        }

        /**
         * Retrieves a cached operator record by its UUID.
         *
         * @param SerializableInterface $record The operator record to cache.
         * @param string $cacheKey The cache key to use for storing the record.
         * @param int|null $expires Optional expiration time in seconds for the cache key. If null, the default TTL will be used.
         * @return void
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function setCacheRecord(SerializableInterface $record, string $cacheKey, ?int $expires=null): void
        {
            if($expires === null)
            {
                $expires = 0;
            }

            try
            {
                if(self::cacheRecordExists($cacheKey))
                {
                    return; // If the record is already cached, skip setting it again
                }

                Logger::log()->debug(sprintf("Caching record with '%s'", $cacheKey));
                RedisConnection::getConnection()->hMSet($cacheKey, $record->toArray());

                // Set the cache expiration if configured
                if($expires > 0)
                {
                    Logger::log()->debug(sprintf("Setting expiration for cache key '%s' to %d seconds", $cacheKey, Configuration::getRedisConfiguration()->getOperatorCacheTtl()));
                    RedisConnection::getConnection()->expire($cacheKey, $expires);
                }
            }
            catch (RedisException $e)
            {
                Logger::log()->error(sprintf("Failed to cache record with '%s': %s", $cacheKey, $e->getMessage()), $e);
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to cache record with '%s'", $cacheKey), 0, $e);
                }
            }
        }

        /**
         * Returns True if the record with the given cache key exists in Redis, otherwise False.
         *
         * @param string $cacheKey The cache key to check for existence.
         * @return bool True if the record exists, False otherwise.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function cacheRecordExists(string $cacheKey): bool
        {
            try
            {
                return RedisConnection::getConnection()->exists($cacheKey) > 0;
            }
            catch (RedisException $e)
            {
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to check cache for '%s'", $cacheKey), $e->getCode(), $e);
                }

                return false; // If the cache operation fails, we assume the record does not exist
            }
        }

        /**
         * Retrieves a cached operator record by its UUID.
         *
         * @param string $cacheKey The cache key to retrieve the operator record from.
         * @return Operator|null The cached operator record if found, null otherwise.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function getRecordFromCache(string $cacheKey): ?array
        {
            try
            {
                if (RedisConnection::getConnection()->exists($cacheKey))
                {
                    return RedisConnection::getConnection()->hGetAll($cacheKey);
                }
            }
            catch (RedisException $e)
            {
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to retrieve record data of '%s' from the cache", $cacheKey), $e->getCode(), $e);
                }
            }

            return null;
        }

        /**
         * Updates an existing cache record by updating a specific field
         *
         * @param string $cacheKey The cache key
         * @param string $field The field to update
         * @param mixed $value The value to update to
         * @return bool Returns True if the update was a success, False otherwise
         * @throws CacheOperationException Thrown if there was an error during the operation
         */
        public static function updateCacheRecord(string $cacheKey, string $field, mixed $value): bool
        {
            try
            {
                if (RedisConnection::getConnection()->exists($cacheKey))
                {
                    Logger::log()->debug(sprintf("Updating cache for record with '%s'", $cacheKey));
                    RedisConnection::getConnection()->hSet($cacheKey, $field, $value);
                    return true;
                }
            }
            catch (RedisException $e)
            {
                Logger::log()->error(sprintf("Failed to update cache for record with '%s': %s", $cacheKey, $e->getMessage()), $e);
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to update cache for record with '%s'", $cacheKey), 0, $e);
                }
            }

            return false;
        }
    }
