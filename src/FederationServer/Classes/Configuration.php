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
            self::$configuration = new \ConfigLib\Configuration('federation');

            self::$configuration->setDefault('server.base_url', 'http://127.0.0.1:6161', 'FEDERATION_BASE_URL');
            self::$configuration->setDefault('server.name', 'Federation Server', 'FEDERATION_NAME');
            self::$configuration->setDefault('server.api_key', Utilities::generateString(), 'FEDERATION_API_KEY');
            self::$configuration->setDefault('server.max_upload_size', 52428800, 'FEDERATION_MAX_UPLOAD_SIZE'); // 50MB default
            self::$configuration->setDefault('server.storage_path', '/var/www/uploads', 'FEDERATION_STORAGE_PATH');
            self::$configuration->setDefault('server.list_audit_logs_max_items', 100, 'FEDERATION_LIST_AUDIT_LOGS_MAX_ITEMS');
            self::$configuration->setDefault('server.list_entities_max_items', 100, 'FEDERATION_LIST_ENTITIES_MAX_ITEMS');
            self::$configuration->setDefault('server.list_operators_max_items', 100, 'FEDERATION_LIST_OPERATORS_MAX_ITEMS');
            self::$configuration->setDefault('server.list_evidence_max_items', 100, 'FEDERATION_LIST_EVIDENCE_MAX_ITEMS');
            self::$configuration->setDefault('server.list_blacklist_max_items', 100, 'FEDERATION_LIST_BLACKLIST_MAX_ITEMS');
            self::$configuration->setDefault('server.public_audit_logs', true, 'FEDERATION_PUBLIC_AUDIT_LOGS');
            self::$configuration->setDefault('server.public_audit_entries', array_map(fn($type) => $type->value, AuditLogType::getDefaultPublic()));
            self::$configuration->setDefault('server.public_evidence', true, 'FEDERATION_PUBLIC_EVIDENCE');
            self::$configuration->setDefault('server.public_blacklist', true, 'FEDERATION_PUBLIC_BLACKLIST');
            self::$configuration->setDefault('server.public_entities', true, 'FEDERATION_PUBLIC_ENTITIES');
            self::$configuration->setDefault('server.min_blacklist_time', 1800, 'FEDERATION_MIN_BLACKLIST_TIME');

            self::$configuration->setDefault('database.host', '127.0.0.1', 'FEDERATION_DATABASE_HOST');
            self::$configuration->setDefault('database.port', 3306, 'FEDERATION_DATABASE_PORT');
            self::$configuration->setDefault('database.username', 'root', 'FEDERATION_DATABASE_USERNAME');
            self::$configuration->setDefault('database.password', 'root', 'FEDERATION_DATABASE_PASSWORD');
            self::$configuration->setDefault('database.name', 'federation', 'FEDERATION_DATABASE_NAME');
            self::$configuration->setDefault('database.charset', 'utf8mb4', 'FEDERATION_DATABASE_CHARSET');
            self::$configuration->setDefault('database.collation', 'utf8mb4_unicode_ci', 'FEDERATION_DATABASE_COLLATION');

            self::$configuration->setDefault('redis.enabled', false, 'FEDERATION_REDIS_ENABLED');
            self::$configuration->setDefault('redis.host', '127.0.1', 'FEDERATION_REDIS_HOST');
            self::$configuration->setDefault('redis.port', 6379, 'FEDERATION_REDIS_PORT');
            self::$configuration->setDefault('redis.password', null, 'FEDERATION_REDIS_PASSWORD');
            self::$configuration->setDefault('redis.database', 0, 'FEDERATION_REDIS_DATABASE');
            self::$configuration->setDefault('redis.throw_on_errors', true, 'FEDERATION_CACHE_THROW_ON_ERRORS');
            // If enabled, some methods will attempt to pre-cache objects before they are called.
            self::$configuration->setDefault('redis.pre_cache_enabled', true, 'FEDERATION_PRE_CACHE_ENABLED');
            // Operators cache
            self::$configuration->setDefault('redis.operator_cache_enabled', true, 'FEDERATION_OPERATOR_CACHE_ENABLED');
            self::$configuration->setDefault('redis.operator_cache_limit', 1000, 'FEDERATION_OPERATOR_CACHE_LIMIT');
            self::$configuration->setDefault('redis.operator_cache_ttl', 600, 'FEDERATION_OPERATOR_CACHE_TTL');
            // Entities cache
            self::$configuration->setDefault('redis.entity_cache_enabled', true, 'FEDERATION_ENTITY_CACHE_ENABLED');
            self::$configuration->setDefault('redis.entity_cache_limit', 5000, 'FEDERATION_ENTITY_CACHE_LIMIT');
            self::$configuration->setDefault('redis.entity_cache_ttl', 600, 'FEDERATION_ENTITY_CACHE_TTL');

            // Save
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

