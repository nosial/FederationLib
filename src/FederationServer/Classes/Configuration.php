<?php

    namespace FederationServer\Classes;

    use FederationServer\Classes\Configuration\DatabaseConfiguration;
    use FederationServer\Classes\Configuration\RedisConfiguration;
    use FederationServer\Classes\Configuration\ServerConfiguration;
    use FederationServer\Classes\Enums\AuditLogType;

    class Configuration
    {
        private static ?ServerConfiguration $serverConfiguration = null;
        private static ?\ConfigLib\Configuration $configuration = null;
        private static ?DatabaseConfiguration $databaseConfiguration = null;
        private static ?RedisConfiguration $redisConfiguration = null;

        /**
         * Initialize the configuration with default values.
         */
        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('federation_server');

            self::$configuration->setDefault('server.base_url', 'http://127.0.0.1:6161');
            self::$configuration->setDefault('server.name', 'Federation Server');
            self::$configuration->setDefault('server.api_key', Utilities::generateString());
            self::$configuration->setDefault('server.max_upload_size', 52428800); // 50MB default
            self::$configuration->setDefault('server.storage_path', '/var/www/uploads');
            self::$configuration->setDefault('server.list_audit_logs_max_items', 100);
            self::$configuration->setDefault('server.list_entities_max_items', 100);
            self::$configuration->setDefault('server.list_operators_max_items', 100);
            self::$configuration->setDefault('server.list_evidence_max_items', 100);
            self::$configuration->setDefault('server.list_blacklist_max_items', 100);
            self::$configuration->setDefault('server.public_audit_logs', true);
            self::$configuration->setDefault('server.public_audit_entries', array_map(fn($type) => $type->value, AuditLogType::cases()));
            self::$configuration->setDefault('server.public_evidence', true);
            self::$configuration->setDefault('server.public_blacklist', true);
            self::$configuration->setDefault('server.public_entities', true);
            self::$configuration->setDefault('server.min_blacklist_time', 1800);

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

            self::$serverConfiguration = new ServerConfiguration(self::$configuration->get('server'));
            self::$databaseConfiguration = new DatabaseConfiguration(self::$configuration->get('database'));
            self::$redisConfiguration = new RedisConfiguration(self::$configuration->get('redis'));
        }

        /**
         * Get the configuration values.
         *
         * @return array
         */
        public static function getConfiguration(): array
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration->getConfiguration();
        }

        /**
         * Get the configuration library instance.
         *
         * @return \ConfigLib\Configuration
         */
        public static function getConfigurationLib(): \ConfigLib\Configuration
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration;
        }

        /**
         * Get the server configuration.
         *
         * @return ServerConfiguration
         */
        public static function getServerConfiguration(): ServerConfiguration
        {
            if(self::$serverConfiguration === null)
            {
                self::initialize();
            }

            return self::$serverConfiguration;
        }

        /**
         * Get the database, Redis, and file storage configurations.
         *
         * @return DatabaseConfiguration
         */
        public static function getDatabaseConfiguration(): DatabaseConfiguration
        {
            if(self::$databaseConfiguration === null)
            {
                self::initialize();
            }

            return self::$databaseConfiguration;
        }

        /**
         * Get the Redis configuration.
         *
         * @return RedisConfiguration
         */
        public static function getRedisConfiguration(): RedisConfiguration
        {
            if(self::$redisConfiguration === null)
            {
                self::initialize();
            }

            return self::$redisConfiguration;
        }
    }

