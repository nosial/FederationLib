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

    class ContentScanSecurityTest extends TestCase
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

    }
