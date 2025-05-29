<?php

    namespace FederationServer\Classes;

    use FederationServer\Classes\Configuration\DatabaseConfiguration;
    use FederationServer\Classes\Configuration\RedisConfiguration;

    class Configuration
    {
        private static ?\ConfigLib\Configuration $configuration = null;
        private static ?DatabaseConfiguration $databaseConfiguration = null;
        private static ?RedisConfiguration $redisConfiguration = null;

        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('federation_server');

            self::$configuration->setDefault('database.host', '127.0.0.1');
            self::$configuration->setDefault('database.port', 3306);
            self::$configuration->setDefault('database.username', 'root');
            self::$configuration->setDefault('database.password', 'root');
            self::$configuration->setDefault('database.name', 'federation');
            self::$configuration->setDefault('database.charset', 'utf8mb4');
            self::$configuration->setDefault('database.collation', 'utf8mb4_unicode_ci');

            self::$configuration->setDefault('redis.enabled', true);
            self::$configuration->setDefault('redis.host', '127.0.1');
            self::$configuration->setDefault('redis.port', 6379);
            self::$configuration->setDefault('redis.password', null);
            self::$configuration->setDefault('redis.database', 0);

            self::$configuration->save();

            self::$databaseConfiguration = new DatabaseConfiguration(self::$configuration->get('database'));
            self::$redisConfiguration = new RedisConfiguration(self::$configuration->get('redis'));
        }

        public static function getConfiguration(): array
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration->getConfiguration();
        }

        public static function getConfigurationLib(): \ConfigLib\Configuration
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration;
        }

        public static function getDatabaseConfiguration(): DatabaseConfiguration
        {
            if(self::$databaseConfiguration === null)
            {
                self::initialize();
            }

            return self::$databaseConfiguration;
        }

        public static function getRedisConfiguration(): RedisConfiguration
        {
            if(self::$redisConfiguration === null)
            {
                self::initialize();
            }

            return self::$redisConfiguration;
        }
    }