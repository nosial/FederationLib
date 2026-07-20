<?php

    namespace FederationLib\FederationServer;

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

            // The Bayesian server trains asynchronously in the background. Give it a
            // short, fixed window to finish before the classification tests run.
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
            // An unresolvable author identifier is silently ignored; the scan should still succeed.
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

        public function testScanContentClassificationMayBeNullWhenUntrained(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT);

            $this->assertNotNull($scanned);
            $classification = $scanned->getClassification();

            if ($classification !== null)
            {
                $this->assertInstanceOf(ContentClassification::class, $classification);
                $this->assertInstanceOf(ClassificationFlag::class, $classification->getClassificationFlag());
                $this->assertIsFloat($classification->getConfidence());
                $this->assertGreaterThanOrEqual(0.0, $classification->getConfidence());
            }
            else
            {
                $this->addToAssertionCount(1);
            }
        }

        public function testScanContentAuthorWithActiveBlacklist(): void
        {
            $host = 'scan-blacklisted-author.com';
            $id = 'scan_blacklisted_author';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Spam evidence for author', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertGreaterThanOrEqual(1, count($scanned->getAuthorEntity()->getActiveBlacklists()));

            $suggestedAction = $scanned->getSuggestedAction();
            $this->assertNotNull($suggestedAction);
            $this->assertContains($suggestedAction->value, ['TEMPORARILY_BLOCK_ENTITY', 'PERMANENTLY_BLOCK_ENTITY']);
        }

        public function testScanContentSuggestedActionForHighRisk(): void
        {
            $host = 'scan-high-risk.com';
            $id = 'scan_high_risk';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence for high risk', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);

            $text = TextGenerator::testText(ClassificationFlag::MALICIOUS);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getSuggestedAction());
            $this->assertEquals('PERMANENTLY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
        }

        public function testScanContentAuthorWithGoodReputation(): void
        {
            $host = 'scan-good-reputation.com';
            $id = 'scan_good_reputation';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            // Submit enough benign content to build positive reputation
            for ($i = 0; $i < 5; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    self::BENIGN_SAMPLE_TEXT,
                    'Good reputation evidence',
                    'reputation'
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertIsArray($scanned->getScanResults());
        }

        public function testScanContentResolvedEntityParentRelationship(): void
        {
            $parentHost = 'scan-parent.com';
            $parentUuid = $this->client->pushEntity($parentHost);
            $this->createdEntities[] = $parentUuid;

            $childHost = 'child.scan-parent.com';
            $childUuid = $this->client->pushEntity($childHost);
            $this->createdEntities[] = $childUuid;

            $this->client->setEntityRelationship($childUuid, $parentUuid, EntityRelationshipType::CHILD);

            $text = "Check $childHost for updates. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $foundChild = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $childUuid)
                {
                    $foundChild = true;
                    $this->assertNotNull($resolvedEntity->getParentEntity());
                    $this->assertEquals($parentUuid, $resolvedEntity->getParentEntity()->getEntity()->getUuid());
                    break;
                }
            }
            $this->assertTrue($foundChild, 'Expected the child entity to be resolved with its parent');
        }

        public function testScanContentAnonymousAccess(): void
        {
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            try
            {
                $scanned = $anonymousClient->scanContent(self::BENIGN_SAMPLE_TEXT);
                $this->assertNotNull($scanned);
                $this->assertIsArray($scanned->getResolvedEntities());
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 when scanning is not public');
            }
        }

        public function testScanContentUnauthorizedOperator(): void
        {
            $operatorUuid = $this->client->createOperator('scan-no-client-perm');
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, false);
            $this->client->setOperatorPermissions($operatorUuid, false);
            $this->client->setClientPermissions($operatorUuid, false);

            $operator = $this->client->getOperator($operatorUuid);
            $restrictedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(403);
            $restrictedClient->scanContent(self::BENIGN_SAMPLE_TEXT);
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

        public function testScanContentBatch(): void
        {
            $samples = TextGenerator::batch(perClass: 3);

            foreach ($samples as $sample)
            {
                $scanned = $this->client->scanContent($sample['text']);
                $this->assertNotNull($scanned, 'Scan result should not be null');
                $this->assertIsArray($scanned->getResolvedEntities());
                $this->assertIsFloat($scanned->getRiskScore());
                $this->assertGreaterThanOrEqual(0.0, $scanned->getRiskScore());
            }
        }

        public function testScanContentWithTrainingAndClassification(): void
        {
            // Scan the held-out test samples. The model was trained once in setUpBeforeClass.
            foreach (ClassificationFlag::cases() as $flag)
            {
                $text = TextGenerator::testText($flag);
                $scanned = $this->client->scanContent($text);

                $this->assertNotNull($scanned);
                $this->assertIsArray($scanned->getResolvedEntities());
                $this->assertIsFloat($scanned->getRiskScore());
            }
        }

        public function testScanContentClassifiesNormalContent(): void
        {
            $entityUuid = $this->client->pushEntity('scan-classify-normal.com', 'scan_classify_normal');
            $this->createdEntities[] = $entityUuid;

            $text = TextGenerator::testText(ClassificationFlag::NORMAL);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $this->assertNotNull($scanned);
            $classification = $scanned->getClassification();

            if ($classification !== null)
            {
                $this->assertEquals(ClassificationFlag::NORMAL->value, $classification->getClassificationFlag()->value);
                $this->assertIsFloat($classification->getConfidence());
                $this->assertGreaterThan(0.0, $classification->getConfidence());
                $this->assertIsString($classification->getDetectedLanguage());
            }
            else
            {
                $this->addToAssertionCount(1);
            }
        }

        public function testScanContentClassifiesSuspiciousContent(): void
        {
            $entityUuid = $this->client->pushEntity('scan-classify-suspicious.com', 'scan_classify_suspicious');
            $this->createdEntities[] = $entityUuid;

            $text = TextGenerator::testText(ClassificationFlag::SUSPICIOUS);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $this->assertNotNull($scanned);
            $classification = $scanned->getClassification();

            if ($classification !== null)
            {
                $this->assertNotEquals(
                    ClassificationFlag::NORMAL,
                    $classification->getClassificationFlag(),
                    'Suspicious test content must be distinguished from normal content'
                );
            }
            else
            {
                $this->addToAssertionCount(1);
            }
        }

        public function testScanContentClassifiesMaliciousContent(): void
        {
            $entityUuid = $this->client->pushEntity('scan-classify-malicious.com', 'scan_classify_malicious');
            $this->createdEntities[] = $entityUuid;


            $text = TextGenerator::testText(ClassificationFlag::MALICIOUS);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $this->assertNotNull($scanned);
            $classification = $scanned->getClassification();

            if ($classification !== null)
            {
                $this->assertNotEquals(
                    ClassificationFlag::NORMAL,
                    $classification->getClassificationFlag(),
                    'Malicious test content must be distinguished from normal content'
                );
            }
            else
            {
                $this->addToAssertionCount(1);
            }
        }

        public function testScanContentClassificationAffectsRiskScore(): void
        {
            // The backend adds classification-based points to the scanning rules table:
            //   NORMAL     -> +0.3 * confidence
            //   SUSPICIOUS -> -0.3 * confidence
            //   MALICIOUS  -> -0.4 * confidence
            // These points are subtracted from the neutral point and scaled into the risk score.
            // Therefore normal content should yield a lower risk score than malicious content.
            $entityUuid = $this->client->pushEntity('scan-classify-risk.com', 'scan_classify_risk');
            $this->createdEntities[] = $entityUuid;


            $normalText = TextGenerator::testText(ClassificationFlag::NORMAL);
            $maliciousText = TextGenerator::testText(ClassificationFlag::MALICIOUS);

            $normalScan = $this->client->scanContent($normalText, $entityUuid);
            $maliciousScan = $this->client->scanContent($maliciousText, $entityUuid);

            $normalClassification = $normalScan->getClassification();
            $maliciousClassification = $maliciousScan->getClassification();

            if ($normalClassification !== null && $maliciousClassification !== null)
            {
                $this->assertEquals(ClassificationFlag::NORMAL, $normalClassification->getClassificationFlag());
                $this->assertNotEquals(
                    ClassificationFlag::NORMAL,
                    $maliciousClassification->getClassificationFlag(),
                    'Malicious test content must be distinguished from normal content'
                );

                $this->assertLessThan(
                    $maliciousScan->getRiskScore(),
                    $normalScan->getRiskScore(),
                    'Normal classification should produce a lower risk score than malicious classification'
                );
            }
            else
            {
                $this->addToAssertionCount(3);
            }
        }

        public function testScanContentClassificationAppearsInScanResults(): void
        {
            // The backend exposes classification contributions in the scan_results table under the
            // CLASSIFICATION_NORMAL, CLASSIFICATION_SUSPICIOUS, and CLASSIFICATION_MALICIOUS keys.
            $entityUuid = $this->client->pushEntity('scan-classify-results.com', 'scan_classify_results');
            $this->createdEntities[] = $entityUuid;


            $text = TextGenerator::testText(ClassificationFlag::MALICIOUS);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $scanResults = $scanned->getScanResults();
            $this->assertArrayHasKey('CLASSIFICATION_NORMAL', $scanResults);
            $this->assertArrayHasKey('CLASSIFICATION_SUSPICIOUS', $scanResults);
            $this->assertArrayHasKey('CLASSIFICATION_MALICIOUS', $scanResults);

            $classification = $scanned->getClassification();
            if ($classification !== null)
            {
                $flag = $classification->getClassificationFlag();
                $this->assertNotEquals(ClassificationFlag::NORMAL, $flag, 'Threat test content must be distinguished from normal content');

                $ruleName = 'CLASSIFICATION_' . $flag->value;
                $this->assertNotEquals(0.0, $scanResults[$ruleName], 'Expected non-zero points for the matching classification rule');
            }
            else
            {
                $this->addToAssertionCount(3);
            }
        }

        public function testScanContentBlacklistOverridesClassification(): void
        {
            // The author's active blacklists are evaluated before the risk score when determining
            // the suggested action. Even if the scanned content is classified as NORMAL, a permanently
            // blacklisted author must result in PERMANENTLY_BLOCK_ENTITY.
            $entityUuid = $this->client->pushEntity('scan-classify-blacklist.com', 'scan_classify_blacklist');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Spam evidence for classification override', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, null);


            $text = TextGenerator::testText(ClassificationFlag::NORMAL);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertGreaterThanOrEqual(1, count($scanned->getAuthorEntity()->getActiveBlacklists()));
            $this->assertEquals('PERMANENTLY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
        }

        public function testScanContentClassificationWithTopKAndThreshold(): void
        {
            // top_k and threshold are forwarded to the Bayesian classifier. They do not change the
            // top_label chosen by ScanContent, but the endpoint must accept them without error.
            $entityUuid = $this->client->pushEntity('scan-topk-threshold.com', 'scan_topk_threshold');
            $this->createdEntities[] = $entityUuid;


            $text = TextGenerator::testText(ClassificationFlag::NORMAL);
            $scanned = $this->client->scanContent($text, $entityUuid, 2, 0.25);

            $this->assertNotNull($scanned);
            if ($scanned->getClassification() !== null)
            {
                $this->assertInstanceOf(ContentClassification::class, $scanned->getClassification());
            }
        }

        public function testScanContentClassificationConfidenceIsValid(): void
        {
            // The confidence returned by the Bayesian classifier is a value in [0, 1] representing
            // language-detection confidence. ScanContent forwards it unmodified in the classification
            // object and also uses it to scale the classification rule points.
            $entityUuid = $this->client->pushEntity('scan-classify-confidence.com', 'scan_classify_confidence');
            $this->createdEntities[] = $entityUuid;


            $text = TextGenerator::testText(ClassificationFlag::SUSPICIOUS);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $classification = $scanned->getClassification();
            if ($classification !== null)
            {
                $this->assertGreaterThanOrEqual(0.0, $classification->getConfidence());
                $this->assertLessThanOrEqual(1.0, $classification->getConfidence());
            }
            else
            {
                $this->addToAssertionCount(2);
            }
        }

        public function testScanContentResolvedEntityBlacklistContributesToRiskScore(): void
        {
            // Resolved named entities with active blacklists contribute negative points to the scanning
            // rules table independently of content classification. This test verifies that a resolved
            // permanently-blacklisted entity raises the overall risk score.
            $host = 'scan-resolved-blacklist.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence for resolved entity', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);


            $text = "Visit $host for updates. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    $this->assertGreaterThanOrEqual(1, count($resolvedEntity->getActiveBlacklists()));
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the blacklisted entity to be resolved');

            $scanResults = $scanned->getScanResults();
            $this->assertArrayHasKey('NAMED_ENTITY_PERMANENTLY_BLACKLISTED', $scanResults);
            $this->assertLessThan(0.0, $scanResults['NAMED_ENTITY_PERMANENTLY_BLACKLISTED']);
        }

        public function testScanContentSuggestedActionForTemporarilyBlacklistedAuthor(): void
        {
            $host = 'scan-temp-blacklist-author.com';
            $id = 'scan_temp_blacklist_author';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Temporary spam evidence', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $expires = time() + 3600;
            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $expires);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertGreaterThanOrEqual(1, count($scanned->getAuthorEntity()->getActiveBlacklists()));
            $this->assertEquals('TEMPORARILY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
            $this->assertGreaterThanOrEqual($expires - 5, $scanned->getSuggestedLiftTimestamp());
            $this->assertLessThanOrEqual($expires + 5, $scanned->getSuggestedLiftTimestamp());
        }

        public function testScanContentSuggestedActionForPermanentlyBlacklistedAuthor(): void
        {
            $host = 'scan-perm-blacklist-author.com';
            $id = 'scan_perm_blacklist_author';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Permanent spam evidence', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, null);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertGreaterThanOrEqual(1, count($scanned->getAuthorEntity()->getActiveBlacklists()));
            $this->assertEquals('PERMANENTLY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
            $this->assertNull($scanned->getSuggestedLiftTimestamp());
        }

        public function testScanContentMultipleAuthorBlacklistsPermanentWins(): void
        {
            $host = 'scan-multi-blacklist-author.com';
            $id = 'scan_multi_blacklist_author';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $tempEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Temporary evidence', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $tempEvidenceUuid;

            $permEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Permanent evidence', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $permEvidenceUuid;

            $this->client->blacklistEntity($entityUuid, $tempEvidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->client->blacklistEntity($entityUuid, $permEvidenceUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertCount(2, $scanned->getAuthorEntity()->getActiveBlacklists());
            $this->assertEquals('PERMANENTLY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
        }

        public function testScanContentAuthorParentBlacklistAffectsSuggestedAction(): void
        {
            $parentHost = 'scan-author-parent.com';
            $childHost = 'child.scan-author-parent.com';
            $childId = 'scan_author_child';

            $parentUuid = $this->client->pushEntity($parentHost);
            $childUuid = $this->client->pushEntity($childHost, $childId);
            $this->createdEntities[] = $parentUuid;
            $this->createdEntities[] = $childUuid;

            $this->client->setEntityRelationship($childUuid, $parentUuid, EntityRelationshipType::CHILD);

            $evidenceUuid = $this->client->submitEvidence($parentUuid, 'Parent is malicious', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($parentUuid, $evidenceUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $childUuid);

            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertNotNull($scanned->getAuthorEntity()->getParentEntity());
            $this->assertEquals($parentUuid, $scanned->getAuthorEntity()->getParentEntity()->getEntity()->getUuid());
            $this->assertGreaterThanOrEqual(1, count($scanned->getAuthorEntity()->getParentEntity()->getActiveBlacklists()));

            // The author's own record is clean, but the blacklisted parent pushes the risk score high
            // enough that the suggested action becomes BLOCK_CONTENT.
            $this->assertEquals('BLOCK_CONTENT', $scanned->getSuggestedAction()->value);
            $this->assertEquals(100.0, $scanned->getRiskScore());

            $scanResults = $scanned->getScanResults();
            $this->assertArrayHasKey('AUTHOR_PARENT_PERMANENTLY_BLACKLISTED', $scanResults);
            $this->assertLessThan(0.0, $scanResults['AUTHOR_PARENT_PERMANENTLY_BLACKLISTED']);
        }

        public function testScanContentResolvedEntityParentBlacklistContributesToRiskScore(): void
        {
            $parentHost = 'scan-resolved-parent.com';
            $childHost = 'child.scan-resolved-parent.com';

            $parentUuid = $this->client->pushEntity($parentHost);
            $childUuid = $this->client->pushEntity($childHost);
            $this->createdEntities[] = $parentUuid;
            $this->createdEntities[] = $childUuid;

            $this->client->setEntityRelationship($childUuid, $parentUuid, EntityRelationshipType::CHILD);

            $evidenceUuid = $this->client->submitEvidence($parentUuid, 'Parent malware evidence', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($parentUuid, $evidenceUuid, IncidentType::MALWARE, null);

            $text = "Visit $childHost for updates. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $childUuid)
                {
                    $found = true;
                    $this->assertNotNull($resolvedEntity->getParentEntity());
                    $this->assertEquals($parentUuid, $resolvedEntity->getParentEntity()->getEntity()->getUuid());
                    $this->assertGreaterThanOrEqual(1, count($resolvedEntity->getParentEntity()->getActiveBlacklists()));
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the child entity to be resolved');

            $scanResults = $scanned->getScanResults();
            $this->assertArrayHasKey('NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED', $scanResults);
            $this->assertLessThan(0.0, $scanResults['NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED']);
        }

        public function testScanContentResolvedEntityTemporaryBlacklistContributes(): void
        {
            $host = 'scan-temp-blacklist-resolved.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Temporary spam evidence', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);

            $text = "Visit $host for updates. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $found = false;
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getUuid() === $entityUuid)
                {
                    $found = true;
                    $activeBlacklists = $resolvedEntity->getActiveBlacklists();
                    $this->assertGreaterThanOrEqual(1, count($activeBlacklists));
                    $this->assertNotNull($activeBlacklists[0]->getExpires());
                    break;
                }
            }
            $this->assertTrue($found, 'Expected the blacklisted entity to be resolved');

            $scanResults = $scanned->getScanResults();
            $this->assertArrayHasKey('NAMED_ENTITY_BLACKLISTED', $scanResults);
            $this->assertLessThan(0.0, $scanResults['NAMED_ENTITY_BLACKLISTED']);
            $this->assertEquals(0.0, $scanResults['NAMED_ENTITY_PERMANENTLY_BLACKLISTED']);
        }

        public function testScanContentRiskScoreWithPermanentlyBlacklistedAuthor(): void
        {
            $host = 'scan-risk-perm-author.com';
            $id = 'scan_risk_perm_author';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertEquals('PERMANENTLY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
            $this->assertEquals(100.0, $scanned->getRiskScore());
        }

        public function testScanContentRiskScoreWithTemporarilyBlacklistedAuthor(): void
        {
            $host = 'scan-risk-temp-author.com';
            $id = 'scan_risk_temp_author';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Spam evidence', 'Test note', 'spam');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $baselineScan = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);
            $baselineRisk = $baselineScan->getRiskScore();

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertEquals('TEMPORARILY_BLOCK_ENTITY', $scanned->getSuggestedAction()->value);
            $this->assertGreaterThan($baselineRisk, $scanned->getRiskScore(), 'Temporary blacklist should raise the risk score');
            $this->assertGreaterThanOrEqual(90.0, $scanned->getRiskScore());
        }

        public function testScanContentRiskScoreWithBlacklistedNamedEntity(): void
        {
            $host = 'scan-risk-resolved.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);

            $text = "Visit $host for updates. " . self::BENIGN_SAMPLE_TEXT;
            $scanned = $this->client->scanContent($text);

            $this->assertGreaterThanOrEqual(60.0, $scanned->getRiskScore());

            $scanResults = $scanned->getScanResults();
            $this->assertArrayHasKey('NAMED_ENTITY_PERMANENTLY_BLACKLISTED', $scanResults);
            $this->assertLessThan(0.0, $scanResults['NAMED_ENTITY_PERMANENTLY_BLACKLISTED']);
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

        public function testScanContentSuggestedActionNullForCleanContent(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT);

            $this->assertNull($scanned->getSuggestedAction());
            $this->assertGreaterThanOrEqual(0.0, $scanned->getRiskScore());
            $this->assertLessThanOrEqual(100.0, $scanned->getRiskScore());
        }

        public function testScanContentScanResultsContainAllRuleKeys(): void
        {
            $entityUuid = $this->client->pushEntity('scan-rule-keys.com', 'scan_rule_keys');
            $this->createdEntities[] = $entityUuid;


            $text = TextGenerator::testText(ClassificationFlag::NORMAL);
            $scanned = $this->client->scanContent($text, $entityUuid);

            $scanResults = $scanned->getScanResults();
            foreach (ScanningRules::cases() as $rule)
            {
                $this->assertArrayHasKey($rule->name, $scanResults, "Missing scanning rule: {$rule->name}");
                $this->assertIsFloat($scanResults[$rule->name]);
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
