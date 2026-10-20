<?php

    namespace FederationLib\Tests\Configuration;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Configuration\RedisConfiguration;
    use FederationLib\Classes\Configuration\SearchConfiguration;
    use FederationLib\Classes\Configuration\ServerConfiguration;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Helpers\InMemoryConfiguration;
    use PHPUnit\Framework\TestCase;

    /**
     * The configuration under test is always an InMemoryConfiguration, never a ConfigLib configuration backed by a
     * file: ConfigLib resolves a missing path to the configuration file of the user and saves on destruction.
     */
    class LegacyConfigurationTest extends TestCase
    {
        /**
         * @var string[] Environment variables set by a test, unset afterwards
         */
        private array $environment = [];

        protected function tearDown(): void
        {
            foreach($this->environment as $variable)
            {
                putenv($variable);
            }

            $this->environment = [];
        }

        private function setEnvironment(string $variable, string $value): void
        {
            putenv($variable . '=' . $value);
            $this->environment[] = $variable;
        }

        public function testNothingToConvert(): void
        {
            $data = [
                'server' => ['name' => 'Test', 'public' => ['ENTITIES']],
                'scanning' => ['auto_report' => 'block', 'dns_lookup_limit' => 5],
            ];
            $configuration = new InMemoryConfiguration($data);

            $this->assertSame([], Configuration::migrateStoredKeys($configuration));
            $this->assertSame($data, $configuration->getConfiguration());
        }

        public function testPublicFlagsBecomePublicList(): void
        {
            $configuration = new InMemoryConfiguration([
                'server' => ['name' => 'Test', 'public_evidence' => false, 'public_telephone_entities' => true, 'public_entity_metadata' => 'true'],
                'search' => ['public_search' => true],
            ]);

            $notes = Configuration::migrateStoredKeys($configuration);
            $this->assertNotEmpty($notes);

            $server = $configuration->get('server');
            $this->assertSame('Test', $server['name']);
            $this->assertArrayNotHasKey('public_evidence', $server);
            $this->assertArrayNotHasKey('public_telephone_entities', $server);
            $this->assertArrayNotHasKey('public_entity_metadata', $server);
            $this->assertArrayNotHasKey('public_search', $configuration->get('search'));

            $public = $server['public'];
            $this->assertNotContains('EVIDENCE', $public);
            $this->assertContains('TELEPHONE_ENTITIES', $public);
            $this->assertContains('ENTITY_METADATA', $public);
            $this->assertContains('SEARCH', $public);
            // The defaults that were not overridden by an old flag are kept
            foreach(array_diff(ServerConfiguration::DEFAULT_PUBLIC, ['EVIDENCE']) as $item)
            {
                $this->assertContains($item, $public);
            }
        }

        public function testStoredPublicListIsKeptOverOldFlags(): void
        {
            $configuration = new InMemoryConfiguration([
                'server' => ['public' => ['ENTITIES'], 'public_evidence' => true],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $this->assertSame(['ENTITIES'], $configuration->get('server.public'));
            $this->assertArrayNotHasKey('public_evidence', $configuration->get('server'));
        }

        public function testListMaximaBecomeLargestPageSize(): void
        {
            $configuration = new InMemoryConfiguration([
                'server' => ['list_entities_max_items' => 50, 'list_evidence_max_items' => '250', 'list_reports_max_items' => 100],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $server = $configuration->get('server');
            $this->assertSame(250, $server['max_page_size']);
            $this->assertArrayNotHasKey('list_entities_max_items', $server);
            $this->assertArrayNotHasKey('list_evidence_max_items', $server);
            $this->assertArrayNotHasKey('list_reports_max_items', $server);
        }

        public function testUrlRetrievalSettingsBecomeRemoteRetrieval(): void
        {
            $configuration = new InMemoryConfiguration([
                'server' => ['url_uploads' => false, 'url_upload_timeout' => 12, 'url_upload_allow_private' => true],
                'scanning' => ['url_retrieval' => true],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $this->assertSame(['uploads' => false, 'timeout' => 12, 'allow_private' => true, 'scans' => true], $configuration->get('remote_retrieval'));
            $this->assertArrayNotHasKey('url_uploads', $configuration->get('server'));
            $this->assertArrayNotHasKey('url_retrieval', $configuration->get('scanning'));
        }

        public function testModifiersKeepOnlyValuesDifferentFromDefaults(): void
        {
            $configuration = new InMemoryConfiguration([
                'scanning' => [
                    'modifier_author_blacklisted' => -30,
                    'modifier_author_whitelisted' => ScanningRules::AUTHOR_WHITELISTED->getModifier(),
                ],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $scanning = $configuration->get('scanning');
            $this->assertSame(['AUTHOR_BLACKLISTED' => -30.0], $scanning['modifiers']);
            $this->assertArrayNotHasKey('modifier_author_blacklisted', $scanning);
            $this->assertArrayNotHasKey('modifier_author_whitelisted', $scanning);
        }

        public function testAutoReportBecomesMode(): void
        {
            $cases = [
                [['auto_report' => true], 'block'],
                [['auto_report' => 'true'], 'block'],
                [['auto_report' => false], 'off'],
                [['auto_report' => '0'], 'off'],
                [['auto_report' => true, 'auto_report_caution' => true], 'caution'],
                [['auto_report_threshold' => 80], 'block'],
            ];

            foreach($cases as [$scanning, $expected])
            {
                $configuration = new InMemoryConfiguration(['scanning' => $scanning]);
                Configuration::migrateStoredKeys($configuration);

                $result = $configuration->get('scanning');
                $this->assertSame($expected, $result['auto_report'], json_encode($scanning));
                $this->assertArrayNotHasKey('auto_report_caution', $result);
                $this->assertArrayNotHasKey('auto_report_threshold', $result);
            }
        }

        public function testAutoReportModeIsNotConverted(): void
        {
            $configuration = new InMemoryConfiguration(['scanning' => ['auto_report' => 'caution']]);
            $this->assertSame([], Configuration::migrateStoredKeys($configuration));
            $this->assertSame('caution', $configuration->get('scanning.auto_report'));
        }

        public function testReportReputationValuesBecomeMap(): void
        {
            $configuration = new InMemoryConfiguration([
                'scanning' => ['report_reputation_normal' => 2, 'report_reputation_malicious' => '-30'],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $scanning = $configuration->get('scanning');
            $this->assertSame(['NORMAL' => 2, 'MALICIOUS' => -30], $scanning['report_reputation']);
            $this->assertArrayNotHasKey('report_reputation_normal', $scanning);
            $this->assertArrayNotHasKey('report_reputation_malicious', $scanning);
        }

        public function testDisabledNamedEntityReputationBecomesFalse(): void
        {
            $configuration = new InMemoryConfiguration([
                'scanning' => ['report_named_entity_reputation' => false, 'report_named_entity_reputation_normal' => 3],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $scanning = $configuration->get('scanning');
            $this->assertFalse($scanning['report_named_entity_reputation']);
            $this->assertArrayNotHasKey('report_named_entity_reputation_normal', $scanning);
        }

        public function testNamedEntityReputationValuesBecomeMap(): void
        {
            $configuration = new InMemoryConfiguration([
                'scanning' => ['report_named_entity_reputation' => true, 'report_named_entity_reputation_suspicious' => -7],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $this->assertSame(['SUSPICIOUS' => -7], $configuration->get('scanning.report_named_entity_reputation'));
        }

        public function testDisabledDnsLookupsBecomeZeroLimit(): void
        {
            $configuration = new InMemoryConfiguration(['scanning' => ['dns_lookups' => false]]);
            Configuration::migrateStoredKeys($configuration);

            $scanning = $configuration->get('scanning');
            $this->assertSame(0, $scanning['dns_lookup_limit']);
            $this->assertArrayNotHasKey('dns_lookups', $scanning);
        }

        public function testEnabledDnsLookupsKeepTheLimit(): void
        {
            $configuration = new InMemoryConfiguration(['scanning' => ['dns_lookups' => true, 'dns_lookup_limit' => 4]]);
            Configuration::migrateStoredKeys($configuration);

            $scanning = $configuration->get('scanning');
            $this->assertSame(4, $scanning['dns_lookup_limit']);
            $this->assertArrayNotHasKey('dns_lookups', $scanning);
        }

        public function testSearchFlagsBecomeTypes(): void
        {
            $configuration = new InMemoryConfiguration([
                'search' => ['enable_attachments' => true, 'enable_operators' => false, 'max_limit' => 20],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $search = $configuration->get('search');
            $this->assertSame(20, $search['max_limit']);
            $this->assertContains('ATTACHMENT', $search['types']);
            $this->assertNotContains('OPERATOR', $search['types']);
            foreach(array_diff(SearchConfiguration::DEFAULT_TYPES, ['OPERATOR']) as $type)
            {
                $this->assertContains($type, $search['types']);
            }
            $this->assertArrayNotHasKey('enable_attachments', $search);
            $this->assertArrayNotHasKey('enable_operators', $search);
        }

        public function testDisabledSearchBecomesNoTypes(): void
        {
            $configuration = new InMemoryConfiguration(['search' => ['enabled' => false, 'enable_entities' => true]]);
            Configuration::migrateStoredKeys($configuration);

            $search = $configuration->get('search');
            $this->assertSame([], $search['types']);
            $this->assertArrayNotHasKey('enabled', $search);
        }

        public function testMaintenanceCleanSettingsBecomeRetention(): void
        {
            $configuration = new InMemoryConfiguration([
                'maintenance' => ['enabled' => true, 'clean_evidence' => false, 'clean_audit_logs_ttl' => 100, 'clean_blacklist' => true],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $maintenance = $configuration->get('maintenance');
            $this->assertTrue($maintenance['enabled']);
            $this->assertSame('off', $maintenance['retention']['EVIDENCE']);
            $this->assertSame(100, $maintenance['retention']['AUDIT_LOGS']);
            // Enabled without a TTL keeps the default retention
            $this->assertSame(31536000, $maintenance['retention']['BLACKLIST']);
            foreach(['clean_evidence', 'clean_audit_logs_ttl', 'clean_blacklist'] as $key)
            {
                $this->assertArrayNotHasKey($key, $maintenance);
            }
        }

        public function testRedisCacheSettingsBecomeRecordCache(): void
        {
            $configuration = new InMemoryConfiguration([
                'redis' => ['enabled' => true, 'entity_cache_enabled' => false, 'operator_cache_limit' => 5, 'operator_cache_ttl' => 30],
            ]);

            Configuration::migrateStoredKeys($configuration);

            $redis = $configuration->get('redis');
            $this->assertTrue($redis['enabled']);
            $this->assertSame(['limit' => 0, 'ttl' => RedisConfiguration::DEFAULT_RECORD_CACHE['ENTITY']['ttl']], $redis['record_cache']['ENTITY']);
            $this->assertSame(['limit' => 5, 'ttl' => 30], $redis['record_cache']['OPERATOR']);
            $this->assertArrayNotHasKey('REPORT', $redis['record_cache']);
            foreach(['entity_cache_enabled', 'operator_cache_limit', 'operator_cache_ttl'] as $key)
            {
                $this->assertArrayNotHasKey($key, $redis);
            }
        }

        public function testReputationWindowRemovedRaisesDefaultGain(): void
        {
            $configuration = new InMemoryConfiguration(['scanning' => ['reputation_window_duration' => 3600, 'reputation_gain' => 1]]);
            Configuration::migrateStoredKeys($configuration);

            $scanning = $configuration->get('scanning');
            $this->assertSame(10, $scanning['reputation_gain']);
            $this->assertArrayNotHasKey('reputation_window_duration', $scanning);
        }

        public function testReputationWindowRemovedKeepsCustomGain(): void
        {
            $configuration = new InMemoryConfiguration(['scanning' => ['reputation_window_duration' => 3600, 'reputation_gain' => 4]]);
            Configuration::migrateStoredKeys($configuration);

            $this->assertSame(4, $configuration->get('scanning.reputation_gain'));
        }

        public function testRemovedKeysAreDropped(): void
        {
            $configuration = new InMemoryConfiguration([
                'scanning' => ['risk_score_neutral_point' => 50, 'reputation_max_bound' => 1000, 'collects_public_scan_content' => true, 'reputation_gain' => 10],
                'database' => ['host' => 'db', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            ]);

            $notes = Configuration::migrateStoredKeys($configuration);
            $this->assertCount(2, $notes);

            $this->assertSame(['reputation_gain' => 10], $configuration->get('scanning'));
            $this->assertSame(['host' => 'db'], $configuration->get('database'));
        }

        public function testMigrationIsIdempotent(): void
        {
            $configuration = new InMemoryConfiguration([
                'server' => ['public_evidence' => false, 'list_entities_max_items' => 50],
                'scanning' => ['dns_lookups' => false, 'auto_report' => false],
            ]);

            Configuration::migrateStoredKeys($configuration);
            $migrated = $configuration->getConfiguration();

            $this->assertSame([], Configuration::migrateStoredKeys($configuration));
            $this->assertSame($migrated, $configuration->getConfiguration());
        }

        public function testNoDeprecatedEnvironment(): void
        {
            $configuration = new InMemoryConfiguration(['scanning' => ['dns_lookup_limit' => 10]]);
            $this->assertSame([], Configuration::migrateEnvironment($configuration));
            $this->assertSame(10, $configuration->get('scanning.dns_lookup_limit'));
        }

        public function testDeprecatedEnvironmentIsConvertedWithWarning(): void
        {
            $this->setEnvironment('FEDERATION_SCANNING_DNS_LOOKUPS', 'false');
            $configuration = new InMemoryConfiguration(['scanning' => ['dns_lookup_limit' => 10]]);

            $notes = Configuration::migrateEnvironment($configuration);

            $this->assertCount(1, $notes);
            $this->assertStringContainsString('FEDERATION_SCANNING_DNS_LOOKUPS', $notes[0]);
            $this->assertStringContainsString('FEDERATION_SCANNING_DNS_LOOKUP_LIMIT', $notes[0]);
            $this->assertSame(0, $configuration->get('scanning.dns_lookup_limit'));
            $this->assertArrayNotHasKey('dns_lookups', $configuration->get('scanning'));
        }

        public function testDeprecatedEnvironmentOverridesConvertedSetting(): void
        {
            $this->setEnvironment('FEDERATION_URL_UPLOADS', 'false');
            $configuration = new InMemoryConfiguration(['remote_retrieval' => ['uploads' => true, 'scans' => false]]);

            Configuration::migrateEnvironment($configuration);

            $this->assertSame('false', $configuration->get('remote_retrieval.uploads'));
            $this->assertFalse($configuration->get('remote_retrieval.scans'));
        }

        public function testAutoReportEnvironmentModeIsNotDeprecated(): void
        {
            $this->setEnvironment('FEDERATION_SCANNING_AUTO_REPORT', 'caution');
            $configuration = new InMemoryConfiguration(['scanning' => ['auto_report' => 'caution']]);

            $this->assertSame([], Configuration::migrateEnvironment($configuration));
        }

        public function testAutoReportEnvironmentBooleanIsDeprecated(): void
        {
            $this->setEnvironment('FEDERATION_SCANNING_AUTO_REPORT', 'false');
            $configuration = new InMemoryConfiguration(['scanning' => ['auto_report' => 'false']]);

            $notes = Configuration::migrateEnvironment($configuration);

            $this->assertCount(1, $notes);
            $this->assertSame('off', $configuration->get('scanning.auto_report'));
        }
    }
