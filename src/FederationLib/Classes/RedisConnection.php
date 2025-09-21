<?php

    namespace FederationLib\Classes;

    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Interfaces\SerializableInterface;
    use InvalidArgumentException;
    use Redis;
    use RedisException;

    class RedisConnection
    {
        private static ?Redis $redis = null;

        /**
         * Get the Redis connection instance. If it does not exist, create it using the configuration.
         *
         * @return Redis|null Returns Redis instance if enabled, otherwise null.
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
        public static function limitReached(string $prefix, int $limit): bool
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
         * Clear all Redis keys that match a given prefix pattern.
         *
         * @param string $prefix The prefix to match keys against.
         * @return void
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function clearRecords(string $prefix): void
        {
            $redis = self::getConnection();

            if ($redis === null)
            {
                return;
            }

            try
            {
                // Ensure the prefix has a wildcard for pattern matching
                $pattern = $prefix . '*';
                $iterator = null;

                // Use SCAN to iterate through keys and delete them in batches
                do
                {
                    $keys = $redis->scan($iterator, $pattern, 100);
                    if ($keys !== false && count($keys) > 0)
                    {
                        $redis->del($keys);
                    }
                }
                while ($iterator !== 0);
            }
            catch (RedisException $e)
            {
                Logger::log()->error(sprintf("Failed to clear records with prefix '%s': %s", $prefix, $e->getMessage()), $e);
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to clear records with prefix '%s'", $prefix), $e->getCode(), $e);
                }
            }
        }

        /**
         * Retrieves a cached operator record by its UUID.
         *
         * @param SerializableInterface $record The operator record to cache.
         * @param string $cacheKey The cache key to use for storing the record.
         * @param int|null $ttl Optional expiration time in seconds for the cache key. If null, the default TTL will be used.
         * @param bool $overwrite
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function setRecord(SerializableInterface $record, string $cacheKey, ?int $ttl=null, bool $overwrite=true): void
        {
            if($ttl === null)
            {
                $ttl = 0;
            }

            try
            {
                if(self::recordExists($cacheKey))
                {
                    if(!$overwrite)
                    {
                        Logger::log()->debug(sprintf("Cache record with key '%s' already exists and overwrite is disabled, skipping", $cacheKey));
                        return;
                    }
                }

                Logger::log()->debug(sprintf("Caching record with '%s'", $cacheKey));
                RedisConnection::getConnection()->hMset($cacheKey, $record->toArray());

                // Set the cache expiration if configured
                if($ttl > 0)
                {
                    Logger::log()->debug(sprintf("Setting expiration for cache key '%s' to %d seconds", $cacheKey, Configuration::getRedisConfiguration()->getOperatorCacheTtl()));
                    RedisConnection::getConnection()->expire($cacheKey, $ttl);
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
        public static function recordExists(string $cacheKey): bool
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
         * @return array|null The cached operator record if found, null otherwise.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function getRecord(string $cacheKey): ?array
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
         * Retrieves all records matching a given prefix and field value.
         *
         * @param string $prefix The prefix to match keys against.
         * @param string $field The field name to filter records by.
         * @param mixed $value The value to match in the specified field.
         * @return array An array of matching records.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function getRecordsByField(string $prefix, string $field, mixed $value): array
        {
            $redis = self::getConnection();

            if ($redis === null)
            {
                return [];
            }

            $pattern = $prefix . '*';
            $iterator = null;
            $matchingRecords = [];

            try
            {
                // Use SCAN to iterate through keys and find matching records
                do
                {
                    $keys = $redis->scan($iterator, $pattern, 100);
                    if ($keys !== false && count($keys) > 0)
                    {
                        foreach ($keys as $key)
                        {
                            $record = $redis->hGetAll($key);
                            if (isset($record[$field]) && $record[$field] == $value)
                            {
                                $matchingRecords[] = $record;
                            }
                        }
                    }
                }
                while ($iterator !== 0);
            }
            catch (RedisException $e)
            {
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to retrieve records by field '%s' from the cache", $field), $e->getCode(), $e);
                }
            }

            return $matchingRecords;
        }

        /**
         * Deletes all records matching a given prefix and field value.
         *
         * @param string $prefix The prefix to match keys against.
         * @param string $field The field name to filter records by.
         * @param mixed $value The value to match in the specified field.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function deleteRecordsByField(string $prefix, string $field, mixed $value): void
        {
            $redis = self::getConnection();
            if ($redis === null)
            {
                return;
            }

            $pattern = $prefix . '*';
            $iterator = null;
            $deletedCount = 0;

            try
            {
                // Use SCAN to iterate through keys and find matching records
                do
                {
                    $keys = $redis->scan($iterator, $pattern, 100);
                    if ($keys !== false && count($keys) > 0)
                    {
                        foreach ($keys as $key)
                        {
                            $record = $redis->hGetAll($key);
                            if (isset($record[$field]) && $record[$field] == $value)
                            {
                                $redis->del($key);
                                $deletedCount++;
                            }
                        }
                    }
                }
                while ($iterator !== 0);
            }
            catch (RedisException $e)
            {
                if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                {
                    throw new CacheOperationException(sprintf("Failed to delete records by field '%s' from the cache", $field), $e->getCode(), $e);
                }
            }

            Logger::log()->debug(sprintf("Deleted %d records with field '%s' equal to '%s' from cache", $deletedCount, $field, (string)$value));
        }

        /**
         * Caches multiple records with intelligent limit handling.
         *
         * @param SerializableInterface[] $records Array of records to cache.
         * @param string $prefix The cache prefix to use.
         * @param string $propertyName
         * @param int $limit The maximum number of records allowed in cache (0 = no limit).
         * @param int|null $ttl Optional TTL for cached records.
         * @return int The number of records actually cached.
         * @throws CacheOperationException If there is an error during the operation.
         */
        public static function setRecords(array $records, string $prefix, string $propertyName, int $limit=0, ?int $ttl=null): int
        {
            if (empty($records))
            {
                return 0;
            }

            $cached = 0;

            // Check if the propertyName method exists on the record
            $firstRecord = reset($records);
            if (!method_exists($firstRecord, $propertyName))
            {
                throw new InvalidArgumentException(sprintf("Property method '%s' does not exist on the record class", $propertyName));
            }

            if ($limit === 0)
            {
                // No limit, cache all records
                foreach ($records as $record)
                {
                    if (!$record instanceof SerializableInterface)
                    {
                        continue;
                    }

                    // Get the unique identifier value by dynamically calling the method
                    self::setRecord($record, sprintf('%s%s', $prefix, $record->$propertyName()), $ttl);
                    $cached++;
                }
            }
            else
            {
                // Calculate available space
                $currentCount = self::countKeys($prefix);
                $availableSpace = max(0, $limit - $currentCount);

                if ($availableSpace > 0)
                {
                    // Cache only up to available space
                    $recordsToCache = array_slice($records, 0, $availableSpace);
                    foreach ($recordsToCache as $record)
                    {
                        if (!$record instanceof SerializableInterface)
                        {
                            continue;
                        }

                        // Get the unique identifier value by dynamically calling the method
                        self::setRecord($record, sprintf('%s%s', $prefix, $record->$propertyName()), $ttl);
                        $cached++;
                    }
                }
            }

            return $cached;
        }

    }
