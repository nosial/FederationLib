<?php

    namespace FederationLib;

    use Exception;
    use PHPUnit\Framework\TestCase;

    class EvidenceClientTest extends TestCase
    {
        private FederationClient $client;
        private array $createEvidenceRecords = [];
        private array $createdEntityRecords = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createEvidenceRecords as $evidenceId)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceId);
                }
                catch (Exception)
                {
                    // Ignore exceptions during cleanup
                }
            }

            foreach ($this->createdEntityRecords as $entityId)
            {
                try
                {
                    $this->client->deleteEntity($entityId);
                }
                catch (Exception)
                {
                    // Ignore exceptions during cleanup
                }
            }

            $this->createEvidenceRecords = [];
            $this->createdEntityRecords = [];
        }

        public function testSubmitEvidence()
        {
            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Submit the evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Unauthorized Login Detected', 'Automatic Detection by System', 'unauthorized_login');
            $this->createEvidenceRecords[] = $evidenceUuid;
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Get self operator
            $selfOperator = $this->client->getSelf();
            $this->assertNotNull($selfOperator);

            // Fetch the evidence record
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNUll($evidenceRecord);
            $this->assertEquals('Unauthorized Login Detected', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('unauthorized_login', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals($selfOperator->getUuid(), $evidenceRecord->getOperatorUuid());
        }
    }