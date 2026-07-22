<?php

    namespace FederationLib\Tests\ContentScan;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TextGenerator;
    use FederationLib\Helpers\Logger;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ContentScanTest extends TestCase
    {
        private const string BENIGN_SAMPLE_TEXT = 'This is a simple, benign message used for scanning tests.';

        private FederationClient $client;
        private array $createdEntities = [];
        private array $createdOperators = [];
        private array $createdReports = [];
        private array $createdEvidenceRecords = [];

        private static ?FederationClient $trainingClient = null;
        private static ?string $trainingEntityUuid = null;
        private static array $createdTrainingReports = [];
        private static array $createdTrainingEvidence = [];

        public static function setUpBeforeClass(): void
        {
            self::$trainingClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            self::$trainingEntityUuid = self::$trainingClient->pushEntity('scan-training.com', 'scan_training');

            foreach (TextGenerator::trainingSet() as $sample)
            {
                $submission = self::$trainingClient->submitReport(self::$trainingEntityUuid, $sample['text'], IncidentType::OTHER);
                $reportUuid = $submission->getReport()->getUuid();
                self::$createdTrainingReports[] = $reportUuid;
                self::$createdTrainingEvidence[] = $submission->getEvidence()->getUuid();
                self::$trainingClient->closeReport($reportUuid, $sample['flag']);
            }

            sleep(3);
        }

        public static function tearDownAfterClass(): void
        {
            foreach (self::$createdTrainingReports as $reportUuid)
            {
                try
                {
                    self::$trainingClient?->deleteReport($reportUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete training report $reportUuid: " . $e->getMessage());
                }
            }

            foreach (self::$createdTrainingEvidence as $evidenceUuid)
            {
                try
                {
                    self::$trainingClient?->deleteEvidence($evidenceUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete training evidence $evidenceUuid: " . $e->getMessage());
                }
            }

            if (self::$trainingEntityUuid !== null)
            {
                try
                {
                    self::$trainingClient?->deleteEntity(self::$trainingEntityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete training entity " . self::$trainingEntityUuid . ": " . $e->getMessage());
                }
            }

            self::$trainingClient = null;
            self::$trainingEntityUuid = null;
            self::$createdTrainingReports = [];
            self::$createdTrainingEvidence = [];
        }

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdReports as $reportUuid)
            {
                try
                {
                    $this->client->deleteReport($reportUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete report $reportUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEvidenceRecords as $evidenceUuid)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete evidence $evidenceUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            $this->createdEntities = [];
            $this->createdOperators = [];
            $this->createdReports = [];
            $this->createdEvidenceRecords = [];
        }

        public function testScanContentBasic(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT);

            $this->assertNotNull($scanned);
            $this->assertIsArray($scanned->getResolvedEntities());
            $this->assertNull($scanned->getAuthorEntity());
            $this->assertIsFloat($scanned->getRiskScore());
            $this->assertGreaterThanOrEqual(0.0, $scanned->getRiskScore());
            $this->assertIsArray($scanned->getScanResults());
        }

        public function testScanContentEmptyContent(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Content cannot be empty');
            $this->client->scanContent('');
        }

        public function testScanContentWithAuthorByUuid(): void
        {
            $entityUuid = $this->client->pushEntity('scan-author-uuid.com', 'scan_author_uuid');
            $this->createdEntities[] = $entityUuid;

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertEquals($entityUuid, $scanned->getAuthorEntity()->getEntity()->getUuid());
        }

        public function testScanContentWithAuthorByHash(): void
        {
            $host = 'scan-author-hash.com';
            $id = 'scan_author_hash';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $hash = Utilities::hashEntity($host, $id);
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $hash);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertEquals($entityUuid, $scanned->getAuthorEntity()->getEntity()->getUuid());
        }

        public function testScanContentWithAuthorByAddress(): void
        {
            $host = 'scan-author-address.com';
            $id = 'scan_author_address';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $address = $id . '@' . $host;
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $address);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertEquals($entityUuid, $scanned->getAuthorEntity()->getEntity()->getUuid());
        }

        public function testScanContentWithInvalidAuthor(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, 'not-a-valid-identifier');
            $this->assertNotNull($scanned);
            $this->assertNull($scanned->getAuthorEntity());
        }

        public function testScanContentResolvesDomain(): void
        {
            $host = 'scan-domain.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $text = "Check out $host for more information. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(1, count($scanned->getResolvedEntities()), 'Expected at least one resolved entity for the domain');

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the pushed domain to be resolved from the content');
        }

        public function testScanContentResolvesUrl(): void
        {
            $host = 'scan-url.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $text = "Visit https://$host/path?q=test for details. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(1, count($scanned->getResolvedEntities()), 'Expected at least one resolved entity for the URL');

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the pushed domain to be resolved from the URL');
        }

        public function testScanContentResolvesEmail(): void
        {
            $host = 'scan-email.com';
            $id = 'scan_email_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $email = $id . '@' . $host;
            $text = "Contact me at $email for more info. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(1, count($scanned->getResolvedEntities()), 'Expected at least one resolved entity for the email');

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the pushed email entity to be resolved from the content');
        }

        public function testScanContentResolvesIpv4(): void
        {
            $ip = '192.168.55.42';
            $entityUuid = $this->client->pushEntity($ip);
            $this->createdEntities[] = $entityUuid;

            $text = "Server is located at $ip today. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(1, count($scanned->getResolvedEntities()), 'Expected at least one resolved entity for the IPv4 address');

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the pushed IPv4 entity to be resolved from the content');
        }

        public function testScanContentResolvesIpv6(): void
        {
            $ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
            $entityUuid = $this->client->pushEntity($ip);
            $this->createdEntities[] = $entityUuid;

            $text = "The server address is $ip please note it. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(1, count($scanned->getResolvedEntities()), 'Expected at least one resolved entity for the IPv6 address');

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the pushed IPv6 entity to be resolved from the content');
        }

        public function testScanContentResolvesMultipleEntities(): void
        {
            $domainHost = 'scan-multi-domain.com';
            $emailHost = 'scan-multi-email.com';
            $emailId = 'multi_user';
            $ip = '203.0.113.10';

            $domainUuid = $this->client->pushEntity($domainHost);
            $emailUuid = $this->client->pushEntity($emailHost, $emailId);
            $ipUuid = $this->client->pushEntity($ip);

            $this->createdEntities[] = $domainUuid;
            $this->createdEntities[] = $emailUuid;
            $this->createdEntities[] = $ipUuid;

            $text = sprintf(
                "Visit %s and contact %s@%s or %s for details. %s",
                $domainHost,
                $emailId,
                $emailHost,
                $ip,
                self::BENIGN_SAMPLE_TEXT
            );

            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(3, count($scanned->getResolvedEntities()), 'Expected at least three resolved entities');

            $resolvedUuids = array_map(fn($entity) => $entity->getEntity()->getUuid(), $scanned->getResolvedEntities());
            $this->assertContains($domainUuid, $resolvedUuids);
            $this->assertContains($emailUuid, $resolvedUuids);
            $this->assertContains($ipUuid, $resolvedUuids);
        }

        public function testScanContentEntityPositions(): void
        {
            $host = 'scan-position.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $prefix = 'Before ';
            $text = $prefix . $host . ' after';
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertCount(1, $scanned->getResolvedEntities());

            $position = $scanned->getResolvedEntities()[0]->getEntityPosition();
            $this->assertNotNull($position);
            $this->assertEquals(strlen($prefix), $position->getOffset());
            $this->assertEquals(strlen($host), $position->getLength());
        }

        public function testScanContentWithTopK(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, null, 1);

            $this->assertNotNull($scanned);
            $this->assertIsArray($scanned->getResolvedEntities());
            $this->assertIsFloat($scanned->getRiskScore());
        }

        public function testScanContentWithThreshold(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, null, null, 0.5);

            $this->assertNotNull($scanned);
            $this->assertIsArray($scanned->getResolvedEntities());
            $this->assertIsFloat($scanned->getRiskScore());
        }

        public function testScanContentWithTopKAndThreshold(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, null, 2, 0.25);

            $this->assertNotNull($scanned);
            $this->assertIsArray($scanned->getResolvedEntities());
            $this->assertIsFloat($scanned->getRiskScore());
        }

        public function testScanContentWithMetadata(): void
        {
            $metadata = ['source' => 'ContentScanTest', 'batch_id' => uniqid('scan_')];

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, null, null, null, $metadata);

            $this->assertNotNull($scanned);
            $this->assertIsArray($scanned->getResolvedEntities());
        }

        public function testScanContentDoesNotResolveUnknownEntities(): void
        {
            $unknownHost = 'unknown-domain-' . uniqid() . '.test';
            $text = "Visit $unknownHost for details. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);

            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                $this->assertNotEquals($unknownHost, $resolvedEntity->getEntity()->getHost(), 'Unknown domain should not be resolved as an entity');
            }
        }

        public function testScanContentEntityPositionsForUrlEmailAndIps(): void
        {
            $domainHost = 'scan-position-domain.com';
            $emailHost = 'scan-position-email.com';
            $emailId = 'position_user';
            $ipv4 = '203.0.113.45';
            $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7335';

            $domainUuid = $this->client->pushEntity($domainHost);
            $emailUuid = $this->client->pushEntity($emailHost, $emailId);
            $ipv4Uuid = $this->client->pushEntity($ipv4);
            $ipv6Uuid = $this->client->pushEntity($ipv6);
            $this->createdEntities[] = $domainUuid;
            $this->createdEntities[] = $emailUuid;
            $this->createdEntities[] = $ipv4Uuid;
            $this->createdEntities[] = $ipv6Uuid;

            $urlPrefix = 'Check ';
            $url = "https://$domainHost/path";
            $emailPrefix = ' or email ';
            $email = "$emailId@$emailHost";
            $ipv4Prefix = ' or server ';
            $ipv6Prefix = ' or v6 ';

            $text = $urlPrefix . $url . $emailPrefix . $email . $ipv4Prefix . $ipv4 . $ipv6Prefix . $ipv6;

            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertGreaterThanOrEqual(4, count($scanned->getResolvedEntities()));

            $expected = [
                $domainUuid => strlen($urlPrefix),
                $emailUuid => strlen($urlPrefix . $url . $emailPrefix),
                $ipv4Uuid => strlen($urlPrefix . $url . $emailPrefix . $email . $ipv4Prefix),
                $ipv6Uuid => strlen($urlPrefix . $url . $emailPrefix . $email . $ipv4Prefix . $ipv4 . $ipv6Prefix),
            ];

            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                $uuid = $resolvedEntity->getEntity()->getUuid();
                $position = $resolvedEntity->getEntityPosition();
                $this->assertNotNull($position, "Expected position for resolved entity $uuid");
                $this->assertArrayHasKey($uuid, $expected, 'Unexpected resolved entity');
                $this->assertEquals($expected[$uuid], $position->getOffset(), "Wrong offset for entity $uuid");
            }
        }

        public function testGetSpecification(): void
        {
            $spec = $this->client->getSpecification();
            $this->assertNotNull($spec);
            $this->assertIsArray($spec);
            $this->assertArrayHasKey('openapi', $spec);
            $this->assertArrayHasKey('info', $spec);
            $this->assertArrayHasKey('paths', $spec);
            $this->assertArrayHasKey('/scan', $spec['paths']);
        }

    }
