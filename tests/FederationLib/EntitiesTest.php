<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Entity;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EvidenceRecord;
    use PHPUnit\Framework\TestCase;

    class EntitiesTest extends TestCase
    {
        private FederationClient $client;
        private array $createdEntities = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
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

        public function testPushEntity()
        {
            $entityId = 'test-entity-' . uniqid();
            $domain = 'example.com';

            $this->client->pushEntity($entityId, $domain);
            $this->createdEntities[] = $entityId;

            // Verify the entity was created by trying to retrieve it
            $entity = $this->client->getEntityRecord($entityId);
            $this->assertInstanceOf(Entity::class, $entity);
            $this->assertEquals($entityId, $entity->getId());
        }

        public function testPushEntityWithoutDomain()
        {
            $entityId = 'test-entity-no-domain-' . uniqid();

            $this->client->pushEntity($entityId);
            $this->createdEntities[] = $entityId;

            // Verify the entity was created
            $entity = $this->client->getEntityRecord($entityId);
            $this->assertInstanceOf(Entity::class, $entity);
            $this->assertEquals($entityId, $entity->getId());
        }

        public function testPushEntityValidation()
        {
            // Test empty entity ID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity ID cannot be empty');
            $this->client->pushEntity('');
        }

        public function testPushEntityEmptyDomainValidation()
        {
            // Test empty domain string
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Domain cannot be an empty string');
            $this->client->pushEntity('test-entity', '');
        }

        public function testGetEntityRecord()
        {
            $entityId = 'test-get-entity-' . uniqid();
            $domain = 'test.example.com';

            // First push an entity
            $this->client->pushEntity($entityId, $domain);
            $this->createdEntities[] = $entityId;

            // Then retrieve it
            $entity = $this->client->getEntityRecord($entityId);
            $this->assertInstanceOf(Entity::class, $entity);
            $this->assertEquals($entityId, $entity->getId());
            $this->assertNotEmpty($entity->getUuid());
        }

        public function testGetEntityRecordValidation()
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity identifier cannot be empty');
            $this->client->getEntityRecord('');
        }

        public function testDeleteEntity()
        {
            $entityId = 'test-delete-entity-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'delete.example.com');

            // Verify it exists
            $entity = $this->client->getEntityRecord($entityId);
            $this->assertInstanceOf(Entity::class, $entity);

            // Delete it
            $this->client->deleteEntity($entityId);

            // Verify it's gone by expecting an exception when trying to retrieve it
            $this->expectException(RequestException::class);
            $this->client->getEntityRecord($entityId);
        }

        public function testDeleteEntityValidation()
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity identifier cannot be empty');
            $this->client->deleteEntity('');
        }

        public function testListEntities()
        {
            // Create several test entities
            $entityIds = [];
            for ($i = 0; $i < 3; $i++) {
                $entityId = 'test-list-entities-' . $i . '-' . uniqid();
                $this->client->pushEntity($entityId, "list$i.example.com");
                $this->createdEntities[] = $entityId;
                $entityIds[] = $entityId;
            }

            // List entities
            $entities = $this->client->listEntities();
            $this->assertIsArray($entities);
            $this->assertGreaterThanOrEqual(3, count($entities));

            // Verify our entities are in the list
            $foundEntityIds = array_map(fn($entity) => $entity->getId(), $entities);
            foreach ($entityIds as $entityId) {
                $this->assertContains($entityId, $foundEntityIds);
            }
        }

        public function testListEntitiesWithPagination()
        {
            // Test pagination parameters
            $entitiesPage1 = $this->client->listEntities(1, 5);
            $this->assertIsArray($entitiesPage1);
            $this->assertLessThanOrEqual(5, count($entitiesPage1));

            $entitiesPage2 = $this->client->listEntities(2, 5);
            $this->assertIsArray($entitiesPage2);
            $this->assertLessThanOrEqual(5, count($entitiesPage2));
        }

        public function testListEntitiesValidation()
        {
            // Test invalid page
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Page must be greater than 0');
            $this->client->listEntities(0, 10);
        }

        public function testListEntitiesLimitValidation()
        {
            // Test invalid limit
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Limit must be greater than 0');
            $this->client->listEntities(1, 0);
        }

        public function testQueryEntity()
        {
            $entityId = 'test-query-entity-' . uniqid();
            $domain = 'query.example.com';

            // First push an entity
            $this->client->pushEntity($entityId, $domain);
            $this->createdEntities[] = $entityId;

            // Query the entity
            $queryResult = $this->client->queryEntity($entityId);
            $this->assertInstanceOf(EntityQueryResult::class, $queryResult);
            $this->assertNotNull($queryResult->getEntity());
            $this->assertEquals($entityId, $queryResult->getEntity()->getId());
        }

        public function testQueryEntityWithOptions()
        {
            $entityId = 'test-query-entity-options-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'query-options.example.com');
            $this->createdEntities[] = $entityId;

            // Query with different options
            $queryResult1 = $this->client->queryEntity($entityId, true, false);
            $this->assertInstanceOf(EntityQueryResult::class, $queryResult1);

            $queryResult2 = $this->client->queryEntity($entityId, false, true);
            $this->assertInstanceOf(EntityQueryResult::class, $queryResult2);

            $queryResult3 = $this->client->queryEntity($entityId, true, true);
            $this->assertInstanceOf(EntityQueryResult::class, $queryResult3);
        }

        public function testQueryEntityValidation()
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity identifier cannot be empty');
            $this->client->queryEntity('');
        }

        public function testListEntityAuditLogs()
        {
            $entityId = 'test-audit-logs-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'audit.example.com');
            $this->createdEntities[] = $entityId;

            // List audit logs for the entity
            $auditLogs = $this->client->listEntityAuditLogs($entityId);
            $this->assertIsArray($auditLogs);
            // There should be at least one audit log for the entity creation
            $this->assertGreaterThanOrEqual(1, count($auditLogs));

            foreach ($auditLogs as $auditLog) {
                $this->assertInstanceOf(AuditLog::class, $auditLog);
            }
        }

        public function testListEntityAuditLogsWithPagination()
        {
            $entityId = 'test-audit-logs-pagination-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'audit-pagination.example.com');
            $this->createdEntities[] = $entityId;

            // Test pagination
            $auditLogsPage1 = $this->client->listEntityAuditLogs($entityId, 1, 10);
            $this->assertIsArray($auditLogsPage1);
            $this->assertLessThanOrEqual(10, count($auditLogsPage1));

            $auditLogsPage2 = $this->client->listEntityAuditLogs($entityId, 2, 10);
            $this->assertIsArray($auditLogsPage2);
            $this->assertLessThanOrEqual(10, count($auditLogsPage2));
        }

        public function testListEntityAuditLogsValidation()
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity identifier cannot be empty');
            $this->client->listEntityAuditLogs('');
        }

        public function testListEntityBlacklistRecords()
        {
            $entityId = 'test-blacklist-records-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'blacklist.example.com');
            $this->createdEntities[] = $entityId;

            // List blacklist records for the entity
            $blacklistRecords = $this->client->listEntityBlacklistRecords($entityId);
            $this->assertIsArray($blacklistRecords);

            foreach ($blacklistRecords as $blacklistRecord) {
                $this->assertInstanceOf(BlacklistRecord::class, $blacklistRecord);
            }
        }

        public function testListEntityBlacklistRecordsWithOptions()
        {
            $entityId = 'test-blacklist-options-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'blacklist-options.example.com');
            $this->createdEntities[] = $entityId;

            // Test with includeLifted option
            $blacklistRecords = $this->client->listEntityBlacklistRecords($entityId, 1, 100, true);
            $this->assertIsArray($blacklistRecords);

            // Test with pagination
            $blacklistRecordsPage = $this->client->listEntityBlacklistRecords($entityId, 1, 10, false);
            $this->assertIsArray($blacklistRecordsPage);
            $this->assertLessThanOrEqual(10, count($blacklistRecordsPage));
        }

        public function testListEntityBlacklistRecordsValidation()
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity identifier cannot be empty');
            $this->client->listEntityBlacklistRecords('');
        }

        public function testListEntityEvidenceRecords()
        {
            $entityId = 'test-evidence-records-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'evidence.example.com');
            $this->createdEntities[] = $entityId;

            // List evidence records for the entity
            $evidenceRecords = $this->client->listEntityEvidenceRecords($entityId);
            $this->assertIsArray($evidenceRecords);

            foreach ($evidenceRecords as $evidenceRecord) {
                $this->assertInstanceOf(EvidenceRecord::class, $evidenceRecord);
            }
        }

        public function testListEntityEvidenceRecordsWithOptions()
        {
            $entityId = 'test-evidence-options-' . uniqid();

            // First push an entity
            $this->client->pushEntity($entityId, 'evidence-options.example.com');
            $this->createdEntities[] = $entityId;

            // Test with includeConfidential option
            $evidenceRecords = $this->client->listEntityEvidenceRecords($entityId, 1, 100, true);
            $this->assertIsArray($evidenceRecords);

            // Test with pagination
            $evidenceRecordsPage = $this->client->listEntityEvidenceRecords($entityId, 1, 10, false);
            $this->assertIsArray($evidenceRecordsPage);
            $this->assertLessThanOrEqual(10, count($evidenceRecordsPage));
        }

        public function testListEntityEvidenceRecordsValidation()
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Entity identifier cannot be empty');
            $this->client->listEntityEvidenceRecords('');
        }

        public function testEntityLifecycle()
        {
            $entityId = 'test-lifecycle-' . uniqid();
            $domain = 'lifecycle.example.com';

            // 1. Push entity
            $this->client->pushEntity($entityId, $domain);

            // 2. Verify it exists
            $entity = $this->client->getEntityRecord($entityId);
            $this->assertInstanceOf(Entity::class, $entity);
            $this->assertEquals($entityId, $entity->getId());

            // 3. Query entity
            $queryResult = $this->client->queryEntity($entityId);
            $this->assertInstanceOf(EntityQueryResult::class, $queryResult);
            $this->assertEquals($entityId, $queryResult->getEntity()->getId());

            // 4. Check audit logs (should have creation log)
            $auditLogs = $this->client->listEntityAuditLogs($entityId);
            $this->assertIsArray($auditLogs);
            $this->assertGreaterThanOrEqual(1, count($auditLogs));

            // 5. Delete entity
            $this->client->deleteEntity($entityId);

            // 6. Verify it's gone
            $this->expectException(RequestException::class);
            $this->client->getEntityRecord($entityId);
        }

        public function testMultipleEntitiesOperations()
        {
            $entityIds = [];
            $domains = ['multi1.example.com', 'multi2.example.com', 'multi3.example.com'];

            // Create multiple entities
            for ($i = 0; $i < 3; $i++) {
                $entityId = 'test-multi-' . $i . '-' . uniqid();
                $this->client->pushEntity($entityId, $domains[$i]);
                $this->createdEntities[] = $entityId;
                $entityIds[] = $entityId;
            }

            // Verify all entities exist
            foreach ($entityIds as $entityId) {
                $entity = $this->client->getEntityRecord($entityId);
                $this->assertInstanceOf(Entity::class, $entity);
                $this->assertEquals($entityId, $entity->getId());
            }

            // Query all entities
            foreach ($entityIds as $entityId) {
                $queryResult = $this->client->queryEntity($entityId);
                $this->assertInstanceOf(EntityQueryResult::class, $queryResult);
                $this->assertEquals($entityId, $queryResult->getEntity()->getId());
            }

            // List entities and verify our entities are included
            $allEntities = $this->client->listEntities();
            $allEntityIds = array_map(fn($entity) => $entity->getId(), $allEntities);

            foreach ($entityIds as $entityId) {
                $this->assertContains($entityId, $allEntityIds);
            }
        }

        public function testEntityWithSpecialCharacters()
        {
            // Test entity IDs with various characters
            $specialEntityIds = [
                'test-with-dashes-' . uniqid(),
                'test_with_underscores_' . uniqid(),
                'test.with.dots.' . uniqid(),
                'test@with@at.' . uniqid()
            ];

            foreach ($specialEntityIds as $entityId) {
                try {
                    $this->client->pushEntity($entityId, 'special.example.com');
                    $this->createdEntities[] = $entityId;

                    // Verify entity was created with correct ID
                    $entity = $this->client->getEntityRecord($entityId);
                    $this->assertEquals($entityId, $entity->getId());
                } catch (RequestException $e) {
                    // Some special characters might not be allowed, which is fine
                    // Just continue with the next test case
                }
            }
        }

        public function testEntityDuplicatePush()
        {
            $entityId = 'test-duplicate-' . uniqid();
            $domain = 'duplicate.example.com';

            // First push
            $this->client->pushEntity($entityId, $domain);
            $this->createdEntities[] = $entityId;

            // Second push of same entity (should not fail, might return OK instead of CREATED)
            $this->client->pushEntity($entityId, $domain);

            // Verify entity still exists and is accessible
            $entity = $this->client->getEntityRecord($entityId);
            $this->assertInstanceOf(Entity::class, $entity);
            $this->assertEquals($entityId, $entity->getId());
        }

        public function testEntityPaginationEdgeCases()
        {
            // Test with large page numbers
            $entities = $this->client->listEntities(999, 1);
            $this->assertIsArray($entities);

            // Test with large limits
            $entities = $this->client->listEntities(1, 1000);
            $this->assertIsArray($entities);
        }

        public function testEntityUuidVsIdAccess()
        {
            $entityId = 'test-uuid-access-' . uniqid();

            // Push entity
            $this->client->pushEntity($entityId, 'uuid.example.com');
            $this->createdEntities[] = $entityId;

            // Get entity to obtain UUID
            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            // Test accessing by UUID instead of ID
            $entityByUuid = $this->client->getEntityRecord($entityUuid);
            $this->assertInstanceOf(Entity::class, $entityByUuid);
            $this->assertEquals($entityId, $entityByUuid->getId());
            $this->assertEquals($entityUuid, $entityByUuid->getUuid());

            // Test querying by UUID
            $queryResultByUuid = $this->client->queryEntity($entityUuid);
            $this->assertInstanceOf(EntityQueryResult::class, $queryResultByUuid);
            $this->assertEquals($entityId, $queryResultByUuid->getEntity()->getId());
        }

        public function testInvalidEntityAccess()
        {
            // Test accessing non-existent entity
            $this->expectException(RequestException::class);
            $this->client->getEntityRecord('non-existent-entity-' . uniqid());
        }

        public function testEntityAuditLogPaginationValidation()
        {
            $entityId = 'test-audit-validation-' . uniqid();
            $this->client->pushEntity($entityId);
            $this->createdEntities[] = $entityId;

            // Test invalid page for audit logs
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Page must be greater than 0');
            $this->client->listEntityAuditLogs($entityId, 0, 10);
        }

        public function testEntityEvidenceRecordsPaginationValidation()
        {
            $entityId = 'test-evidence-validation-' . uniqid();
            $this->client->pushEntity($entityId);
            $this->createdEntities[] = $entityId;

            // Test invalid limit for evidence records
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Limit must be greater than 0');
            $this->client->listEntityEvidenceRecords($entityId, 1, 0);
        }

        public function testEntityBlacklistRecordsPaginationValidation()
        {
            $entityId = 'test-blacklist-validation-' . uniqid();
            $this->client->pushEntity($entityId);
            $this->createdEntities[] = $entityId;

            // Test invalid page for blacklist records
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Page must be greater than 0');
            $this->client->listEntityBlacklistRecords($entityId, 0, 10);
        }
    }
