<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\EvidenceRecord;
    use PHPUnit\Framework\TestCase;

    class EvidenceTest extends TestCase
    {
        private FederationClient $client;
        private array $createdEvidence = [];
        private array $createdEntities = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up any evidence that was created during tests
            foreach ($this->createdEvidence as $evidenceUuid) {
                try {
                    $this->client->deleteEvidence($evidenceUuid);
                } catch (RequestException $e) {
                    // Ignore errors during cleanup
                }
            }
            $this->createdEvidence = [];

            // Clean up any entities that were created during tests
            foreach ($this->createdEntities as $entityId) {
                try {
                    $this->client->deleteEntity($entityId);
                } catch (RequestException $e) {
                    // Ignore errors during cleanup
                }
            }
            $this->createdEntities = [];
        }

        public function testSubmitEvidence()
        {
            // First create an entity to submit evidence for
            $entityId = 'test-evidence-entity-' . uniqid();
            $this->client->pushEntity($entityId, 'evidence.example.com');
            $this->createdEntities[] = $entityId;

            // Get the entity to obtain UUID
            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            // Submit evidence with text content
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence content', 'Test note', false);
            $this->assertNotEmpty($evidenceUuid);
            $this->createdEvidence[] = $evidenceUuid;

            // Verify the evidence was created
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);
            $this->assertEquals($evidenceUuid, $evidence->getUuid());
        }

        public function testSubmitEvidenceWithoutTextContent()
        {
            // First create an entity
            $entityId = 'test-evidence-no-text-' . uniqid();
            $this->client->pushEntity($entityId, 'evidence-no-text.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            // Submit evidence without text content
            $evidenceUuid = $this->client->submitEvidence($entityUuid, null, 'Evidence note only');
            $this->assertNotEmpty($evidenceUuid);
            $this->createdEvidence[] = $evidenceUuid;

            // Verify the evidence was created
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);
        }

        public function testSubmitConfidentialEvidence()
        {
            // First create an entity
            $entityId = 'test-confidential-evidence-' . uniqid();
            $this->client->pushEntity($entityId, 'confidential.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            // Submit confidential evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential evidence', 'Confidential note', true);
            $this->assertNotEmpty($evidenceUuid);
            $this->createdEvidence[] = $evidenceUuid;

            // Verify the evidence was created and is confidential
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);
            $this->assertTrue($evidence->isConfidential());
        }

        public function testSubmitEvidenceValidation()
        {
            // Test empty entity UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity UUID cannot be empty');
            $this->client->submitEvidence('', 'Test content');
        }

        public function testGetEvidenceRecord()
        {
            // First create an entity and evidence
            $entityId = 'test-get-evidence-' . uniqid();
            $this->client->pushEntity($entityId, 'get-evidence.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for retrieval test');
            $this->createdEvidence[] = $evidenceUuid;

            // Retrieve the evidence
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);
            $this->assertEquals($evidenceUuid, $evidence->getUuid());
            $this->assertNotEmpty($evidence->getTextContent());
        }

        public function testGetEvidenceRecordValidation()
        {
            // Test empty evidence UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence UUID cannot be empty');
            $this->client->getEvidenceRecord('');
        }

        public function testDeleteEvidence()
        {
            // First create an entity and evidence
            $entityId = 'test-delete-evidence-' . uniqid();
            $this->client->pushEntity($entityId, 'delete-evidence.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence to be deleted');

            // Verify evidence exists
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);

            // Delete the evidence
            $this->client->deleteEvidence($evidenceUuid);

            // Verify it's gone by expecting an exception when trying to retrieve it
            $this->expectException(RequestException::class);
            $this->client->getEvidenceRecord($evidenceUuid);
        }

        public function testDeleteEvidenceValidation()
        {
            // Test empty evidence UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence UUID cannot be empty');
            $this->client->deleteEvidence('');
        }

        public function testListEvidence()
        {
            // Create several test evidence records
            $evidenceUuids = [];
            for ($i = 0; $i < 3; $i++) {
                $entityId = 'test-list-evidence-' . $i . '-' . uniqid();
                $this->client->pushEntity($entityId, "list-evidence$i.example.com");
                $this->createdEntities[] = $entityId;

                $entity = $this->client->getEntityRecord($entityId);
                $entityUuid = $entity->getUuid();

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence content $i");
                $this->createdEvidence[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            // List evidence
            $evidenceList = $this->client->listEvidence();
            $this->assertIsArray($evidenceList);
            $this->assertGreaterThanOrEqual(3, count($evidenceList));

            // Verify our evidence records are in the list
            $foundEvidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $evidenceList);
            foreach ($evidenceUuids as $evidenceUuid) {
                $this->assertContains($evidenceUuid, $foundEvidenceUuids);
            }
        }

        public function testListEvidenceWithPagination()
        {
            // Test pagination parameters
            $evidencePage1 = $this->client->listEvidence(1, 5);
            $this->assertIsArray($evidencePage1);
            $this->assertLessThanOrEqual(5, count($evidencePage1));

            $evidencePage2 = $this->client->listEvidence(2, 5);
            $this->assertIsArray($evidencePage2);
            $this->assertLessThanOrEqual(5, count($evidencePage2));
        }

        public function testListEvidenceWithConfidential()
        {
            // Create confidential evidence
            $entityId = 'test-confidential-list-' . uniqid();
            $this->client->pushEntity($entityId, 'confidential-list.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential evidence', null, true);
            $this->createdEvidence[] = $evidenceUuid;

            // List evidence including confidential
            $evidenceList = $this->client->listEvidence(1, 100, true);
            $this->assertIsArray($evidenceList);

            // Verify confidential evidence is included
            $foundEvidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $evidenceList);
            $this->assertContains($evidenceUuid, $foundEvidenceUuids);
        }

        public function testListEvidenceValidation()
        {
            // Test invalid page
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Page must be greater than 0');
            $this->client->listEvidence(0, 10);
        }

        public function testListEvidenceLimitValidation()
        {
            // Test invalid limit
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Limit must be greater than 0');
            $this->client->listEvidence(1, 0);
        }

        public function testEvidenceLifecycle()
        {
            // Complete lifecycle test: create entity, submit evidence, retrieve, delete
            $entityId = 'test-evidence-lifecycle-' . uniqid();
            $this->client->pushEntity($entityId, 'lifecycle.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            // 1. Submit evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Lifecycle evidence', 'Lifecycle note', false);
            $this->assertNotEmpty($evidenceUuid);

            // 2. Retrieve evidence
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);
            $this->assertEquals($evidenceUuid, $evidence->getUuid());
            $this->assertEquals('Lifecycle evidence', $evidence->getTextContent());
            $this->assertEquals('Lifecycle note', $evidence->getNote());
            $this->assertFalse($evidence->isConfidential());

            // 3. Verify evidence appears in lists
            $evidenceList = $this->client->listEvidence();
            $foundEvidenceUuids = array_map(fn($ev) => $ev->getUuid(), $evidenceList);
            $this->assertContains($evidenceUuid, $foundEvidenceUuids);

            // 4. Delete evidence
            $this->client->deleteEvidence($evidenceUuid);

            // 5. Verify it's gone
            $this->expectException(RequestException::class);
            $this->client->getEvidenceRecord($evidenceUuid);
        }

        public function testEvidenceWithSpecialCharacters()
        {
            // Test evidence with special characters in content
            $entityId = 'test-special-chars-' . uniqid();
            $this->client->pushEntity($entityId, 'special.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $specialContent = 'Evidence with special chars: àáâãäåçèéêë ñóôõö ùúûü 中文 русский العربية 🚀🔥💯';
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $specialContent, 'Special note');
            $this->createdEvidence[] = $evidenceUuid;

            // Verify content is preserved
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($specialContent, $evidence->getTextContent());
        }

        public function testEvidenceWithLongContent()
        {
            // Test evidence with long content
            $entityId = 'test-long-content-' . uniqid();
            $this->client->pushEntity($entityId, 'long-content.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $longContent = str_repeat('This is a long piece of evidence content. ', 100);
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $longContent);
            $this->createdEvidence[] = $evidenceUuid;

            // Verify long content is preserved
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($longContent, $evidence->getTextContent());
        }

        public function testMultipleEvidenceForSameEntity()
        {
            // Test submitting multiple evidence records for the same entity
            $entityId = 'test-multiple-evidence-' . uniqid();
            $this->client->pushEntity($entityId, 'multiple.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $evidenceUuids = [];
            for ($i = 1; $i <= 3; $i++) {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence $i for same entity", "Note $i");
                $evidenceUuids[] = $evidenceUuid;
                $this->createdEvidence[] = $evidenceUuid;
            }

            // Verify all evidence records were created
            foreach ($evidenceUuids as $evidenceUuid) {
                $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertInstanceOf(EvidenceRecord::class, $evidence);
                $this->assertEquals($entityUuid, $evidence->getEntityUuid());
            }
        }

        public function testEvidenceTimestamps()
        {
            // Test that evidence timestamps are reasonable
            $entityId = 'test-timestamps-' . uniqid();
            $this->client->pushEntity($entityId, 'timestamps.example.com');
            $this->createdEntities[] = $entityId;

            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            $beforeSubmission = time();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Timestamp test evidence');
            $this->createdEvidence[] = $evidenceUuid;
            $afterSubmission = time();

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            
            // Verify timestamps are within reasonable range
            $this->assertGreaterThanOrEqual($beforeSubmission, $evidence->getCreated());
            $this->assertLessThanOrEqual($afterSubmission, $evidence->getCreated());
        }
    }
