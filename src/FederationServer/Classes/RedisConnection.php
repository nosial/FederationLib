<?php

    namespace FederationServer\Classes;

    use Redis;
    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Configuration\RedisConfiguration;

    class RedisConnection
    {
        private static ?Redis $redis = null;

        /**
         * Get the Redis connection instance. If it does not exist, create it using the configuration.
         *
         * @return Redis|null Returns Redis instance if enabled, otherwise null.
         * @throws \RedisException
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
    }
