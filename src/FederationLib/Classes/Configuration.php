<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Configuration\BayesianModelConfiguration;
    use FederationLib\Classes\Configuration\ConfigurationValues;
    use FederationLib\Classes\Configuration\DatabaseConfiguration;
    use FederationLib\Classes\Configuration\MaintenanceConfiguration;
    use FederationLib\Classes\Configuration\PluginsConfiguration;
    use FederationLib\Classes\Configuration\RedisConfiguration;
    use FederationLib\Classes\Configuration\RemoteRetrievalConfiguration;
    use FederationLib\Classes\Configuration\ScanningConfiguration;
    use FederationLib\Classes\Configuration\SearchConfiguration;
    use FederationLib\Classes\Configuration\ServerConfiguration;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ScanningRules;

    class Configuration
    {
        private static ?\ConfigLib\Configuration $configuration = null;
        private static ?ServerConfiguration $serverConfiguration = null;
        private static ?RemoteRetrievalConfiguration $remoteRetrievalConfiguration = null;
        private static ?ScanningConfiguration $scanningConfiguration = null;
        private static ?DatabaseConfiguration $databaseConfiguration = null;
        private static ?MaintenanceConfiguration $maintenanceConfiguration = null;
        private static ?RedisConfiguration $redisConfiguration = null;
        private static ?SearchConfiguration $searchConfiguration = null;
        private static ?PluginsConfiguration $pluginsConfiguration = null;
        private static ?BayesianModelConfiguration $bayesianModelConfiguration = null;

        // Configuration of earlier releases is migrated by initialize() on every start: keys stored in the configuration
        // file are converted to the settings that replaced them and removed from it, and deprecated environment
        // variables override those settings, each with a warning in the log naming the replacement.

        /**
         * The old server.public_* flags and the item of server.public they became
         */
        private const array LEGACY_PUBLIC_FLAGS = [
            'public_audit_logs' => 'AUDIT_LOGS',
            'public_evidence' => 'EVIDENCE',
            'public_blacklist' => 'BLACKLIST',
            'public_entities' => 'ENTITIES',
            'public_entity_metadata' => 'ENTITY_METADATA',
            'public_reports' => 'REPORTS',
            'public_scan_content' => 'SCAN_CONTENT',
            'public_query_entity' => 'QUERY_ENTITY',
            'public_telephone_entities' => 'TELEPHONE_ENTITIES',
        ];

        /**
         * The old search.enable_* flags and the record type of search.types they became
         */
        private const array LEGACY_SEARCH_FLAGS = [
            'enable_entities' => 'ENTITY',
            'enable_evidence' => 'EVIDENCE',
            'enable_blacklist' => 'BLACKLIST',
            'enable_reports' => 'REPORT',
            'enable_attachments' => 'ATTACHMENT',
            'enable_audit_logs' => 'AUDIT_LOG',
            'enable_operators' => 'OPERATOR',
        ];

        /**
         * The old maintenance.clean_* names and the record type of maintenance.retention they became
         */
        private const array LEGACY_RETENTION_TYPES = [
            'audit_logs' => 'AUDIT_LOGS',
            'blacklist' => 'BLACKLIST',
            'evidence' => 'EVIDENCE',
            'reports' => 'REPORTS',
            'file_attachments' => 'FILE_ATTACHMENTS',
            'entities' => 'ENTITIES',
        ];

        /**
         * The old redis.<name>_cache_* names and the record type of redis.record_cache they became
         */
        private const array LEGACY_CACHE_TYPES = [
            'operator' => 'OPERATOR',
            'entity' => 'ENTITY',
            'file_attachment' => 'FILE_ATTACHMENT',
            'evidence' => 'EVIDENCE',
            'audit_log' => 'AUDIT_LOG',
            'blacklist' => 'BLACKLIST',
            'report' => 'REPORT',
        ];

        /**
         * The old list maxima, replaced by server.max_page_size
         */
        private const array LEGACY_LIST_MAXIMA = [
            'list_audit_logs_max_items', 'list_entities_max_items', 'list_operators_max_items', 'list_evidence_max_items',
            'list_blacklist_max_items', 'list_attachments_max_items', 'list_reports_max_items',
        ];

        /**
         * The keys that were removed without a replacement, their values are fixed
         */
        private const array LEGACY_REMOVED = [
            'scanning' => ['reputation_min_bound', 'reputation_max_bound', 'risk_score_neutral_point', 'risk_score_min_bound', 'risk_score_max_bound', 'collects_public_scan_content'],
            'database' => ['charset', 'collation'],
        ];

        /**
         * Converts the keys of earlier releases stored in the configuration. A setting that was already converted is
         * kept, the old keys are only removed.
         *
         * @param \ConfigLib\Configuration $configuration The configuration
         * @return string[] Descriptions of the converted keys, empty if nothing was converted
         */
        public static function migrateStoredKeys(\ConfigLib\Configuration $configuration): array
        {
            return self::convertLegacyKeys($configuration, $configuration->getConfiguration(), false);
        }

        /**
         * Converts the environment variables of earlier releases, they override the configuration like the
         * environment variables of the settings that replaced them
         *
         * @param \ConfigLib\Configuration $configuration The configuration, after its defaults were applied
         * @return string[] Descriptions of the converted environment variables, empty if none are set
         */
        public static function migrateEnvironment(\ConfigLib\Configuration $configuration): array
        {
            $data = $configuration->getConfiguration();
            $found = [];
            foreach(self::getLegacyEnvironment() as $variable => [$section, $key])
            {
                $value = getenv($variable);
                if($value === false || $value === '')
                {
                    continue;
                }

                $data[$section][$key] = $value;
                $found[] = $variable;
            }

            if(count($found) === 0)
            {
                return [];
            }

            self::convertLegacyKeys($configuration, $data, true);
            return array_map(fn(string $variable) => sprintf('The environment variable %s is deprecated, %s', $variable, self::describeLegacyReplacement($variable)), $found);
        }

        /**
         * Converts the old keys found in the configuration data and writes the converted sections
         *
         * @param \ConfigLib\Configuration $configuration The configuration to write to
         * @param array $data The configuration data, possibly with old keys set from the environment
         * @param bool $override True if old values override settings that were already converted (environment variables)
         * @return string[] Descriptions of the converted keys
         */
        private static function convertLegacyKeys(\ConfigLib\Configuration $configuration, array $data, bool $override): array
        {
            $notes = [];
            $server = is_array($data['server'] ?? null) ? $data['server'] : [];
            $scanning = is_array($data['scanning'] ?? null) ? $data['scanning'] : [];
            $search = is_array($data['search'] ?? null) ? $data['search'] : [];
            $maintenance = is_array($data['maintenance'] ?? null) ? $data['maintenance'] : [];
            $redis = is_array($data['redis'] ?? null) ? $data['redis'] : [];
            $database = is_array($data['database'] ?? null) ? $data['database'] : [];
            $remoteRetrieval = is_array($data['remote_retrieval'] ?? null) ? $data['remote_retrieval'] : [];
            $changed = [];

            // server.public_* flags and search.public_search -> server.public
            $flags = array_intersect_key($server, self::LEGACY_PUBLIC_FLAGS);
            if(count($flags) > 0 || array_key_exists('public_search', $search))
            {
                if($override || !array_key_exists('public', $server))
                {
                    $public = ConfigurationValues::parseList($server['public'] ?? null, ServerConfiguration::PUBLIC_ITEMS, ServerConfiguration::DEFAULT_PUBLIC);
                    foreach($flags as $key => $value)
                    {
                        $public = self::toggleListItem($public, self::LEGACY_PUBLIC_FLAGS[$key], ConfigurationValues::parseBool($value, false));
                    }
                    if(array_key_exists('public_search', $search))
                    {
                        $public = self::toggleListItem($public, 'SEARCH', ConfigurationValues::parseBool($search['public_search'], false));
                    }
                    $server['public'] = $public;
                }

                $server = array_diff_key($server, self::LEGACY_PUBLIC_FLAGS);
                unset($search['public_search']);
                $notes[] = 'server.public_* and search.public_search became server.public';
                $changed += ['server' => true, 'search' => true];
            }

            // server.list_*_max_items -> server.max_page_size, the largest so that no list becomes shorter
            $maxima = array_intersect_key($server, array_flip(self::LEGACY_LIST_MAXIMA));
            if(count($maxima) > 0)
            {
                if($override || !array_key_exists('max_page_size', $server))
                {
                    $server['max_page_size'] = max(array_map('intval', $maxima));
                }

                $server = array_diff_key($server, array_flip(self::LEGACY_LIST_MAXIMA));
                $notes[] = 'server.list_*_max_items became server.max_page_size';
                $changed['server'] = true;
            }

            // server.url_upload* and scanning.url_retrieval -> remote_retrieval
            foreach(['url_uploads' => 'uploads', 'url_upload_timeout' => 'timeout', 'url_upload_allow_private' => 'allow_private'] as $old => $new)
            {
                if(array_key_exists($old, $server))
                {
                    if($override || !array_key_exists($new, $remoteRetrieval))
                    {
                        $remoteRetrieval[$new] = $server[$old];
                    }
                    unset($server[$old]);
                    $changed += ['server' => true, 'remote_retrieval' => true];
                }
            }
            if(array_key_exists('url_retrieval', $scanning))
            {
                if($override || !array_key_exists('scans', $remoteRetrieval))
                {
                    $remoteRetrieval['scans'] = $scanning['url_retrieval'];
                }
                unset($scanning['url_retrieval']);
                $changed += ['scanning' => true, 'remote_retrieval' => true];
            }
            if(isset($changed['remote_retrieval']))
            {
                $notes[] = 'server.url_upload* and scanning.url_retrieval became remote_retrieval';
            }

            // scanning.modifier_* -> scanning.modifiers, only the values that differ from the defaults
            $modifiers = ConfigurationValues::parseMap($scanning['modifiers'] ?? []);
            $hadModifiers = false;
            foreach(ScanningRules::cases() as $rule)
            {
                $key = 'modifier_' . strtolower($rule->name);
                if(!array_key_exists($key, $scanning))
                {
                    continue;
                }

                $hadModifiers = true;
                if(($override || !array_key_exists('modifiers', $scanning)) && is_numeric($scanning[$key]) && (float)$scanning[$key] !== $rule->getModifier())
                {
                    $modifiers[$rule->name] = (float)$scanning[$key];
                }
                unset($scanning[$key]);
            }
            if($hadModifiers)
            {
                $scanning['modifiers'] = $modifiers;
                $notes[] = 'scanning.modifier_* became scanning.modifiers';
                $changed['scanning'] = true;
            }

            // scanning.auto_report (bool), auto_report_threshold and auto_report_caution -> scanning.auto_report (mode)
            if(array_key_exists('auto_report_caution', $scanning) || array_key_exists('auto_report_threshold', $scanning) || is_bool($scanning['auto_report'] ?? null) || in_array($scanning['auto_report'] ?? null, ['true', 'false', '1', '0'], true))
            {
                $enabled = ConfigurationValues::parseBool($scanning['auto_report'] ?? null, true);
                $caution = ConfigurationValues::parseBool($scanning['auto_report_caution'] ?? null, false);
                $scanning['auto_report'] = $caution ? 'caution' : ($enabled ? 'block' : 'off');
                if($enabled && isset($scanning['auto_report_threshold']) && (float)$scanning['auto_report_threshold'] !== (float)($scanning['action_block_threshold'] ?? 80.0))
                {
                    $notes[] = 'scanning.auto_report_threshold was removed, automatic reports use the block or caution threshold';
                }
                unset($scanning['auto_report_threshold'], $scanning['auto_report_caution']);
                $notes[] = 'scanning.auto_report is now block, caution or off';
                $changed['scanning'] = true;
            }

            // scanning.report_reputation_* -> scanning.report_reputation
            $reportReputation = [];
            foreach(['normal', 'suspicious', 'malicious'] as $flag)
            {
                if(array_key_exists('report_reputation_' . $flag, $scanning))
                {
                    $reportReputation[strtoupper($flag)] = (int)$scanning['report_reputation_' . $flag];
                    unset($scanning['report_reputation_' . $flag]);
                }
            }
            if(count($reportReputation) > 0)
            {
                if($override || !array_key_exists('report_reputation', $scanning))
                {
                    $scanning['report_reputation'] = array_merge(ConfigurationValues::parseMap($scanning['report_reputation'] ?? []), $reportReputation);
                }
                $notes[] = 'scanning.report_reputation_* became scanning.report_reputation';
                $changed['scanning'] = true;
            }

            // scanning.report_named_entity_reputation (bool) and its _* values -> scanning.report_named_entity_reputation
            $namedEntityReputation = [];
            foreach(['normal', 'suspicious', 'malicious'] as $flag)
            {
                if(array_key_exists('report_named_entity_reputation_' . $flag, $scanning))
                {
                    $namedEntityReputation[strtoupper($flag)] = (int)$scanning['report_named_entity_reputation_' . $flag];
                    unset($scanning['report_named_entity_reputation_' . $flag]);
                }
            }
            $namedEntityFlag = $scanning['report_named_entity_reputation'] ?? null;
            if(count($namedEntityReputation) > 0 || is_bool($namedEntityFlag) || in_array($namedEntityFlag, ['true', 'false', '1', '0'], true))
            {
                $enabled = !is_array($namedEntityFlag) ? ConfigurationValues::parseBool($namedEntityFlag, true) : true;
                $scanning['report_named_entity_reputation'] = $enabled
                    ? array_merge(is_array($namedEntityFlag) ? ConfigurationValues::parseMap($namedEntityFlag) : [], $namedEntityReputation)
                    : false;
                $notes[] = 'scanning.report_named_entity_reputation_* became scanning.report_named_entity_reputation';
                $changed['scanning'] = true;
            }

            // scanning.dns_lookups -> scanning.dns_lookup_limit (0 disables)
            if(array_key_exists('dns_lookups', $scanning))
            {
                if(!ConfigurationValues::parseBool($scanning['dns_lookups'], true))
                {
                    $scanning['dns_lookup_limit'] = 0;
                }
                unset($scanning['dns_lookups']);
                $notes[] = 'scanning.dns_lookups became scanning.dns_lookup_limit (0 disables DNS lookups)';
                $changed['scanning'] = true;
            }

            // search.enabled and search.enable_* -> search.types
            $searchFlags = array_intersect_key($search, self::LEGACY_SEARCH_FLAGS);
            if(count($searchFlags) > 0 || array_key_exists('enabled', $search))
            {
                if($override || !array_key_exists('types', $search))
                {
                    $types = ConfigurationValues::parseList($search['types'] ?? null, array_values(self::LEGACY_SEARCH_FLAGS), SearchConfiguration::DEFAULT_TYPES);
                    foreach($searchFlags as $key => $value)
                    {
                        $types = self::toggleListItem($types, self::LEGACY_SEARCH_FLAGS[$key], ConfigurationValues::parseBool($value, true));
                    }
                    $search['types'] = ConfigurationValues::parseBool($search['enabled'] ?? null, true) ? $types : [];
                }

                $search = array_diff_key($search, self::LEGACY_SEARCH_FLAGS);
                unset($search['enabled']);
                $notes[] = 'search.enabled and search.enable_* became search.types';
                $changed['search'] = true;
            }

            // maintenance.clean_* and clean_*_ttl -> maintenance.retention
            $retention = ConfigurationValues::parseMap($maintenance['retention'] ?? []);
            $hadRetention = false;
            foreach(self::LEGACY_RETENTION_TYPES as $name => $type)
            {
                $enabledKey = 'clean_' . $name;
                $ttlKey = 'clean_' . $name . '_ttl';
                if(!array_key_exists($enabledKey, $maintenance) && !array_key_exists($ttlKey, $maintenance))
                {
                    continue;
                }

                $hadRetention = true;
                if($override || !array_key_exists('retention', $maintenance) || !array_key_exists($type, $retention))
                {
                    $enabled = ConfigurationValues::parseBool($maintenance[$enabledKey] ?? null, $type !== 'ENTITIES');
                    // Without a TTL the configured retention is kept, or the default one
                    $ttl = $maintenance[$ttlKey]
                        ?? ConfigurationValues::parseDuration($retention[$type] ?? null, null)
                        ?? ConfigurationValues::parseDuration(MaintenanceConfiguration::DEFAULT_RETENTION[$type], null)
                        ?? ConfigurationValues::parseDuration('2y', 0);
                    $retention[$type] = $enabled ? (int)$ttl : 'off';
                }
                unset($maintenance[$enabledKey], $maintenance[$ttlKey]);
            }
            if($hadRetention)
            {
                $maintenance['retention'] = $retention;
                $notes[] = 'maintenance.clean_* and clean_*_ttl became maintenance.retention';
                $changed['maintenance'] = true;
            }

            // redis.<type>_cache_enabled, _limit and _ttl -> redis.record_cache
            $recordCache = ConfigurationValues::parseMap($redis['record_cache'] ?? []);
            $hadCache = false;
            foreach(self::LEGACY_CACHE_TYPES as $name => $type)
            {
                $keys = [$name . '_cache_enabled', $name . '_cache_limit', $name . '_cache_ttl'];
                if(count(array_intersect_key($redis, array_flip($keys))) === 0)
                {
                    continue;
                }

                $hadCache = true;
                if($override || !array_key_exists('record_cache', $redis) || !array_key_exists($type, $recordCache))
                {
                    $default = RedisConfiguration::DEFAULT_RECORD_CACHE[$type];
                    $existing = is_array($recordCache[$type] ?? null) ? $recordCache[$type] : [];
                    $enabled = ConfigurationValues::parseBool($redis[$keys[0]] ?? null, true);
                    $recordCache[$type] = [
                        'limit' => $enabled ? (int)($redis[$keys[1]] ?? $existing['limit'] ?? $default['limit']) : 0,
                        'ttl' => (int)($redis[$keys[2]] ?? $existing['ttl'] ?? $default['ttl']),
                    ];
                }
                $redis = array_diff_key($redis, array_flip($keys));
            }
            if($hadCache)
            {
                $redis['record_cache'] = $recordCache;
                $notes[] = 'redis.<type>_cache_* became redis.record_cache';
                $changed['redis'] = true;
            }

            // scanning.reputation_window_duration was removed when reputation became settled once per day; a gain still at
            // the default of the hourly windows (1) becomes the default of a daily settlement (10)
            if(array_key_exists('reputation_window_duration', $scanning))
            {
                if((int)($scanning['reputation_gain'] ?? 1) === 1)
                {
                    $scanning['reputation_gain'] = 10;
                }
                unset($scanning['reputation_window_duration']);
                $notes[] = 'scanning.reputation_window_duration was removed, reputation is settled once per day (scanning.reputation_gain is per day)';
                $changed['scanning'] = true;
            }

            // Keys removed without a replacement
            foreach(self::LEGACY_REMOVED as $section => $keys)
            {
                $values = $section === 'scanning' ? $scanning : $database;
                $removed = array_intersect_key($values, array_flip($keys));
                if(count($removed) === 0)
                {
                    continue;
                }

                $values = array_diff_key($values, $removed);
                if($section === 'scanning')
                {
                    $scanning = $values;
                }
                else
                {
                    $database = $values;
                }
                $notes[] = sprintf('%s.%s were removed, their values are fixed', $section, implode(', ' . $section . '.', array_keys($removed)));
                $changed[$section] = true;
            }

            foreach(array_keys($changed) as $section)
            {
                $configuration->set($section, match($section)
                {
                    'server' => $server,
                    'scanning' => $scanning,
                    'search' => $search,
                    'maintenance' => $maintenance,
                    'redis' => $redis,
                    'database' => $database,
                    'remote_retrieval' => $remoteRetrieval,
                }, true);
            }

            return $notes;
        }

        /**
         * Adds an item to a list or removes it
         *
         * @param string[] $list The list
         * @param string $item The item
         * @param bool $present True to add the item, false to remove it
         * @return string[] The list
         */
        private static function toggleListItem(array $list, string $item, bool $present): array
        {
            $list = array_values(array_diff($list, [$item]));
            if($present)
            {
                $list[] = $item;
            }

            return $list;
        }

        /**
         * Returns the deprecated environment variables and the old key each one set
         *
         * @return array<string, array{0: string, 1: string}> The section and old key, keyed by environment variable
         */
        private static function getLegacyEnvironment(): array
        {
            $variables = [
                'FEDERATION_URL_UPLOADS' => ['server', 'url_uploads'],
                'FEDERATION_URL_UPLOAD_TIMEOUT' => ['server', 'url_upload_timeout'],
                'FEDERATION_URL_UPLOAD_ALLOW_PRIVATE' => ['server', 'url_upload_allow_private'],
                'FEDERATION_SCANNING_URL_RETRIEVAL' => ['scanning', 'url_retrieval'],
                'FEDERATION_SEARCH_PUBLIC' => ['search', 'public_search'],
                'FEDERATION_SEARCH_ENABLED' => ['search', 'enabled'],
                'FEDERATION_SCANNING_AUTO_REPORT_THRESHOLD' => ['scanning', 'auto_report_threshold'],
                'FEDERATION_SCANNING_AUTO_REPORT_CAUTION' => ['scanning', 'auto_report_caution'],
                'FEDERATION_SCANNING_DNS_LOOKUPS' => ['scanning', 'dns_lookups'],
                'FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION' => ['scanning', 'report_named_entity_reputation'],
                'FEDERATION_MAINTENANCE_CLEAN_ENTITIES' => ['maintenance', 'clean_entities'],
            ];

            foreach(array_keys(self::LEGACY_PUBLIC_FLAGS) as $key)
            {
                $variables['FEDERATION_' . strtoupper($key)] = ['server', $key];
            }

            foreach(self::LEGACY_LIST_MAXIMA as $key)
            {
                $variables['FEDERATION_' . strtoupper($key)] = ['server', $key];
            }

            foreach(array_keys(self::LEGACY_SEARCH_FLAGS) as $key)
            {
                $variables['FEDERATION_SEARCH_' . strtoupper($key)] = ['search', $key];
            }

            foreach(ScanningRules::cases() as $rule)
            {
                $variables['FEDERATION_SCANNING_MODIFIER_' . $rule->name] = ['scanning', 'modifier_' . strtolower($rule->name)];
            }

            foreach(['normal', 'suspicious', 'malicious'] as $flag)
            {
                $variables['FEDERATION_SCANNING_REPORT_REPUTATION_' . strtoupper($flag)] = ['scanning', 'report_reputation_' . $flag];
                $variables['FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION_' . strtoupper($flag)] = ['scanning', 'report_named_entity_reputation_' . $flag];
            }

            foreach(array_keys(self::LEGACY_RETENTION_TYPES) as $name)
            {
                $variables['FEDERATION_MAINTENANCE_CLEAN_' . strtoupper($name)] = ['maintenance', 'clean_' . $name];
                $variables['FEDERATION_MAINTENANCE_CLEAN_' . strtoupper($name) . '_TTL'] = ['maintenance', 'clean_' . $name . '_ttl'];
            }

            foreach(array_keys(self::LEGACY_CACHE_TYPES) as $name)
            {
                foreach(['enabled', 'limit', 'ttl'] as $setting)
                {
                    $variables[sprintf('FEDERATION_%s_CACHE_%s', strtoupper($name), strtoupper($setting))] = ['redis', sprintf('%s_cache_%s', $name, $setting)];
                }
            }

            foreach(['FEDERATION_SCANNING_REPUTATION_WINDOW_DURATION', 'FEDERATION_SCANNING_REPUTATION_MIN_BOUND', 'FEDERATION_SCANNING_REPUTATION_MAX_BOUND', 'FEDERATION_SCANNING_RISK_SCORE_NEUTRAL_POINT',
                     'FEDERATION_SCANNING_RISK_SCORE_MIN_BOUND', 'FEDERATION_SCANNING_RISK_SCORE_MAX_BOUND', 'FEDERATION_SCANNING_COLLECTS_PUBLIC_SCAN_CONTENT'] as $variable)
            {
                $variables[$variable] = ['scanning', strtolower(substr($variable, strlen('FEDERATION_SCANNING_')))];
            }

            $variables['FEDERATION_DATABASE_CHARSET'] = ['database', 'charset'];
            $variables['FEDERATION_DATABASE_COLLATION'] = ['database', 'collation'];

            // FEDERATION_SCANNING_AUTO_REPORT keeps its name, but its true/false values are deprecated
            if(in_array(strtolower((string)getenv('FEDERATION_SCANNING_AUTO_REPORT')), ['true', 'false', '1', '0'], true))
            {
                $variables['FEDERATION_SCANNING_AUTO_REPORT'] = ['scanning', 'auto_report'];
            }

            return $variables;
        }

        /**
         * Describes what replaced a deprecated environment variable
         *
         * @param string $variable The deprecated environment variable
         * @return string The description
         */
        private static function describeLegacyReplacement(string $variable): string
        {
            return match(true)
            {
                str_starts_with($variable, 'FEDERATION_URL_UPLOAD'), $variable === 'FEDERATION_SCANNING_URL_RETRIEVAL'
                    => 'use FEDERATION_REMOTE_RETRIEVAL_UPLOADS, _SCANS, _SCAN_FILES, _TIMEOUT or _ALLOW_PRIVATE',
                str_starts_with($variable, 'FEDERATION_PUBLIC_'), $variable === 'FEDERATION_SEARCH_PUBLIC'
                    => 'use FEDERATION_PUBLIC (eg; ENTITIES,EVIDENCE,BLACKLIST)',
                str_starts_with($variable, 'FEDERATION_LIST_') => 'use FEDERATION_MAX_PAGE_SIZE',
                str_starts_with($variable, 'FEDERATION_SEARCH_') => 'use FEDERATION_SEARCH_TYPES (eg; ENTITY,EVIDENCE)',
                str_starts_with($variable, 'FEDERATION_SCANNING_MODIFIER_') => 'use FEDERATION_SCANNING_MODIFIERS (eg; AUTHOR_BLACKLISTED=-25)',
                str_starts_with($variable, 'FEDERATION_SCANNING_AUTO_REPORT') => 'set FEDERATION_SCANNING_AUTO_REPORT to block, caution or off',
                str_starts_with($variable, 'FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION') => 'use FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION (eg; NORMAL=1,SUSPICIOUS=-5 or false)',
                str_starts_with($variable, 'FEDERATION_SCANNING_REPORT_REPUTATION') => 'use FEDERATION_SCANNING_REPORT_REPUTATION (eg; NORMAL=1,SUSPICIOUS=-10,MALICIOUS=-20)',
                $variable === 'FEDERATION_SCANNING_DNS_LOOKUPS' => 'set FEDERATION_SCANNING_DNS_LOOKUP_LIMIT to 0 to disable DNS lookups',
                str_starts_with($variable, 'FEDERATION_MAINTENANCE_CLEAN_') => 'use FEDERATION_MAINTENANCE_RETENTION (eg; EVIDENCE=2y,ENTITIES=off)',
                str_contains($variable, '_CACHE_') => 'use FEDERATION_REDIS_RECORD_CACHE (eg; ENTITY=5000/600)',
                $variable === 'FEDERATION_SCANNING_COLLECTS_PUBLIC_SCAN_CONTENT' => 'plugins that store scanned content declare collects_scan_content instead',
                default => 'it has no effect, the value is fixed',
            };
        }

        /**
         * Initialize the configuration with default values.
         */
        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('federation');

            // Keys of earlier releases stored in the configuration file are converted to the settings that replaced them
            $migrated = self::migrateStoredKeys(self::$configuration);

            // Server configuration
            self::$configuration->setDefault('server.base_url', 'http://127.0.0.1:7000', 'FEDERATION_BASE_URL');
            self::$configuration->setDefault('server.name', 'Federation Server', 'FEDERATION_NAME');
            self::$configuration->setDefault('server.access_token', Utilities::generateString(), 'FEDERATION_ACCESS_TOKEN');
            self::$configuration->setDefault('server.max_upload_size', 52428800, 'FEDERATION_MAX_UPLOAD_SIZE'); // 50MB default
            self::$configuration->setDefault('server.storage_path', '/var/www/uploads', 'FEDERATION_STORAGE_PATH');
            self::$configuration->setDefault('server.max_page_size', 100, 'FEDERATION_MAX_PAGE_SIZE');
            self::$configuration->setDefault('server.public', ServerConfiguration::DEFAULT_PUBLIC, 'FEDERATION_PUBLIC');
            self::$configuration->setDefault('server.public_audit_entries', array_map(fn($type) => $type->value, AuditLogType::getDefaultPublic()), 'FEDERATION_PUBLIC_AUDIT_ENTRIES');
            self::$configuration->setDefault('server.top_threats_limit', 25, 'FEDERATION_TOP_THREATS_LIMIT');
            self::$configuration->setDefault('server.min_blacklist_time', 1800, 'FEDERATION_MIN_BLACKLIST_TIME');
            self::$configuration->setDefault('server.allow_illegal_content', true, 'FEDERATION_ALLOW_ILLEGAL_CONTENT');
            self::$configuration->setDefault('server.link_subdomain_entities', true, 'FEDERATION_LINK_SUBDOMAIN_ENTITIES');

            // Retrieval of URLs chosen by requesters: URL uploads, and the URLs submitted for scanning (plugins only)
            self::$configuration->setDefault('remote_retrieval.uploads', true, 'FEDERATION_REMOTE_RETRIEVAL_UPLOADS');
            self::$configuration->setDefault('remote_retrieval.scans', false, 'FEDERATION_REMOTE_RETRIEVAL_SCANS');
            self::$configuration->setDefault('remote_retrieval.scan_files', false, 'FEDERATION_REMOTE_RETRIEVAL_SCAN_FILES');
            self::$configuration->setDefault('remote_retrieval.timeout', 30, 'FEDERATION_REMOTE_RETRIEVAL_TIMEOUT');
            self::$configuration->setDefault('remote_retrieval.allow_private', false, 'FEDERATION_REMOTE_RETRIEVAL_ALLOW_PRIVATE');

            // Scanning configuration, scanning rules keep their default points unless overridden in scanning.modifiers
            self::$configuration->setDefault('scanning.modifiers', [], 'FEDERATION_SCANNING_MODIFIERS');
            self::$configuration->setDefault('scanning.auto_report', 'block', 'FEDERATION_SCANNING_AUTO_REPORT');
            self::$configuration->setDefault('scanning.action_block_threshold', 80.00, 'FEDERATION_SCANNING_ACTION_BLOCK_THRESHOLD');
            self::$configuration->setDefault('scanning.action_caution_threshold', 60.00, 'FEDERATION_SCANNING_ACTION_CAUTION_THRESHOLD');
            self::$configuration->setDefault('scanning.risk_score_scaling_factor', 2.3, 'FEDERATION_SCANNING_RISK_SCORE_SCALING_FACTOR');
            self::$configuration->setDefault('scanning.reputation_gain', 10, 'FEDERATION_SCANNING_REPUTATION_GAIN');
            self::$configuration->setDefault('scanning.reputation_loss', -5, 'FEDERATION_SCANNING_REPUTATION_LOSS');
            self::$configuration->setDefault('scanning.mention_min_authors', 3, 'FEDERATION_SCANNING_MENTION_MIN_AUTHORS');
            self::$configuration->setDefault('scanning.report_reputation', ['NORMAL' => 1, 'SUSPICIOUS' => -10, 'MALICIOUS' => -20], 'FEDERATION_SCANNING_REPORT_REPUTATION');
            self::$configuration->setDefault('scanning.report_named_entity_reputation', ['NORMAL' => 1, 'SUSPICIOUS' => -5, 'MALICIOUS' => -10], 'FEDERATION_SCANNING_REPORT_NAMED_ENTITY_REPUTATION');
            self::$configuration->setDefault('scanning.blacklist_reputation', -50, 'FEDERATION_SCANNING_BLACKLIST_REPUTATION');
            self::$configuration->setDefault('scanning.blacklist_related_reputation', -10, 'FEDERATION_SCANNING_BLACKLIST_RELATED_REPUTATION');
            self::$configuration->setDefault('scanning.concerns', array_map(fn($type) => $type->value, ScanningConfiguration::getAllConcerns()), 'FEDERATION_SCANNING_CONCERNS');
            self::$configuration->setDefault('scanning.default_concerns', array_map(fn($type) => $type->value, ScanningConfiguration::getStandardDefaultConcerns()), 'FEDERATION_SCANNING_DEFAULT_CONCERNS');
            self::$configuration->setDefault('scanning.dns_lookup_limit', 10, 'FEDERATION_SCANNING_DNS_LOOKUP_LIMIT');
            self::$configuration->setDefault('scanning.max_files', 4, 'FEDERATION_SCANNING_MAX_FILES');
            self::$configuration->setDefault('scanning.max_file_size', 10485760, 'FEDERATION_SCANNING_MAX_FILE_SIZE'); // 10MB default

            // Maintenance configuration, how long records of each type are kept (eg; 2y, 30d, or off to keep them)
            self::$configuration->setDefault('maintenance.enabled', true, 'FEDERATION_MAINTENANCE_ENABLED');
            self::$configuration->setDefault('maintenance.retention', MaintenanceConfiguration::DEFAULT_RETENTION, 'FEDERATION_MAINTENANCE_RETENTION');
            // How often the DNS details of domain entities are refreshed by maintenance (eg; 90d, or off)
            self::$configuration->setDefault('maintenance.dns_refresh', MaintenanceConfiguration::DEFAULT_DNS_REFRESH, 'FEDERATION_MAINTENANCE_DNS_REFRESH');

            // Search configuration, the searchable record types (none disables search)
            self::$configuration->setDefault('search.types', SearchConfiguration::DEFAULT_TYPES, 'FEDERATION_SEARCH_TYPES');
            self::$configuration->setDefault('search.max_limit', 50, 'FEDERATION_SEARCH_MAX_LIMIT');
            self::$configuration->setDefault('search.extended_search', true, 'FEDERATION_SEARCH_EXTENDED');

            // The ncc package names of the plugins to load, plugins are responsible for their own configuration
            self::$configuration->setDefault('plugins', [], 'FEDERATION_PLUGINS');

            // Bayesian model check of `federationlib init`, the defaults match the layout of the docker image
            self::$configuration->setDefault('bayesian_model.enabled', true, 'FEDERATION_BAYESIAN_MODEL_ENABLED');
            self::$configuration->setDefault('bayesian_model.start_script', '/usr/local/bin/temporary_start_bayesian.sh', 'FEDERATION_BAYESIAN_MODEL_START_SCRIPT');
            self::$configuration->setDefault('bayesian_model.stop_script', '/usr/local/bin/stop_temporary_bayesian.sh', 'FEDERATION_BAYESIAN_MODEL_STOP_SCRIPT');
            self::$configuration->setDefault('bayesian_model.model_path', '/var/www/bayesian_model/model', 'FEDERATION_BAYESIAN_MODEL_PATH');
            self::$configuration->setDefault('bayesian_model.archive_path', '/var/www/bayesian_model/archive.csv', 'FEDERATION_BAYESIAN_MODEL_ARCHIVE_PATH');
            self::$configuration->setDefault('bayesian_model.backup_path', '/var/www/bayesian_model/backups', 'FEDERATION_BAYESIAN_MODEL_BACKUP_PATH');
            self::$configuration->setDefault('bayesian_model.minimum_evidence', 20, 'FEDERATION_BAYESIAN_MODEL_MINIMUM_EVIDENCE');
            self::$configuration->setDefault('bayesian_model.learning_timeout', 600, 'FEDERATION_BAYESIAN_MODEL_LEARNING_TIMEOUT');

            // Database configuration, the character set is always utf8mb4
            self::$configuration->setDefault('database.host', '127.0.0.1', 'FEDERATION_DATABASE_HOST');
            self::$configuration->setDefault('database.port', 3306, 'FEDERATION_DATABASE_PORT');
            self::$configuration->setDefault('database.username', 'root', 'FEDERATION_DATABASE_USERNAME');
            self::$configuration->setDefault('database.password', 'root', 'FEDERATION_DATABASE_PASSWORD');
            self::$configuration->setDefault('database.name', 'federation', 'FEDERATION_DATABASE_NAME');

            // Redis caching configuration
            self::$configuration->setDefault('redis.enabled', false, 'FEDERATION_REDIS_ENABLED');
            self::$configuration->setDefault('redis.host', '127.0.0.1', 'FEDERATION_REDIS_HOST');
            self::$configuration->setDefault('redis.port', 6379, 'FEDERATION_REDIS_PORT');
            self::$configuration->setDefault('redis.password', null, 'FEDERATION_REDIS_PASSWORD');
            self::$configuration->setDefault('redis.database', 0, 'FEDERATION_REDIS_DATABASE');
            self::$configuration->setDefault('redis.throw_on_errors', true, 'FEDERATION_CACHE_THROW_ON_ERRORS');
            // If enabled, some methods will attempt to pre-cache objects before they are called.
            self::$configuration->setDefault('redis.pre_cache_enabled', true, 'FEDERATION_PRE_CACHE_ENABLED');
            // The maximum number of cached records of each record type and their TTL, a limit of 0 disables the cache
            self::$configuration->setDefault('redis.record_cache', RedisConfiguration::DEFAULT_RECORD_CACHE, 'FEDERATION_REDIS_RECORD_CACHE');
            // Search and listing result set TTL (how long cached query results live)
            self::$configuration->setDefault('redis.search_cache_ttl', 60, 'FEDERATION_SEARCH_CACHE_TTL');

            // Environment variables of earlier releases override the settings that replaced them, with a warning
            $storedMigrated = count($migrated) > 0;
            array_push($migrated, ...self::migrateEnvironment(self::$configuration));
            foreach(array_unique($migrated) as $note)
            {
                Logger::log()->warning(sprintf('Deprecated configuration: %s', $note));
            }

            // Only save if the configuration file does not exist or we're in CLI mode, or when stored keys were migrated
            // so that they are converted once rather than on every request
            if(!file_exists(self::$configuration->getPath()) || php_sapi_name() === 'cli')
            {
                self::$configuration->save();
            }
            elseif($storedMigrated)
            {
                try
                {
                    self::$configuration->save();
                }
                catch(\RuntimeException $e)
                {
                    // The migrated configuration still applies to this process, it is converted again on the next start
                    Logger::log()->warning(sprintf('Unable to save the migrated configuration: %s', $e->getMessage()));
                }
            }

            // Initialize the configuration classes
            self::$serverConfiguration = new ServerConfiguration(self::$configuration->get('server'));
            self::$remoteRetrievalConfiguration = new RemoteRetrievalConfiguration(self::$configuration->get('remote_retrieval') ?? []);
            self::$scanningConfiguration = new ScanningConfiguration(self::$configuration->get('scanning'));
            self::$databaseConfiguration = new DatabaseConfiguration(self::$configuration->get('database'));
            self::$redisConfiguration = new RedisConfiguration(self::$configuration->get('redis'));
            self::$maintenanceConfiguration = new MaintenanceConfiguration(self::$configuration->get('maintenance'));
            self::$searchConfiguration = new SearchConfiguration(self::$configuration->get('search'), self::$serverConfiguration->isPublic('SEARCH'));
            self::$pluginsConfiguration = new PluginsConfiguration(self::$configuration->get('plugins'));
            self::$bayesianModelConfiguration = new BayesianModelConfiguration(self::$configuration->get('bayesian_model'));
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
         * Get the remote retrieval configuration.
         *
         * @return RemoteRetrievalConfiguration
         */
        public static function getRemoteRetrievalConfiguration(): RemoteRetrievalConfiguration
        {
            if(self::$remoteRetrievalConfiguration === null)
            {
                self::initialize();
            }

            return self::$remoteRetrievalConfiguration;
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

        /**
         * Get the search configuration.
         *
         * @return SearchConfiguration
         */
        public static function getSearchConfiguration(): SearchConfiguration
        {
            if(self::$searchConfiguration === null)
            {
                self::initialize();
            }

            return self::$searchConfiguration;
        }

        /**
         * Get the plugins configuration.
         *
         * @return PluginsConfiguration
         */
        public static function getPluginsConfiguration(): PluginsConfiguration
        {
            if(self::$pluginsConfiguration === null)
            {
                self::initialize();
            }

            return self::$pluginsConfiguration;
        }

        /**
         * Get the configuration of the Bayesian model check.
         *
         * @return BayesianModelConfiguration
         */
        public static function getBayesianModelConfiguration(): BayesianModelConfiguration
        {
            if(self::$bayesianModelConfiguration === null)
            {
                self::initialize();
            }

            return self::$bayesianModelConfiguration;
        }

    }

