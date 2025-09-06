<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use PHPUnit\Framework\TestCase;

    class EvidenceClientTest extends TestCase
    {
        private FederationClient $client;
        private array $createEvidenceRecords = [];
        private array $createdEntityRecords = [];
        private array $createdOperatorRecords = [];

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

            foreach ($this->createdOperatorRecords as $operatorId)
            {
                try
                {
                    $this->client->deleteOperator($operatorId);
                }
                catch (Exception)
                {
                    // Ignore exceptions during cleanup
                }
            }

            $this->createEvidenceRecords = [];
            $this->createdEntityRecords = [];
            $this->createdOperatorRecords = [];
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

        public function testSubmitEvidenceUnauthorized(): void
        {
            // First create a basic operator
            $basicOperatorUuid = $this->client->createOperator('Basic Operator');
            $this->createdOperatorRecords[] = $basicOperatorUuid;
            $this->assertNotNull($basicOperatorUuid);

            // Disable all permissions for the basic operator
            $this->client->setManageBlacklistPermission($basicOperatorUuid, false);
            $this->client->setManageOperatorsPermission($basicOperatorUuid, false);
            $this->client->setClientPermission($basicOperatorUuid, false);

            // Verify the operator
            $basicOperator = $this->client->getOperator($basicOperatorUuid);
            $this->assertNotNull($basicOperator);
            $this->assertFalse($basicOperator->canManageBlacklist());
            $this->assertFalse($basicOperator->canManageOperators());
            $this->assertFalse($basicOperator->isClient());

            // Create a client for the basic operator
            $basicClient = new FederationClient(getenv('SERVER_ENDPOINT'), $basicOperator->getApiKey());
            $this->assertNotNull($basicClient);

            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Attempt to push evidence to the entity as the basic client
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $basicClient->submitEvidence($entityUuid, 'Test text content', 'Test note', 'test_tag');
        }

        public function testListEvidence(): void
        {
            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Create 10 evidence records
            $createdEntires = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntires[] = $evidenceUuid;
                $this->createEvidenceRecords[] = $evidenceUuid;
                $this->assertNotNull($evidenceUuid);
                $this->assertNotEmpty($evidenceUuid);
            }

            // List all evidence records page by page and verify each entry
            $page = 1;
            do
            {
                $evidenceList = $this->client->listEvidence($page, 5);
                if(count($evidenceList) === 0)
                {
                    break;
                }

                $this->assertNotNull($evidenceList);
                $this->assertNotEmpty($evidenceList);

                foreach ($evidenceList as $evidenceRecord)
                {
                    $this->assertContains($evidenceRecord->getUuid(), $createdEntires);
                }

                $page++;
            } while (count($evidenceList) === 5);

            $this->assertGreaterThanOrEqual(10, count($createdEntires));
        }

        public function testListOperatorEvidence(): void
        {
            // Get self operator
            $selfOperator = $this->client->getSelf();
            $this->assertNotNull($selfOperator);

            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Create 10 evidence records
            $createdEntires = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntires[] = $evidenceUuid;
                $this->createEvidenceRecords[] = $evidenceUuid;
                $this->assertNotNull($evidenceUuid);
                $this->assertNotEmpty($evidenceUuid);
            }

            // List all evidence records for the operator page by page and verify each entry
            $page = 1;
            do
            {
                $evidenceList = $this->client->listOperatorEvidence($selfOperator->getUuid(), $page, 5);
                if(count($evidenceList) === 0)
                {
                    break;
                }

                $this->assertNotNull($evidenceList);
                $this->assertNotEmpty($evidenceList);

                foreach ($evidenceList as $evidenceRecord)
                {
                    $this->assertContains($evidenceRecord->getUuid(), $createdEntires);
                    $this->assertEquals($selfOperator->getUuid(), $evidenceRecord->getOperatorUuid());
                }

                $page++;
            } while (count($evidenceList) === 5);

            $this->assertGreaterThanOrEqual(10, count($createdEntires));
        }

        public function testListEntityEvidence(): void
        {
            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Create 10 evidence records
            $createdEntires = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntires[] = $evidenceUuid;
                $this->createEvidenceRecords[] = $evidenceUuid;
                $this->assertNotNull($evidenceUuid);
                $this->assertNotEmpty($evidenceUuid);
            }

            // List all evidence records for the entity page by page and verify each entry
            $page = 1;
            do
            {
                $evidenceList = $this->client->listEntityEvidenceRecords($entityUuid, $page, 5);
                if(count($evidenceList) === 0)
                {
                    break;
                }

                $this->assertNotNull($evidenceList);
                $this->assertNotEmpty($evidenceList);

                foreach ($evidenceList as $evidenceRecord)
                {
                    $this->assertContains($evidenceRecord->getUuid(), $createdEntires);
                    $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
                }

                $page++;
            } while (count($evidenceList) === 5);

            $this->assertGreaterThanOrEqual(10, count($createdEntires));
        }

        public function testNonConfidentialEvidenceAccess(): void
        {
            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Submit the evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Non-Confidential Evidence', 'Automatic Detection by System', 'non_confidential_tag');
            $this->createEvidenceRecords[] = $evidenceUuid;
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Create an anonymous client
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->assertNotNull($anonymousClient);

            // Fetch the evidence record as an anonymous client
            $evidenceRecord = $anonymousClient->getEvidenceRecord($evidenceUuid);
            $this->assertNotNUll($evidenceRecord);
            $this->assertEquals('Non-Confidential Evidence', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('non_confidential_tag', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
        }

        public function testConfidentialEvidenceAccess(): void
        {
            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Submit the confidential evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential Evidence', 'Automatic Detection by System', 'confidential_tag', true);
            $this->createEvidenceRecords[] = $evidenceUuid;
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Fetch the confidential evidence as the root operator
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNUll($evidenceRecord);
            $this->assertEquals('Confidential Evidence', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('confidential_tag', $evidenceRecord->getTag());
            $this->assertTrue($evidenceRecord->isConfidential());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());

            // Create an anonymous client
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->assertNotNull($anonymousClient);

            // Attempt to fetch the confidential evidence record as an anonymous client
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $anonymousClient->getEvidenceRecord($evidenceUuid);
        }

        public function testLargeEvidenceTextContent(): void
        {
            // First, create an entity to associate the evidence with
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Generate large text content (e.g., 10,000 characters)
            $largeTextContent = str_repeat('A', 10000);

            // Submit the evidence with large text content
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $largeTextContent, 'Note for large content', 'large_content_tag');
            $this->createEvidenceRecords[] = $evidenceUuid;
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Fetch the evidence record
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNUll($evidenceRecord);
            $this->assertEquals($largeTextContent, $evidenceRecord->getTextContent());
            $this->assertEquals('Note for large content', $evidenceRecord->getNote());
            $this->assertEquals('large_content_tag', $evidenceRecord->getTag());
        }
    }