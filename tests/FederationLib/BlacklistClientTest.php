<?php

    namespace FederationLib;

    use FederationLib\Enums\BlacklistType;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class BlacklistClientTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdBlacklistRecords as $blacklistRecordUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistRecordUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete blacklist record $blacklistRecordUuid: " . $e->getMessage(), $e);
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
                    $this->logger->warning("Failed to delete entity record $entityUuid: " . $e->getMessage(), $e);
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
                    $this->logger->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage(), $e);
                }
            }
        }

        public function testBlacklistEntity(): void
        {
            // First create an entity to blacklist
            $entityUuid = $this->client->pushEntity('example.com', 'john_test');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Fetch and verify the entity
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('john_test', $entityRecord->getId());
            $this->assertEquals('example.com', $entityRecord->getHost());


            // Submit evidence for the blacklist
            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Subscribe to my free crypto exchange!", "Automated Spam Detection", "spam");
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Get the operator UUID for these next series of checks
            $operatorUuid = $this->client->getSelf()->getUuid();
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch and verify the submitted evidence
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($evidenceUuid, $evidenceRecord->getUuid());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals("Subscribe to my free crypto exchange!", $evidenceRecord->getTextContent());
            $this->assertEquals("Automated Spam Detection", $evidenceRecord->getNote());
            $this->assertEquals("spam", $evidenceRecord->getTag());
            $this->assertEquals($operatorUuid, $evidenceRecord->getOperatorUuid());


            // Blacklist the entity for 3600 seconds.
            $expires = (time() + 3600);
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $expires);
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Fetch and verify the blacklist record
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertEquals($operatorUuid, $blacklistRecord->getOperatorUuid());
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertNotNull($blacklistRecord->getExpires());
            $this->assertEquals($expires, $blacklistRecord->getExpires());
            $this->assertFalse($blacklistRecord->isLifted());
        }
    }