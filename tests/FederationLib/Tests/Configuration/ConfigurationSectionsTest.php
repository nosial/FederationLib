<?php

    namespace FederationLib\Tests\Configuration;

    use FederationLib\Classes\Configuration\MaintenanceConfiguration;
    use FederationLib\Classes\Configuration\RedisConfiguration;
    use FederationLib\Classes\Configuration\RemoteRetrievalConfiguration;
    use FederationLib\Classes\Configuration\ScanningConfiguration;
    use FederationLib\Classes\Configuration\SearchConfiguration;
    use FederationLib\Classes\Configuration\ServerConfiguration;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Enums\ScanningRules;
    use PHPUnit\Framework\TestCase;

    class ConfigurationSectionsTest extends TestCase
    {
        public function testServerDefaults(): void
        {
            $server = new ServerConfiguration([]);

            $this->assertSame(100, $server->getMaxPageSize());
            foreach(ServerConfiguration::DEFAULT_PUBLIC as $item)
            {
                $this->assertTrue($server->isPublic($item), $item);
            }
            $this->assertFalse($server->isPublic('SEARCH'));
            $this->assertFalse($server->isPublic('ENTITY_METADATA'));
            // Telephone entities are hidden from unauthenticated requests by default (OFD "Telephone Entities")
            $this->assertFalse($server->isTelephoneEntitiesPublic());
        }

        public function testServerPublicList(): void
        {
            $server = new ServerConfiguration(['public' => 'entities, telephone_entities, bogus']);

            $this->assertTrue($server->isEntitiesPublic());
            $this->assertTrue($server->isTelephoneEntitiesPublic());
            $this->assertFalse($server->isEvidencePublic());
            $this->assertFalse($server->isScanContentPublic());
            $this->assertFalse($server->isPublic('BOGUS'));
        }

        public function testServerNothingPublic(): void
        {
            $server = new ServerConfiguration(['public' => []]);
            foreach(ServerConfiguration::PUBLIC_ITEMS as $item)
            {
                $this->assertFalse($server->isPublic($item), $item);
            }
        }

        public function testServerMaxPageSizeAppliesToEveryList(): void
        {
            $server = new ServerConfiguration(['max_page_size' => 25]);

            $this->assertSame(25, $server->getMaxPageSize());
            $this->assertSame(25, $server->getListEntitiesMaxItems());
            $this->assertSame(25, $server->getListEvidenceMaxItems());
            $this->assertSame(25, $server->getListAuditLogsMaxItems());
            $this->assertSame(25, $server->getListOperatorsMaxItems());
            $this->assertSame(25, $server->getListBlacklistMaxItems());
            $this->assertSame(25, $server->getListAttachmentsMaxItems());
            $this->assertSame(25, $server->getListReportsMaxItems());
        }

        public function testServerMaxPageSizeIsAtLeastOne(): void
        {
            $this->assertSame(1, (new ServerConfiguration(['max_page_size' => 0]))->getMaxPageSize());
            $this->assertSame(1, (new ServerConfiguration(['max_page_size' => -5]))->getMaxPageSize());
        }

        public function testRemoteRetrievalDefaults(): void
        {
            $remoteRetrieval = new RemoteRetrievalConfiguration([]);

            $this->assertTrue($remoteRetrieval->isUploadsEnabled());
            // The server does not retrieve URLs submitted for scanning by default
            $this->assertFalse($remoteRetrieval->isScansEnabled());
            $this->assertSame(30, $remoteRetrieval->getTimeout());
            $this->assertFalse($remoteRetrieval->isPrivateAllowed());
        }

        public function testRemoteRetrievalValues(): void
        {
            $remoteRetrieval = new RemoteRetrievalConfiguration(['uploads' => 'false', 'scans' => 'true', 'timeout' => '2m', 'allow_private' => true]);

            $this->assertFalse($remoteRetrieval->isUploadsEnabled());
            $this->assertTrue($remoteRetrieval->isScansEnabled());
            $this->assertSame(120, $remoteRetrieval->getTimeout());
            $this->assertTrue($remoteRetrieval->isPrivateAllowed());
        }

        public function testRemoteRetrievalTimeoutIsAtLeastOneSecond(): void
        {
            $this->assertSame(1, (new RemoteRetrievalConfiguration(['timeout' => 0]))->getTimeout());
        }

        public function testScanningDefaults(): void
        {
            $scanning = new ScanningConfiguration([]);

            $this->assertTrue($scanning->isAutoReport());
            $this->assertFalse($scanning->isAutoReportCaution());
            $this->assertSame(80.0, $scanning->getAutoReportThreshold());
            $this->assertSame(10, $scanning->getReputationGain());
            $this->assertSame(-5, $scanning->getReputationLoss());
            $this->assertSame(3, $scanning->getMentionMinAuthors());
            $this->assertSame(10, $scanning->getDnsLookupLimit());
            $this->assertTrue($scanning->isDnsLookupsEnabled());
            $this->assertSame(ScanningRules::AUTHOR_BLACKLISTED->getModifier(), $scanning->getModifierAuthorBlacklisted());
            $this->assertSame(1, $scanning->getReportReputation(ClassificationFlag::NORMAL));
            $this->assertSame(-10, $scanning->getReportReputation(ClassificationFlag::SUSPICIOUS));
            $this->assertSame(-20, $scanning->getReportReputation(ClassificationFlag::MALICIOUS));
            $this->assertTrue($scanning->isReportNamedEntityReputationEnabled());
            $this->assertSame(-50, $scanning->getBlacklistReputation());
            $this->assertSame(-10, $scanning->getBlacklistRelatedReputation());
        }

        public function testScanningFixedBounds(): void
        {
            $scanning = new ScanningConfiguration(['reputation_min_bound' => -5, 'risk_score_neutral_point' => 10]);

            $this->assertSame(ScanningConfiguration::REPUTATION_MIN_BOUND, $scanning->getReputationMinBound());
            $this->assertSame(ScanningConfiguration::REPUTATION_MAX_BOUND, $scanning->getReputationMaxBound());
            $this->assertSame(ScanningConfiguration::RISK_SCORE_NEUTRAL_POINT, $scanning->getRiskScoreNeutralPoint());
            $this->assertSame(ScanningConfiguration::RISK_SCORE_MIN_BOUND, $scanning->getRiskScoreMinBound());
            $this->assertSame(ScanningConfiguration::RISK_SCORE_MAX_BOUND, $scanning->getRiskScoreMaxBound());
        }

        public function testScanningAutoReportModes(): void
        {
            $caution = new ScanningConfiguration(['auto_report' => 'CAUTION', 'action_caution_threshold' => 55]);
            $this->assertTrue($caution->isAutoReport());
            $this->assertTrue($caution->isAutoReportCaution());
            $this->assertSame(55.0, $caution->getAutoReportThreshold());

            $off = new ScanningConfiguration(['auto_report' => 'off']);
            $this->assertFalse($off->isAutoReport());

            // Values of earlier releases are still understood
            $this->assertFalse((new ScanningConfiguration(['auto_report' => false]))->isAutoReport());
            $this->assertTrue((new ScanningConfiguration(['auto_report' => true]))->isAutoReport());
            $this->assertFalse((new ScanningConfiguration(['auto_report' => 'false']))->isAutoReport());
            $this->assertFalse((new ScanningConfiguration(['auto_report' => 'false']))->isAutoReportCaution());
        }

        public function testScanningModifiersOverrideOnlyTheirRules(): void
        {
            $scanning = new ScanningConfiguration(['modifiers' => 'AUTHOR_BLACKLISTED=-40, classification_malicious=50, BOGUS=1, AUTHOR_WHITELISTED=abc']);

            $this->assertSame(-40.0, $scanning->getModifierAuthorBlacklisted());
            $this->assertSame(50.0, $scanning->getModifierClassificationMalicious());
            $this->assertSame(ScanningRules::AUTHOR_WHITELISTED->getModifier(), $scanning->getModifierAuthorWhitelisted());
            $this->assertSame(ScanningRules::NAMED_ENTITY_BLACKLISTED->getModifier(), $scanning->getModifierNamedEntityBlacklisted());
        }

        public function testScanningReputationClamps(): void
        {
            $scanning = new ScanningConfiguration([
                'reputation_gain' => 50,
                'reputation_loss' => 5,
                'mention_min_authors' => 0,
                'report_reputation' => ['NORMAL' => 25, 'SUSPICIOUS' => 5, 'MALICIOUS' => -100],
                'blacklist_reputation' => 10,
            ]);

            // Incremental gains SHOULD NOT exceed 10 points (OFD "Reputation"), decreases are never positive
            $this->assertSame(10, $scanning->getReputationGain());
            $this->assertSame(0, $scanning->getReputationLoss());
            $this->assertSame(1, $scanning->getMentionMinAuthors());
            $this->assertSame(10, $scanning->getReportReputation(ClassificationFlag::NORMAL));
            $this->assertSame(0, $scanning->getReportReputation(ClassificationFlag::SUSPICIOUS));
            $this->assertSame(-100, $scanning->getReportReputation(ClassificationFlag::MALICIOUS));
            $this->assertSame(0, $scanning->getBlacklistReputation());

            $this->assertSame(0, (new ScanningConfiguration(['reputation_gain' => -3]))->getReputationGain());
        }

        public function testScanningNamedEntityReputationCanBeDisabled(): void
        {
            foreach([false, 'false', 'off', 'no', '0'] as $value)
            {
                $this->assertFalse((new ScanningConfiguration(['report_named_entity_reputation' => $value]))->isReportNamedEntityReputationEnabled(), var_export($value, true));
            }

            $scanning = new ScanningConfiguration(['report_named_entity_reputation' => 'NORMAL=2,MALICIOUS=-3']);
            $this->assertTrue($scanning->isReportNamedEntityReputationEnabled());
            $this->assertSame(2, $scanning->getReportNamedEntityReputation(ClassificationFlag::NORMAL));
            $this->assertSame(-5, $scanning->getReportNamedEntityReputation(ClassificationFlag::SUSPICIOUS));
            $this->assertSame(-3, $scanning->getReportNamedEntityReputation(ClassificationFlag::MALICIOUS));
        }

        public function testScanningDefaultConcernsExcludeAdvertisingAndTracking(): void
        {
            $scanning = new ScanningConfiguration([]);

            $this->assertSame(IncidentType::cases(), $scanning->getConcerns());
            $this->assertNotContains(IncidentType::ADVERTISING, $scanning->getDefaultConcerns());
            $this->assertNotContains(IncidentType::TRACKING, $scanning->getDefaultConcerns());
            $this->assertContains(IncidentType::SPAM, $scanning->getDefaultConcerns());
            $this->assertContains(IncidentType::OTHER, $scanning->getDefaultConcerns());
        }

        public function testScanningDefaultConcernsAreSubsetOfConcerns(): void
        {
            $scanning = new ScanningConfiguration(['concerns' => 'spam, phishing, bogus', 'default_concerns' => ['SPAM', 'MALWARE']]);

            $this->assertSame([IncidentType::SPAM, IncidentType::PHISHING], $scanning->getConcerns());
            $this->assertSame([IncidentType::SPAM], $scanning->getDefaultConcerns());
        }

        public function testScanningDnsLookupsCanBeDisabled(): void
        {
            $scanning = new ScanningConfiguration(['dns_lookup_limit' => 0]);
            $this->assertFalse($scanning->isDnsLookupsEnabled());
            $this->assertSame(0, $scanning->getDnsLookupLimit());

            $this->assertSame(0, (new ScanningConfiguration(['dns_lookup_limit' => -1]))->getDnsLookupLimit());
        }

        public function testCollectsPublicScanContent(): void
        {
            $block = new ScanningConfiguration(['auto_report' => 'block']);
            $off = new ScanningConfiguration(['auto_report' => 'off']);

            // Only unauthenticated scans are concerned, they can only be made when content scanning is public
            $this->assertFalse($block->collectsPublicScanContent(false));
            $this->assertFalse($block->collectsPublicScanContent(false, true));
            $this->assertTrue($block->collectsPublicScanContent(true));
            $this->assertFalse($off->collectsPublicScanContent(true));
            // A plugin that stores scanned content collects it even without automatic reports
            $this->assertTrue($off->collectsPublicScanContent(true, true));
        }

        public function testMaintenanceDefaults(): void
        {
            $maintenance = new MaintenanceConfiguration([]);

            $this->assertTrue($maintenance->isEnabled());
            $this->assertSame(63072000, $maintenance->getRetention('EVIDENCE'));
            $this->assertSame(31536000, $maintenance->getRetention('BLACKLIST'));
            $this->assertNull($maintenance->getRetention('ENTITIES'));
            $this->assertFalse($maintenance->isCleanEntitiesEnabled());
            $this->assertTrue($maintenance->isCleanEvidenceEnabled());
            $this->assertSame(63072000, $maintenance->getCleanEvidenceTtl());
        }

        public function testMaintenanceRetention(): void
        {
            $maintenance = new MaintenanceConfiguration(['enabled' => 'false', 'retention' => 'evidence=30d, ENTITIES=1y, AUDIT_LOGS=off, REPORTS=0']);

            $this->assertFalse($maintenance->isEnabled());
            $this->assertSame(2592000, $maintenance->getRetention('EVIDENCE'));
            $this->assertSame(31536000, $maintenance->getRetention('ENTITIES'));
            $this->assertTrue($maintenance->isCleanEntitiesEnabled());
            $this->assertNull($maintenance->getRetention('AUDIT_LOGS'));
            $this->assertFalse($maintenance->isCleanAuditLogsEnabled());
            // A retention of 0 would remove every record, it keeps them instead
            $this->assertNull($maintenance->getRetention('REPORTS'));
            // Types that are not configured keep their default retention
            $this->assertSame(31536000, $maintenance->getRetention('BLACKLIST'));
            $this->assertNull($maintenance->getRetention('BOGUS'));
        }

        public function testSearchTypes(): void
        {
            $default = new SearchConfiguration([]);
            $this->assertTrue($default->isEnabled());
            $this->assertTrue($default->isEntitiesEnabled());
            $this->assertFalse($default->isAttachmentsEnabled());
            $this->assertFalse($default->isPublicSearch());

            $configured = new SearchConfiguration(['types' => 'attachment,entity'], true);
            $this->assertTrue($configured->isPublicSearch());
            $this->assertTrue($configured->isTypeEnabled(RecordType::ATTACHMENT));
            $this->assertTrue($configured->isTypeEnabled(RecordType::ENTITY));
            $this->assertFalse($configured->isTypeEnabled(RecordType::EVIDENCE));

            $disabled = new SearchConfiguration(['types' => []]);
            $this->assertFalse($disabled->isEnabled());
        }

        public function testRedisRecordCache(): void
        {
            $redis = new RedisConfiguration(['enabled' => 'true', 'record_cache' => 'ENTITY=100/5m, EVIDENCE=0', 'search_cache_ttl' => '2m']);

            $this->assertTrue($redis->isEnabled());
            $this->assertSame(100, $redis->getRecordCacheLimit('ENTITY'));
            $this->assertSame(300, $redis->getRecordCacheTtl('ENTITY'));
            $this->assertFalse($redis->isRecordCacheEnabled('EVIDENCE'));
            $this->assertSame(RedisConfiguration::DEFAULT_RECORD_CACHE['EVIDENCE']['ttl'], $redis->getRecordCacheTtl('EVIDENCE'));
            $this->assertSame(RedisConfiguration::DEFAULT_RECORD_CACHE['REPORT']['limit'], $redis->getReportCacheLimit());
            $this->assertSame(120, $redis->getSearchCacheTtl());
        }

        public function testRedisRecordCacheAsMapping(): void
        {
            $redis = new RedisConfiguration(['record_cache' => ['operator' => ['limit' => 7, 'ttl' => '1h']]]);

            $this->assertFalse($redis->isEnabled());
            $this->assertSame(7, $redis->getOperatorCacheLimit());
            $this->assertSame(3600, $redis->getOperatorCacheTtl());
            $this->assertTrue($redis->isOperatorCacheEnabled());
        }
    }
