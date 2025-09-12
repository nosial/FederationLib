<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class PaginationTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('pagination-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up in reverse dependency order
            foreach ($this->createdBlacklistRecords as $blacklistUuid) {
                try {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting blacklist record $blacklistUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEvidenceRecords as $evidenceUuid) {
                try {
                    $this->client->deleteEvidence($evidenceUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting evidence record $evidenceUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityUuid) {
                try {
                    $this->client->deleteEntity($entityUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting entity $entityUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorUuid) {
                try {
                    $this->client->deleteOperator($operatorUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting operator $operatorUuid: " . $e->getMessage());
                }
            }

            // Reset arrays
            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        // ENTITIES PAGINATION TESTS

        public function testEntitiesPaginationBasic(): void
        {
            // Create test entities
            $entityCount = 15;
            $entityUuids = [];

            for ($i = 0; $i < $entityCount; $i++) {
                $entityUuid = $this->client->pushEntity("pagination-test-$i.com", "pagination_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            // Test pagination with different page sizes
            $pageSize = 5;
            $totalRetrieved = 0;
            $allRetrievedUuids = [];
            $page = 1;

            do {
                $entitiesPage = $this->client->listEntities($page, $pageSize);
                $this->assertIsArray($entitiesPage);
                $this->assertLessThanOrEqual($pageSize, count($entitiesPage));

                foreach ($entitiesPage as $entity) {
                    $allRetrievedUuids[] = $entity->getUuid();
                    $totalRetrieved++;
                }

                $page++;
            } while (count($entitiesPage) === $pageSize && $page <= 10); // Safety limit

            // Verify our entities are in the results
            foreach ($entityUuids as $uuid) {
                $this->assertContains($uuid, $allRetrievedUuids, "Entity $uuid not found in paginated results");
            }
        }

        public function testEntitiesPaginationEdgeCases(): void
        {
            // Test with page size 1
            $entities = $this->client->listEntities(1, 1);
            $this->assertIsArray($entities);
            $this->assertLessThanOrEqual(1, count($entities));

            // Test with large page size
            $entities = $this->client->listEntities(1, 100);
            $this->assertIsArray($entities);
            $this->assertLessThanOrEqual(100, count($entities));

            // Test with negative page
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(-1, 10);
        }

        public function testEntitiesPaginationInvalidLimits(): void
        {
            // Test with page size 0
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(1, 0);
        }

        public function testEntitiesPaginationConsistency(): void
        {
            // Create entities
            $entityCount = 8;
            for ($i = 0; $i < $entityCount; $i++) {
                $entityUuid = $this->client->pushEntity("consistency-test-$i.com", "consistency_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            // Get same page multiple times - should be consistent
            $page1First = $this->client->listEntities(1, 3);
            $page1Second = $this->client->listEntities(1, 3);

            $this->assertEquals(count($page1First), count($page1Second));
            
            // Compare UUIDs (order should be consistent)
            for ($i = 0; $i < count($page1First); $i++) {
                $this->assertEquals($page1First[$i]->getUuid(), $page1Second[$i]->getUuid());
            }
        }

        // OPERATORS PAGINATION TESTS

        public function testOperatorsPaginationBasic(): void
        {
            // Create test operators
            $operatorCount = 10;
            $operatorUuids = [];

            for ($i = 0; $i < $operatorCount; $i++)
            {
                $operatorUuid = $this->client->createOperator("pagination_operator_$i");
                $this->createdOperators[] = $operatorUuid;
                $operatorUuids[] = $operatorUuid;
            }

            // Test pagination
            $pageSize = 100;
            $allRetrievedUuids = [];
            $page = 1;

            do
            {
                $operatorsPage = $this->client->listOperators($page, $pageSize);
                $this->assertIsArray($operatorsPage);
                $this->assertLessThanOrEqual($pageSize, count($operatorsPage));

                foreach ($operatorsPage as $operator) {
                    $allRetrievedUuids[] = $operator->getUuid();
                }

                $page++;
            } while (count($operatorsPage) === $pageSize && $page <= 10); // Safety limit

            // Verify our operators are in the results
            foreach ($operatorUuids as $uuid) {
                $this->assertContains($uuid, $allRetrievedUuids, "Operator $uuid not found in paginated results");
            }
        }

        public function testOperatorsPaginationInvalidParameters(): void
        {
            // Test invalid page numbers
            $this->expectException(InvalidArgumentException::class);
            $this->client->listOperators(-1, 10);
        }

        // EVIDENCE PAGINATION TESTS

        public function testEvidencePaginationBasic(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('evidence-pagination.com', 'evidence_user');
            $this->createdEntities[] = $entityUuid;

            // Create multiple evidence records
            $evidenceCount = 12;
            $evidenceUuids = [];

            for ($i = 0; $i < $evidenceCount; $i++) {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Evidence content $i",
                    "Evidence note $i",
                    "pagination_tag_$i"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            // Test pagination
            $pageSize = 5;
            $allRetrievedUuids = [];
            $page = 1;

            do {
                $evidencePage = $this->client->listEvidence($page, $pageSize);
                $this->assertIsArray($evidencePage);
                $this->assertLessThanOrEqual($pageSize, count($evidencePage));

                foreach ($evidencePage as $evidence) {
                    $allRetrievedUuids[] = $evidence->getUuid();
                }

                $page++;
            } while (count($evidencePage) === $pageSize && $page <= 10); // Safety limit

            // Verify our evidence records are in the results
            foreach ($evidenceUuids as $uuid) {
                $this->assertContains($uuid, $allRetrievedUuids, "Evidence $uuid not found in paginated results");
            }
        }

        public function testEntityEvidencePaginationBasic(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('entity-evidence-pagination.com', 'entity_evidence_user');
            $this->createdEntities[] = $entityUuid;

            // Create multiple evidence records for this entity
            $evidenceCount = 8;
            $evidenceUuids = [];

            for ($i = 0; $i < $evidenceCount; $i++) {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Entity evidence content $i",
                    "Entity evidence note $i",
                    "entity_pagination_tag_$i"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            // Test pagination of entity-specific evidence
            $pageSize = 3;
            $allRetrievedUuids = [];
            $page = 1;

            do {
                $evidencePage = $this->client->listEntityEvidenceRecords($entityUuid, $page, $pageSize);
                $this->assertIsArray($evidencePage);
                $this->assertLessThanOrEqual($pageSize, count($evidencePage));

                foreach ($evidencePage as $evidence) {
                    $this->assertEquals($entityUuid, $evidence->getEntityUuid());
                    $allRetrievedUuids[] = $evidence->getUuid();
                }

                $page++;
            } while (count($evidencePage) === $pageSize && $page <= 10); // Safety limit

            // Verify all our evidence records are found
            $this->assertEquals($evidenceCount, count($allRetrievedUuids));
            foreach ($evidenceUuids as $uuid) {
                $this->assertContains($uuid, $allRetrievedUuids);
            }
        }

        // BLACKLIST PAGINATION TESTS

        public function testBlacklistPaginationBasic(): void
        {
            // Create entities and blacklist them
            $blacklistCount = 10;
            $blacklistUuids = [];

            for ($i = 0; $i < $blacklistCount; $i++) {
                $entityUuid = $this->client->pushEntity("blacklist-pagination-$i.com", "blacklist_user_$i");
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Blacklist evidence $i",
                    "Blacklist note $i",
                    "blacklist_pagination"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $blacklistUuid = $this->client->blacklistEntity(
                    $entityUuid,
                    $evidenceUuid,
                    BlacklistType::SPAM,
                    time() + 3600
                );
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            // Test pagination
            $pageSize = 100;
            $allRetrievedUuids = [];
            $page = 1;

            do {
                $blacklistPage = $this->client->listBlacklistRecords($page, $pageSize);
                $this->assertIsArray($blacklistPage);
                $this->assertLessThanOrEqual($pageSize, count($blacklistPage));

                foreach ($blacklistPage as $blacklistRecord) {
                    $allRetrievedUuids[] = $blacklistRecord->getUuid();
                }

                $page++;
            } while (count($blacklistPage) === $pageSize && $page <= 10); // Safety limit

            // Verify our blacklist records are in the results
            foreach ($blacklistUuids as $uuid) {
                $this->assertContains($uuid, $allRetrievedUuids, "Blacklist record $uuid not found in paginated results");
            }
        }

        // AUDIT LOG PAGINATION TESTS

        public function testAuditLogPaginationBasic(): void
        {
            // Generate some audit log entries
            for ($i = 0; $i < 5; $i++) {
                $operatorUuid = $this->client->createOperator("audit_pagination_operator_$i");
                $this->createdOperators[] = $operatorUuid;
                // Delete immediately to generate more audit entries
                $this->client->deleteOperator($operatorUuid);
                array_pop($this->createdOperators); // Remove from cleanup array since already deleted
            }

            // Test audit log pagination
            $pageSize = 3;
            $page = 1;
            $totalAuditLogs = 0;

            do {
                $auditLogsPage = $this->client->listAuditLogs($page, $pageSize);
                $this->assertIsArray($auditLogsPage);
                $this->assertLessThanOrEqual($pageSize, count($auditLogsPage));

                foreach ($auditLogsPage as $auditLog) {
                    $this->assertNotNull($auditLog->getUuid());
                    $this->assertNotNull($auditLog->getType());
                    $this->assertNotNull($auditLog->getMessage());
                    $this->assertIsInt($auditLog->getTimestamp());
                    $totalAuditLogs++;
                }

                $page++;
            } while (count($auditLogsPage) === $pageSize && $page <= 5); // Limit to avoid excessive testing

            $this->assertGreaterThan(0, $totalAuditLogs);
        }

        // CROSS-FUNCTIONAL PAGINATION TESTS

        public function testPaginationPerformance(): void
        {
            // Create entities for performance testing
            $entityCount = 20;
            for ($i = 0; $i < $entityCount; $i++) {
                $entityUuid = $this->client->pushEntity("performance-test-$i.com", "performance_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            $pageSize = 5;
            $maxPages = 5;
            $startTime = microtime(true);

            for ($page = 1; $page <= $maxPages; $page++) {
                $entities = $this->client->listEntities($page, $pageSize);
                $this->assertIsArray($entities);
                $this->assertLessThanOrEqual($pageSize, count($entities));
            }

            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;

            // Should complete in reasonable time
            $this->assertLessThan(30.0, $totalTime, "Pagination performance test took too long: {$totalTime}s");
            $this->logger->info("Pagination performance: {$totalTime}s for {$maxPages} pages of {$pageSize} entities each");
        }

        public function testPaginationMemoryUsage(): void
        {
            // Create entities
            $entityCount = 15;
            for ($i = 0; $i < $entityCount; $i++) {
                $entityUuid = $this->client->pushEntity("memory-test-$i.com", "memory_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            $initialMemory = memory_get_usage();
            $pageSize = 5;
            $maxPages = 3;

            for ($page = 1; $page <= $maxPages; $page++) {
                $entities = $this->client->listEntities($page, $pageSize);
                unset($entities); // Free memory immediately
            }

            gc_collect_cycles();
            $finalMemory = memory_get_usage();
            $memoryIncrease = $finalMemory - $initialMemory;

            // Memory usage should not increase significantly
            $this->assertLessThan(1024 * 1024, $memoryIncrease, "Pagination caused excessive memory usage: {$memoryIncrease} bytes");
            $this->logger->info("Pagination memory usage: {$memoryIncrease} bytes for {$maxPages} pages");
        }

        // PAGINATION BOUNDARY TESTS

        public function testPaginationEmptyResults(): void
        {
            // Try to get a page that definitely doesn't exist
            $entities = $this->client->listEntities(1000, 10);
            $this->assertIsArray($entities);
            $this->assertEmpty($entities);
        }

        public function testPaginationLargePageSizes(): void
        {
            // Test with large page size
            $entities = $this->client->listEntities(1, 1000);
            $this->assertIsArray($entities);
            // Should not error out, but may be limited by server
        }

        public function testPaginationOrderConsistency(): void
        {
            // Create entities with predictable names
            for ($i = 0; $i < 10; $i++) {
                $entityUuid = $this->client->pushEntity("order-test-$i.com", sprintf("order_user_%03d", $i));
                $this->createdEntities[] = $entityUuid;
            }

            // Get first two pages
            $page1 = $this->client->listEntities(1, 5);
            $page2 = $this->client->listEntities(2, 5);

            // No entity should appear in both pages
            $page1Uuids = array_map(fn($entity) => $entity->getUuid(), $page1);
            $page2Uuids = array_map(fn($entity) => $entity->getUuid(), $page2);

            $intersection = array_intersect($page1Uuids, $page2Uuids);
            $this->assertEmpty($intersection, "Entities appeared in multiple pages: " . implode(', ', $intersection));
        }
    }
