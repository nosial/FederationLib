<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class EvidenceClientTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createEvidenceRecords = [];
        private array $createdEntityRecords = [];
        private array $createdOperatorRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('tests');
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
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete evidence record $evidenceId: " . $e->getMessage(), $e);
                }
                catch (Exception $e)
                {
                    $this->logger->warning("Failed to delete evidence record $evidenceId: " . $e->getMessage(), $e);
                }
            }

            foreach ($this->createdEntityRecords as $entityId)
            {
                try
                {
                    $this->client->deleteEntity($entityId);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete entity record $entityId: " . $e->getMessage(), $e);
                }
                catch (Exception $e)
                {
                    $this->logger->warning("Failed to delete entity record $entityId: " . $e->getMessage(), $e);
                }
            }

            foreach ($this->createdOperatorRecords as $operatorId)
            {
                try
                {
                    $this->client->deleteOperator($operatorId);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete operator record $operatorId: " . $e->getMessage(), $e);
                }
                catch (Exception $e)
                {
                    $this->logger->warning("Failed to delete operator record $operatorId: " . $e->getMessage(), $e);
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

            // List all evidence records page by page and collect all UUIDs
            $allEvidenceUuids = [];
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
                    $allEvidenceUuids[] = $evidenceRecord->getUuid();
                }

                $page++;
            } while (count($evidenceList) === 5);
            foreach ($createdEntires as $uuid)
            {
                $this->assertContains($uuid, $allEvidenceUuids);
            }

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
            $createdEntries = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntries[] = $evidenceUuid;
                $this->createEvidenceRecords[] = $evidenceUuid;
                $this->assertNotNull($evidenceUuid);
                $this->assertNotEmpty($evidenceUuid);
            }

            // List all evidence records for the operator page by page and collect all UUIDs
            $allEvidenceUuids = [];
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
                    $allEvidenceUuids[] = $evidenceRecord->getUuid();
                    $this->assertEquals($selfOperator->getUuid(), $evidenceRecord->getOperatorUuid());
                }

                $page++;
            } while (count($evidenceList) === 5);

            foreach ($createdEntries as $uuid) {
                $this->assertContains($uuid, $allEvidenceUuids);
            }
            $this->assertGreaterThanOrEqual(10, count($createdEntries));
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

        // DURABILITY TESTS

        public function testEvidenceLifecycleIntegrity(): void
        {
            // Test complete evidence lifecycle: create entity, submit evidence, update, delete
            $entityUuid = $this->client->pushEntity('evidence-lifecycle.com', 'evidence_lifecycle_user');
            $this->createdEntityRecords[] = $entityUuid;

            // Submit evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Original evidence content', 'Original note', 'lifecycle_test');
            $this->createEvidenceRecords[] = $evidenceUuid;

            // Verify evidence creation
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals('Original evidence content', $evidenceRecord->getTextContent());
            $this->assertFalse($evidenceRecord->isConfidential());

            // Update confidentiality
            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $updatedRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($updatedRecord->isConfidential());

            // Update back to non-confidential
            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $revertedRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($revertedRecord->isConfidential());

            // Verify all other data remained intact
            $this->assertEquals($evidenceRecord->getEntityUuid(), $revertedRecord->getEntityUuid());
            $this->assertEquals($evidenceRecord->getTextContent(), $revertedRecord->getTextContent());
            $this->assertEquals($evidenceRecord->getNote(), $revertedRecord->getNote());
            $this->assertEquals($evidenceRecord->getTag(), $revertedRecord->getTag());

            // Delete evidence
            $this->client->deleteEvidence($evidenceUuid);

            // Verify deletion
            try {
                $this->client->getEvidenceRecord($evidenceUuid);
                $this->fail("Expected RequestException for deleted evidence");
            } catch (RequestException $e) {
                $this->assertEquals(404, $e->getCode());
            }

            // Remove from cleanup array since already deleted
            array_splice($this->createEvidenceRecords, array_search($evidenceUuid, $this->createEvidenceRecords), 1);
        }

        public function testHighVolumeEvidenceOperations(): void
        {
            // Test creating and managing large numbers of evidence records
            $entityUuid = $this->client->pushEntity('high-volume-evidence.com', 'high_volume_user');
            $this->createdEntityRecords[] = $entityUuid;

            $batchSize = 15;
            $evidenceUuids = [];

            // Create evidence records in batch
            for ($i = 0; $i < $batchSize; $i++) {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid, 
                    "Batch evidence content $i", 
                    "Batch note $i", 
                    "batch_$i",
                    $i % 2 === 0 // Alternate confidentiality
                );
                $this->createEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            // Verify all evidence records were created
            foreach ($evidenceUuids as $uuid) {
                $record = $this->client->getEvidenceRecord($uuid);
                $this->assertNotNull($record);
                $this->assertEquals($entityUuid, $record->getEntityUuid());
            }

            // Test pagination through evidence records
            $allEvidence = [];
            $page = 1;
            $pageSize = 5;
            do {
                $evidencePage = $this->client->listEvidence($page, $pageSize, true);
                $allEvidence = array_merge($allEvidence, $evidencePage);
                $page++;
            } while (count($evidencePage) === $pageSize && $page <= 10); // Safety limit

            // Verify our evidence records are in the results
            $foundUuids = array_map(fn($evidence) => $evidence->getUuid(), $allEvidence);
            foreach ($evidenceUuids as $uuid) {
                $this->assertContains($uuid, $foundUuids);
            }

            // Test entity-specific evidence listing
            $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid, 1, 100, true);
            $this->assertGreaterThanOrEqual($batchSize, count($entityEvidence));
        }

        public function testEvidenceContentVariations(): void
        {
            // Test evidence with various content types and edge cases
            $entityUuid = $this->client->pushEntity('content-variations.com', 'content_test_user');
            $this->createdEntityRecords[] = $entityUuid;

            $testCases = [
                ['content' => 'Single word', 'note' => 'Single word test', 'tag' => 'single'],
                ['content' => str_repeat('Long content ', 100), 'note' => 'Repetitive content', 'tag' => 'repetitive'],
                ['content' => "Multi\nline\ncontent\ntest", 'note' => 'Multiline test', 'tag' => 'multiline'],
                ['content' => 'Special chars: @#$%^&*()[]{}|;:\'",.<>?/', 'note' => 'Special chars', 'tag' => 'special'],
                ['content' => 'Unicode content: 你好世界 🌍', 'note' => 'Unicode test', 'tag' => 'unicode'],
            ];

            $evidenceUuids = [];
            foreach ($testCases as $index => $testCase) {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    $testCase['content'],
                    $testCase['note'],
                    $testCase['tag']
                );
                $this->createEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;

                // Verify content preservation
                $record = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($testCase['content'], $record->getTextContent());
                $this->assertEquals($testCase['note'], $record->getNote());
                $this->assertEquals($testCase['tag'], $record->getTag());
            }

            // Verify all evidence records exist
            $this->assertEquals(count($testCases), count($evidenceUuids));
        }

        public function testEvidenceConfidentialityConsistency(): void
        {
            // Test confidentiality settings and access control
            $entityUuid = $this->client->pushEntity('confidentiality-test.com', 'confidentiality_user');
            $this->createdEntityRecords[] = $entityUuid;

            // Create confidential evidence
            $confidentialUuid = $this->client->submitEvidence($entityUuid, 'Confidential content', 'Confidential note', 'confidential', true);
            $this->createEvidenceRecords[] = $confidentialUuid;

            // Create non-confidential evidence
            $publicUuid = $this->client->submitEvidence($entityUuid, 'Public content', 'Public note', 'public', false);
            $this->createEvidenceRecords[] = $publicUuid;

            // Verify confidentiality settings
            $confidentialRecord = $this->client->getEvidenceRecord($confidentialUuid);
            $this->assertTrue($confidentialRecord->isConfidential());

            $publicRecord = $this->client->getEvidenceRecord($publicUuid);
            $this->assertFalse($publicRecord->isConfidential());

            // Test toggling confidentiality multiple times
            for ($i = 0; $i < 3; $i++) {
                $this->client->updateEvidenceConfidentiality($publicUuid, true);
                $toggledRecord = $this->client->getEvidenceRecord($publicUuid);
                $this->assertTrue($toggledRecord->isConfidential());

                $this->client->updateEvidenceConfidentiality($publicUuid, false);
                $revertedRecord = $this->client->getEvidenceRecord($publicUuid);
                $this->assertFalse($revertedRecord->isConfidential());
            }

            // Verify content integrity after confidentiality changes
            $finalRecord = $this->client->getEvidenceRecord($publicUuid);
            $this->assertEquals($publicRecord->getTextContent(), $finalRecord->getTextContent());
            $this->assertEquals($publicRecord->getNote(), $finalRecord->getNote());
            $this->assertEquals($publicRecord->getTag(), $finalRecord->getTag());
        }

        public function testEvidenceAssociationIntegrity(): void
        {
            // Test evidence associations with entities and operators
            $selfOperator = $this->client->getSelf();
            $operatorUuid = $selfOperator->getUuid();

            // Create multiple entities
            $entityUuids = [];
            for ($i = 0; $i < 3; $i++) {
                $entityUuid = $this->client->pushEntity("association-test-$i.com", "association_user_$i");
                $this->createdEntityRecords[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            // Create evidence for each entity
            $evidenceMapping = [];
            foreach ($entityUuids as $index => $entityUuid) {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence for entity $index", "Note $index", "association");
                $this->createEvidenceRecords[] = $evidenceUuid;
                $evidenceMapping[$entityUuid] = $evidenceUuid;
            }

            // Verify associations
            foreach ($evidenceMapping as $entityUuid => $evidenceUuid) {
                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
                $this->assertEquals($operatorUuid, $evidenceRecord->getOperatorUuid());
            }

            // Test listing evidence by operator
            $operatorEvidence = $this->client->listOperatorEvidence($operatorUuid, 1, 100, true);
            $operatorEvidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $operatorEvidence);
            
            foreach ($evidenceMapping as $evidenceUuid) {
                $this->assertContains($evidenceUuid, $operatorEvidenceUuids);
            }

            // Test listing evidence by entity
            foreach ($evidenceMapping as $entityUuid => $evidenceUuid) {
                $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid);
                $entityEvidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $entityEvidence);
                $this->assertContains($evidenceUuid, $entityEvidenceUuids);
            }
        }

        public function testEvidenceConcurrentOperations(): void
        {
            // Test concurrent operations on evidence records
            $entityUuid = $this->client->pushEntity('concurrent-evidence.com', 'concurrent_user');
            $this->createdEntityRecords[] = $entityUuid;

            // Create evidence record
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Concurrent test content', 'Concurrent note', 'concurrent');
            $this->createEvidenceRecords[] = $evidenceUuid;

            // Perform multiple operations rapidly
            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $record1 = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($record1->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $record2 = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($record2->isConfidential());

            // Verify data integrity
            $this->assertEquals($record1->getTextContent(), $record2->getTextContent());
            $this->assertEquals($record1->getNote(), $record2->getNote());
            $this->assertEquals($record1->getTag(), $record2->getTag());
            $this->assertEquals($record1->getEntityUuid(), $record2->getEntityUuid());
            $this->assertEquals($record1->getOperatorUuid(), $record2->getOperatorUuid());
        }
    }