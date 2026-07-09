<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Configuration\BayesianConfiguration;
    use FederationLib\Classes\Configuration\DatabaseConfiguration;
    use FederationLib\Classes\Configuration\MaintenanceConfiguration;
    use FederationLib\Classes\Configuration\RedisConfiguration;
    use FederationLib\Classes\Configuration\ScanningConfiguration;
    use FederationLib\Classes\Configuration\ServerConfiguration;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ScanningRules;

    class Configuration
    {
        private static ?\ConfigLib\Configuration $configuration = null;
        private static ?ServerConfiguration $serverConfiguration = null;
        private static ?ScanningConfiguration $scanningConfiguration = null;
        private static ?BayesianConfiguration $bayesianConfiguration = null;
        private static ?DatabaseConfiguration $databaseConfiguration = null;
        private static ?MaintenanceConfiguration $maintenanceConfiguration = null;
        private static ?RedisConfiguration $redisConfiguration = null;

        /**
         * Initialize the configuration with default values.
         */
        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('federation');

            self::$configuration->setDefault('server.base_url', 'http://127.0.0.1:7000', 'FEDERATION_BASE_URL');
            self::$configuration->setDefault('server.name', 'Federation Server', 'FEDERATION_NAME');
            self::$configuration->setDefault('server.access_token', Utilities::generateString(), 'FEDERATION_ACCESS_TOKEN');
            self::$configuration->setDefault('server.max_upload_size', 52428800, 'FEDERATION_MAX_UPLOAD_SIZE'); // 50MB default
            self::$configuration->setDefault('server.storage_path', '/var/www/uploads', 'FEDERATION_STORAGE_PATH');
            self::$configuration->setDefault('server.list_audit_logs_max_items', 100, 'FEDERATION_LIST_AUDIT_LOGS_MAX_ITEMS');
            self::$configuration->setDefault('server.list_entities_max_items', 100, 'FEDERATION_LIST_ENTITIES_MAX_ITEMS');
            self::$configuration->setDefault('server.list_operators_max_items', 100, 'FEDERATION_LIST_OPERATORS_MAX_ITEMS');
            self::$configuration->setDefault('server.list_evidence_max_items', 100, 'FEDERATION_LIST_EVIDENCE_MAX_ITEMS');
            self::$configuration->setDefault('server.list_blacklist_max_items', 100, 'FEDERATION_LIST_BLACKLIST_MAX_ITEMS');
            self::$configuration->setDefault('server.list_reports_max_items', 100, 'FEDERATION_LIST_REPORTS_MAX_ITEMS');
            self::$configuration->setDefault('server.public_audit_logs', true, 'FEDERATION_PUBLIC_AUDIT_LOGS');
            self::$configuration->setDefault('server.public_audit_entries', array_map(fn($type) => $type->value, AuditLogType::getDefaultPublic()));
            self::$configuration->setDefault('server.public_evidence', true, 'FEDERATION_PUBLIC_EVIDENCE');
            self::$configuration->setDefault('server.public_blacklist', true, 'FEDERATION_PUBLIC_BLACKLIST');
            self::$configuration->setDefault('server.public_entities', true, 'FEDERATION_PUBLIC_ENTITIES');
            self::$configuration->setDefault('server.public_reports', true, 'FEDERATION_PUBLIC_REPORTS');
            self::$configuration->setDefault('server.public_scan_content', true, 'FEDERATION_PUBLIC_SCAN_CONTENT');
            self::$configuration->setDefault('server.min_blacklist_time', 1800, 'FEDERATION_MIN_BLACKLIST_TIME');

            // Scanning configuration
            self::$configuration->setDefault('scanning.default_rosl_score', 0.0, 'FEDERATION_SCORING_RISK_DEFAULT');
            self::$configuration->setDefault('scanning.risk_score_steepness', 0.25, 'FEDERATION_SCANNING_RISK_AUTHOR_WHITELISTEDSCORE_STEEPNESS');
            self::$configuration->setDefault('scanning.reputation_update_interval', 900, 'FEDERATION_SCANNING_REPUTATION_UPDATE_INTERVAL');
            self::$configuration->setDefault('scanning.good_reputation_threshold', 50, 'FEDERATION_SCANNING_GOOD_REPUTATION_THRESHOLD');
            self::$configuration->setDefault('scanning.bad_reputation_threshold', -50, 'FEDERATION_SCANNING_BAD_REPUTATION_THRESHOLD');
            self::$configuration->setDefault('scanning.author_blacklisted', ScanningRules::AUTHOR_BLACKLISTED->getModifier(), 'FEDERATION_SCANNING_AUTHOR_BLACKLISTED');
            self::$configuration->setDefault('scanning.author_permanently_blacklisted', ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED->getModifier(), 'FEDERATION_SCANNING_AUTHOR_PERMANENTLY_BLACKLISTED');
            self::$configuration->setDefault('scanning.author_whitelisted', ScanningRules::AUTHOR_WHITELISTED->getModifier(), 'FEDERATION_SCANNING_AUTHOR_WHITELISTED');
            self::$configuration->setDefault('scanning.named_entity_blacklisted', ScanningRules::NAMED_ENTITY_BLACKLISTED->getModifier(), 'FEDERATION_SCANNING_NAMED_ENTITY_BLACKLISTED');
            self::$configuration->setDefault('scanning.named_entity_permanently_blacklisted', ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED->getModifier(), 'FEDERATION_SCANNING_NAMED_ENTITY_PERMANENTLY_BLACKLISTED');
            self::$configuration->setDefault('scanning.named_entity_whitelisted', ScanningRules::NAMED_ENTITY_WHITELISTED->getModifier(), 'FEDERATION_SCANNING_NAMED_ENTITY_WHITELISTED');
            self::$configuration->setDefault('scanning.author_good_reputation', ScanningRules::AUTHOR_GOOD_REPUTATION->getModifier(), 'FEDERATION_SCANNING_AUTHOR_GOOD_REPUTATION');
            self::$configuration->setDefault('scanning.author_bad_reputation', ScanningRules::AUTHOR_BAD_REPUTATION->getModifier(), 'FEDERATION_SCANNING_AUTHOR_BAD_REPUTATION');
            self::$configuration->setDefault('scanning.named_entity_bad_repuation', ScanningRules::NAMED_ENTITY_BAD_REPUTATION->getModifier(), 'FEDERATION_SCANNING_NAMED_ENTITY_BAD_REPUTATION');
            self::$configuration->setDefault('scanning.named_entity_good_repuation', ScanningRules::NAMED_ENTITY_GOOD_REPUTATION->getModifier(), 'FEDERATION_SCANNING_NAMED_ENTITY_GOOD_REPUTATION');
            self::$configuration->setDefault('scanning.classification_normal', ScanningRules::CLASSIFICATION_NORMAL->getModifier(), 'FEDERATION_SCANNING_CLASSIFICATION_NORMAL');
            self::$configuration->setDefault('scanning.classification_suspicious', ScanningRules::CLASSIFICATION_SUSPICIOUS->getModifier(), 'FEDERATION_SCANNING_CLASSIFICATION_SUSPICIOUS');
            self::$configuration->setDefault('scanning.classification_malicious', ScanningRules::CLASSIFICATION_MALICIOUS->getModifier(), 'FEDERATION_SCANNING_CLASSIFICATION_MALICIOUS');
            self::$configuration->setDefault('scanning.auto_report', true, 'FEDERATION_SCANNING_AUTO_REPORT');
            self::$configuration->setDefault('scanning.auto_report_threshold', 30.00, 'FEDERATION_SCANNING_AUTO_REPORT_THRESHOLD');
            self::$configuration->setDefault('scanning.reputation_window_duration', 300, 'FEDERATION_SCANNING_REPUTATION_WINDOW_DURATION');
            self::$configuration->setDefault('scanning.reputation_max_delta', 10, 'FEDERATION_SCANNING_REPUTATION_MAX_DELTA');
            self::$configuration->setDefault('scanning.reputation_min_delta', -10, 'FEDERATION_SCANNING_REPUTATION_MIN_DELTA');
            self::$configuration->setDefault('scanning.reputation_scaling_factor', 0.25, 'FEDERATION_SCANNING_REPUTATION_SCALING_FACTOR');

            // Bayesian filter configuration
            self::$configuration->setDefault('bayesian.enabled', true, 'FEDERATION_BS_ENABLED');
            self::$configuration->setDefault('bayesian.ssl', false, 'FEDERATION_BS_SSL');
            self::$configuration->setDefault('bayesian.host', '127.0.0.1', 'FEDERATION_BS_HOST');
            self::$configuration->setDefault('bayesian.port', 6380, 'FEDERATION_BS_PORT');
            self::$configuration->setDefault('bayesian.classify_known_tokens', true, 'FEDERATION_BS_CLASSIFY_KNOWN_TOKENS');

            // Maintenance configuration
            self::$configuration->setDefault('maintenance.enabled', true, 'FEDERATION_MAINTENANCE_ENABLED');
            self::$configuration->setDefault('maintenance.clean_audit_logs', true, 'FEDERATION_MAINTENANCE_CLEAN_AUDIT_LOGS');
            self::$configuration->setDefault('maintenance.clean_audit_logs_ttl', 63072000, 'FEDERATION_MAINTENANCE_CLEAN_AUDIT_LOGS_TTL'); // 2 years
            self::$configuration->setDefault('maintenance.clean_blacklist', true, 'FEDERATION_MAINTENANCE_CLEAN_BLACKLIST');
            self::$configuration->setDefault('maintenance.clean_blacklist_ttl', 31536000, 'FEDERATION_MAINTENANCE_CLEAN_BLACKLIST_TTL'); // 1 year
            self::$configuration->setDefault('maintenance.clean_evidence', true, 'FEDERATION_MAINTENANCE_CLEAN_EVIDENCE');
            self::$configuration->setDefault('maintenance.clean_evidence_ttl', 63072000, 'FEDERATION_MAINTENANCE_CLEAN_EVIDENCE_TTL'); // 2 years
            self::$configuration->setDefault('maintenance.clean_reports', true, 'FEDERATION_MAINTENANCE_CLEAN_REPORTS');
            self::$configuration->setDefault('maintenance.clean_reports_ttl', 63072000, 'FEDERATION_MAINTENANCE_CLEAN_REPORTS_TTL'); // 2 years
            self::$configuration->setDefault('maintenance.clean_file_attachments', true, 'FEDERATION_MAINTENANCE_CLEAN_FILE_ATTACHMENTS');
            self::$configuration->setDefault('maintenance.clean_file_attachments_ttl', 63072000, 'FEDERATION_MAINTENANCE_CLEAN_FILE_ATTACHMENTS_TTL'); // 2 years
            self::$configuration->setDefault('maintenance.clean_entities', false, 'FEDERATION_MAINTENANCE_CLEAN_ENTITIES');
            self::$configuration->setDefault('maintenance.clean_entities_ttl', 63072000, 'FEDERATION_MAINTENANCE_CLEAN_ENTITIES_TTL'); // 2 years

            // Database configuration
            self::$configuration->setDefault('database.host', '127.0.0.1', 'FEDERATION_DATABASE_HOST');
            self::$configuration->setDefault('database.port', 3306, 'FEDERATION_DATABASE_PORT');
            self::$configuration->setDefault('database.username', 'root', 'FEDERATION_DATABASE_USERNAME');
            self::$configuration->setDefault('database.password', 'root', 'FEDERATION_DATABASE_PASSWORD');
            self::$configuration->setDefault('database.name', 'federation', 'FEDERATION_DATABASE_NAME');
            self::$configuration->setDefault('database.charset', 'utf8mb4', 'FEDERATION_DATABASE_CHARSET');
            self::$configuration->setDefault('database.collation', 'utf8mb4_unicode_ci', 'FEDERATION_DATABASE_COLLATION');

            // Redis caching configuration
            self::$configuration->setDefault('redis.enabled', false, 'FEDERATION_REDIS_ENABLED');
            self::$configuration->setDefault('redis.host', '127.0.0.1', 'FEDERATION_REDIS_HOST');
            self::$configuration->setDefault('redis.port', 6379, 'FEDERATION_REDIS_PORT');
            self::$configuration->setDefault('redis.password', null, 'FEDERATION_REDIS_PASSWORD');
            self::$configuration->setDefault('redis.database', 0, 'FEDERATION_REDIS_DATABASE');
            self::$configuration->setDefault('redis.throw_on_errors', true, 'FEDERATION_CACHE_THROW_ON_ERRORS');
            // If enabled, some methods will attempt to pre-cache objects before they are called.
            self::$configuration->setDefault('redis.pre_cache_enabled', true, 'FEDERATION_PRE_CACHE_ENABLED');
            // If enabled, very specific system-related properties are cached for a slight performance increase
            self::$configuration->setDefault('redis.system_caching_enabled', true, 'FEDERATION_SYSTEM_CACHING_ENABLED');
            // Operators cache
            self::$configuration->setDefault('redis.operator_cache_enabled', true, 'FEDERATION_OPERATOR_CACHE_ENABLED');
            self::$configuration->setDefault('redis.operator_cache_limit', 1000, 'FEDERATION_OPERATOR_CACHE_LIMIT');
            self::$configuration->setDefault('redis.operator_cache_ttl', 600, 'FEDERATION_OPERATOR_CACHE_TTL');
            // Entities cache
            self::$configuration->setDefault('redis.entity_cache_enabled', true, 'FEDERATION_ENTITY_CACHE_ENABLED');
            self::$configuration->setDefault('redis.entity_cache_limit', 5000, 'FEDERATION_ENTITY_CACHE_LIMIT');
            self::$configuration->setDefault('redis.entity_cache_ttl', 600, 'FEDERATION_ENTITY_CACHE_TTL');
            // File Attachments cache
            self::$configuration->setDefault('redis.file_attachment_cache_enabled', true, 'FEDERATION_FILE_ATTACHMENT_CACHE_ENABLED');
            self::$configuration->setDefault('redis.file_attachment_cache_limit', 2000, 'FEDERATION_FILE_ATTACHMENT_CACHE_LIMIT');
            self::$configuration->setDefault('redis.file_attachment_cache_ttl', 600, 'FEDERATION_FILE_ATTACHMENT_CACHE_TTL');
            // Evidence cache
            self::$configuration->setDefault('redis.evidence_cache_enabled', true, 'FEDERATION_EVIDENCE_CACHE_ENABLED');
            self::$configuration->setDefault('redis.evidence_cache_limit', 3000, 'FEDERATION_EVIDENCE_CACHE_LIMIT');
            self::$configuration->setDefault('redis.evidence_cache_ttl', 600, 'FEspiciousDERATION_EVIDENCE_CACHE_TTL');
            // Reports cache
            self::$configuration->setDefault('redis.report_cache_enabled', true, 'FEDERATION_REPORT_CACHE_ENABLED');
            self::$configuration->setDefault('redis.report_cache_limit', 1000, 'FEDERATION_REPORT_CACHE_LIMIT');
            self::$configuration->setDefault('redis.report_cache_ttl', 600, 'FEDERATION_REPORT_CACHE_TTL');

            // Only save if the configuration file does not exist or we're in CLI mode
            if(!file_exists(self::$configuration->getPath()) || php_sapi_name() === 'cli')
            {
                self::$configuration->save();
            }

            // Initialize the configuration classes
            self::$serverConfiguration = new ServerConfiguration(self::$configuration->get('server'));
            self::$scanningConfiguration = new ScanningConfiguration(self::$configuration->get('scanning'));
            self::$bayesianConfiguration = new BayesianConfiguration(self::$configuration->get('bayesian'));
            self::$databaseConfiguration = new DatabaseConfiguration(self::$configuration->get('database'));
            self::$redisConfiguration = new RedisConfiguration(self::$configuration->get('redis'));
            self::$maintenanceConfiguration = new MaintenanceConfiguration(self::$configuration->get('maintenance'));
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
         * Get the scanning configuration.
         *
         * @return ScanningConfiguration
         */
        public static function getScanningConfiguration(): ScanningConfiguration
        {
            if(self::$scanningConfiguration === null)
            {
                self::initialize();
            }

            return self::$scanningConfiguration;
        }

        /**
         * Get the BayesianServer configuration
         *
         * @return BayesianConfiguration
         */
        public static function getBayesianConfiguration(): BayesianConfiguration
        {
            if(self::$bayesianConfiguration === null)
            {
                self::initialize();
            }

            return self::$bayesianConfiguration;
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

        /**
         * Get the maintenance configuration.
         *
         * @return MaintenanceConfiguration
         */
        public static function getMaintenanceConfiguration(): MaintenanceConfiguration
        {
            if(self::$maintenanceConfiguration === null)
            {
                self::initialize();
            }

            return self::$maintenanceConfiguration;
        }
    }

