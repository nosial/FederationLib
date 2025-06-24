<?php

    namespace FederationServer\Classes;

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
         * @param string $prefix
         * @param int $limit
         * @return bool
         * @throws RedisException
         */
        public static function limitExceeded(string $prefix, int $limit): bool
        {
            if($limit <= 0)
            {
                return false;
            }

            return self::countKeys($prefix) >= $limit;
        }
    }
