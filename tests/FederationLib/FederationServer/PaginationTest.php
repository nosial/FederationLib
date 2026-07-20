<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class PaginationTest extends TestCase
    {
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdBlacklistRecords as $blacklistUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
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

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        public function testEntitiesPaginationBasic(): void
        {
            $entityCount = 15;
            $entityUuids = [];

            for ($i = 0; $i < $entityCount; $i++)
            {
                $entityUuid = $this->client->pushEntity("pagination-test-$i.com", "pagination_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $pageSize = 5;
            $allRetrievedUuids = [];
            $page = 1;

            do
            {
                $entitiesPage = $this->client->listEntities($page, $pageSize);
                $this->assertIsArray($entitiesPage);
                $this->assertLessThanOrEqual($pageSize, count($entitiesPage));

                foreach ($entitiesPage as $entity)
                {
                    $allRetrievedUuids[] = $entity->getUuid();
                }

                $page++;
            } while (count($entitiesPage) === $pageSize && $page <= 10);

            foreach ($entityUuids as $uuid)
            {
                $this->assertContains($uuid, $allRetrievedUuids, "Entity $uuid not found in paginated results");
            }
        }

        public function testEntitiesPaginationEdgeCases(): void
        {
            $entities = $this->client->listEntities(1, 1);
            $this->assertIsArray($entities);
            $this->assertLessThanOrEqual(1, count($entities));

            $entities = $this->client->listEntities();
            $this->assertIsArray($entities);
            $this->assertLessThanOrEqual(100, count($entities));

            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(-1, 10);
        }

        public function testEntitiesPaginationInvalidLimits(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(1, 0);
        }

        public function testEntitiesPaginationConsistency(): void
        {
            $entityCount = 8;
            for ($i = 0; $i < $entityCount; $i++)
            {
                $entityUuid = $this->client->pushEntity("consistency-test-$i.com", "consistency_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            $page1First = $this->client->listEntities(1, 3);
            $page1Second = $this->client->listEntities(1, 3);

            $this->assertSameSize($page1First, $page1Second);

            for ($i = 0; $i < count($page1First); $i++)
            {
                $this->assertEquals($page1First[$i]->getUuid(), $page1Second[$i]->getUuid());
            }
        }

        public function testOperatorsPaginationBasic(): void
        {
            $operatorCount = 10;
            $operatorUuids = [];

            for ($i = 0; $i < $operatorCount; $i++)
            {
                $operatorUuid = $this->client->createOperator("pagination_operator_$i");
                $this->createdOperators[] = $operatorUuid;
                $operatorUuids[] = $operatorUuid;
            }

            $pageSize = 100;
            $allRetrievedUuids = [];
            $page = 1;

            do
            {
                $operatorsPage = $this->client->listOperators($page, $pageSize);
                $this->assertIsArray($operatorsPage);
                $this->assertLessThanOrEqual($pageSize, count($operatorsPage));

                foreach ($operatorsPage as $operator)
                {
                    $allRetrievedUuids[] = $operator->getUuid();
                }

                $page++;
            } while (count($operatorsPage) === $pageSize && $page <= 10);

            foreach ($operatorUuids as $uuid)
            {
                $this->assertContains($uuid, $allRetrievedUuids, "Operator $uuid not found in paginated results");
            }
        }

        public function testOperatorsPaginationInvalidParameters(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listOperators(-1, 10);
        }

        public function testEvidencePaginationBasic(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-pagination.com', 'evidence_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceCount = 12;
            $evidenceUuids = [];

            for ($i = 0; $i < $evidenceCount; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Evidence content $i",
                    "Evidence note $i",
                    "pagination_tag_$i"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            $pageSize = 5;
            $allRetrievedUuids = [];
            $page = 1;

            do
            {
                $evidencePage = $this->client->listEvidence($page, $pageSize);
                $this->assertIsArray($evidencePage);
                $this->assertLessThanOrEqual($pageSize, count($evidencePage));

                foreach ($evidencePage as $evidence)
                {
                    $allRetrievedUuids[] = $evidence->getUuid();
                }

                $page++;
            } while (count($evidencePage) === $pageSize && $page <= 10);

            foreach ($evidenceUuids as $uuid)
            {
                $this->assertContains($uuid, $allRetrievedUuids, "Evidence $uuid not found in paginated results");
            }
        }

        public function testEntityEvidencePaginationBasic(): void
        {
            $entityUuid = $this->client->pushEntity('entity-evidence-pagination.com', 'entity_evidence_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceCount = 8;
            $evidenceUuids = [];

            for ($i = 0; $i < $evidenceCount; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Entity evidence content $i",
                    "Entity evidence note $i",
                    "entity_pagination_tag_$i"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            $pageSize = 3;
            $allRetrievedUuids = [];
            $page = 1;

            do
            {
                $evidencePage = $this->client->listEntityEvidenceRecords($entityUuid, $page, $pageSize);
                $this->assertIsArray($evidencePage);
                $this->assertLessThanOrEqual($pageSize, count($evidencePage));

                foreach ($evidencePage as $evidence)
                {
                    $this->assertEquals($entityUuid, $evidence->getEntityUuid());
                    $allRetrievedUuids[] = $evidence->getUuid();
                }

                $page++;
            } while (count($evidencePage) === $pageSize && $page <= 10);

            $this->assertCount($evidenceCount, $allRetrievedUuids);
            foreach ($evidenceUuids as $uuid)
            {
                $this->assertContains($uuid, $allRetrievedUuids);
            }
        }

        public function testBlacklistPaginationBasic(): void
        {
            $blacklistCount = 10;
            $blacklistUuids = [];

            for ($i = 0; $i < $blacklistCount; $i++)
            {
                $entityUuid = $this->client->pushEntity("blacklist-pagination-$i.com", "blacklist_user_$i");
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Blacklist evidence $i",
                    "Blacklist note $i",
                    'blacklist_pagination'
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $blacklistUuid = $this->client->blacklistEntity(
                    $entityUuid,
                    $evidenceUuid,
                    IncidentType::SPAM,
                    time() + 3600
                );
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            $pageSize = 100;
            $allRetrievedUuids = [];
            $page = 1;

            do
            {
                $blacklistPage = $this->client->listBlacklistRecords($page, $pageSize);
                $this->assertIsArray($blacklistPage);
                $this->assertLessThanOrEqual($pageSize, count($blacklistPage));

                foreach ($blacklistPage as $blacklistRecord)
                {
                    $allRetrievedUuids[] = $blacklistRecord->getUuid();
                }

                $page++;
            } while (count($blacklistPage) === $pageSize && $page <= 10);

            foreach ($blacklistUuids as $uuid)
            {
                $this->assertContains($uuid, $allRetrievedUuids, "Blacklist record $uuid not found in paginated results");
            }
        }

        public function testAuditLogPaginationBasic(): void
        {
            for ($i = 0; $i < 5; $i++)
            {
                $operatorUuid = $this->client->createOperator("audit_pagination_operator_$i");
                $this->createdOperators[] = $operatorUuid;
                $this->client->deleteOperator($operatorUuid);
                array_pop($this->createdOperators);
            }

            $pageSize = 3;
            $page = 1;
            $totalAuditLogs = 0;

            do
            {
                $auditLogsPage = $this->client->listAuditLogs($page, $pageSize);
                $this->assertIsArray($auditLogsPage);
                $this->assertLessThanOrEqual($pageSize, count($auditLogsPage));

                foreach ($auditLogsPage as $auditLog)
                {
                    $this->assertNotEmpty($auditLog->getUuid());
                    $this->assertNotNull($auditLog->getType());
                    $this->assertNotEmpty($auditLog->getMessage());
                    $this->assertIsInt($auditLog->getTimestamp());
                    $totalAuditLogs++;
                }

                $page++;
            } while (count($auditLogsPage) === $pageSize && $page <= 5);

            $this->assertGreaterThan(0, $totalAuditLogs);
        }

        public function testPaginationEmptyResults(): void
        {
            $entities = $this->client->listEntities(1000, 10);
            $this->assertIsArray($entities);
            $this->assertEmpty($entities);
        }

        public function testPaginationLargePageSizes(): void
        {
            $entities = $this->client->listEntities(1, 1000);
            $this->assertIsArray($entities);
        }

        public function testPaginationOrderConsistency(): void
        {
            for ($i = 0; $i < 10; $i++)
            {
                $entityUuid = $this->client->pushEntity("order-test-$i.com", sprintf("order_user_%03d", $i));
                $this->createdEntities[] = $entityUuid;
            }

            $page1 = $this->client->listEntities(1, 5);
            $page2 = $this->client->listEntities(2, 5);

            $page1Uuids = array_map(fn($entity) => $entity->getUuid(), $page1);
            $page2Uuids = array_map(fn($entity) => $entity->getUuid(), $page2);

            $intersection = array_intersect($page1Uuids, $page2Uuids);
            $this->assertEmpty($intersection, 'Entities appeared in multiple pages: ' . implode(', ', $intersection));
        }

        public function testSecurityExcessivePaginationLimitIsTolerated(): void
        {
            $entities = $this->client->listEntities(1, 10000);
            $this->assertIsArray($entities);

            $operators = $this->client->listOperators(1, 10000);
            $this->assertIsArray($operators);

            $evidence = $this->client->listEvidence(1, 10000, true);
            $this->assertIsArray($evidence);

            $blacklist = $this->client->listBlacklistRecords(1, 10000, true);
            $this->assertIsArray($blacklist);
        }

        public function testSortByHostAscending(): void
        {
            $suffix = uniqid();
            $hosts = ['c-sort-' . $suffix . '.com', 'a-sort-' . $suffix . '.com', 'b-sort-' . $suffix . '.com'];
            $created = [];
            foreach ($hosts as $host)
            {
                $uuid = $this->client->pushEntity($host, 'sort_user_' . uniqid());
                $this->createdEntities[] = $uuid;
                $created[] = $uuid;
            }

            $allEntities = [];
            $page = 1;
            do
            {
                $entities = $this->client->listEntities($page, 100, null, 'host', 'ASC');
                $allEntities = array_merge($allEntities, $entities);
                $page++;
            } while (count($entities) > 0);

            $filtered = array_filter($allEntities, fn($e) => in_array($e->getUuid(), $created, true));
            $filtered = array_values($filtered);

            $this->assertCount(3, $filtered);
            $this->assertEquals('a-sort-' . $suffix . '.com', $filtered[0]->getHost());
            $this->assertEquals('b-sort-' . $suffix . '.com', $filtered[1]->getHost());
            $this->assertEquals('c-sort-' . $suffix . '.com', $filtered[2]->getHost());
        }

        public function testSortByHostDescending(): void
        {
            $suffix = uniqid();
            $hosts = ['a-sort-' . $suffix . '.com', 'b-sort-' . $suffix . '.com', 'c-sort-' . $suffix . '.com'];
            $created = [];
            foreach ($hosts as $host)
            {
                $uuid = $this->client->pushEntity($host, 'sort_desc_user_' . uniqid());
                $this->createdEntities[] = $uuid;
                $created[] = $uuid;
            }

            $allEntities = [];
            $page = 1;
            do
            {
                $entities = $this->client->listEntities($page, 100, null, 'host', 'DESC');
                $allEntities = array_merge($allEntities, $entities);
                $page++;
            } while (count($entities) > 0);

            $filtered = array_filter($allEntities, fn($e) => in_array($e->getUuid(), $created, true));
            $filtered = array_values($filtered);

            $this->assertCount(3, $filtered);
            $this->assertEquals('c-sort-' . $suffix . '.com', $filtered[0]->getHost());
            $this->assertEquals('b-sort-' . $suffix . '.com', $filtered[1]->getHost());
            $this->assertEquals('a-sort-' . $suffix . '.com', $filtered[2]->getHost());
        }

        public function testSortOrderCaseInsensitive(): void
        {
            $host = 'case-insensitive-sort.com';
            $uuid = $this->client->pushEntity($host, 'case_test_' . uniqid());
            $this->createdEntities[] = $uuid;

            $resultLower = $this->client->listEntities(1, 10, null, 'created', 'asc');
            $resultUpper = $this->client->listEntities(1, 10, null, 'created', 'ASC');
            $resultMixed = $this->client->listEntities(1, 10, null, 'created', 'Asc');

            $uuidsLower = array_map(fn($e) => $e->getUuid(), $resultLower);
            $uuidsUpper = array_map(fn($e) => $e->getUuid(), $resultUpper);
            $uuidsMixed = array_map(fn($e) => $e->getUuid(), $resultMixed);

            $this->assertSame($uuidsLower, $uuidsUpper);
            $this->assertSame($uuidsLower, $uuidsMixed);
        }

        public function testSortByInvalidFieldFallsBackToDefault(): void
        {
            $host = 'invalid-field-sort.com';
            $uuid = $this->client->pushEntity($host, 'invalid_field_' . uniqid());
            $this->createdEntities[] = $uuid;

            $resultDefault = $this->client->listEntities(1, 10);
            $resultInvalid = $this->client->listEntities(1, 10, 'nonexistent_column_xyz');

            $defaultUuids = array_map(fn($e) => $e->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($e) => $e->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testSortInvalidOrderFallsBackToDefault(): void
        {
            $host = 'invalid-order-sort.com';
            $uuid = $this->client->pushEntity($host, 'invalid_order_' . uniqid());
            $this->createdEntities[] = $uuid;

            $resultDefault = $this->client->listEntities(1, 10);
            $resultInvalid = $this->client->listEntities(1, 10, null, 'created', 'INVALID_ORDER');

            $defaultUuids = array_map(fn($e) => $e->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($e) => $e->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testSortSqlInjectionSafe(): void
        {
            $host = 'sql-injection-sort.com';
            $uuid = $this->client->pushEntity($host, 'sql_injection_' . uniqid());
            $this->createdEntities[] = $uuid;

            $payloads = [
                "' OR '1'='1",
                "created; DROP TABLE entities",
                "1 OR 1=1",
                "uuid UNION SELECT * FROM operators",
            ];

            foreach ($payloads as $payload)
            {
                $result = $this->client->listEntities(1, 10, null, $payload, 'ASC');
                $this->assertIsArray($result, "Malicious by value '$payload' should not cause errors");
            }
        }

        public function testSortByReputationDescending(): void
        {
            $hostBase = 'reputation-sort-test';
            $uuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->pushEntity("$hostBase-$i.com", 'rep_user_' . uniqid());
                $this->createdEntities[] = $uuid;
                $uuids[] = $uuid;
            }

            $entities = $this->client->listEntities(1, 100, null, 'reputation', 'DESC');
            $filtered = array_filter($entities, fn($e) => in_array($e->getUuid(), $uuids, true));

            $this->assertCount(3, $filtered);
        }

        public function testEntitiesCategoryWithPagination(): void
        {
            $uuids = [];
            for ($i = 0; $i < 6; $i++)
            {
                $uuid = $this->client->pushEntity("cat-pag-$i.com", 'cat_pag_' . uniqid());
                $this->createdEntities[] = $uuid;
                $uuids[] = $uuid;
            }

            $allFound = [];
            $page = 1;
            $pageSize = 2;
            do
            {
                $entities = $this->client->listEntities($page, $pageSize, 'NOT_WHITELISTED');
                $this->assertIsArray($entities);
                $this->assertLessThanOrEqual($pageSize, count($entities));
                foreach ($entities as $e)
                {
                    $allFound[] = $e->getUuid();
                }
                $page++;
            } while (count($entities) === $pageSize && $page <= 10);

            foreach ($uuids as $uuid)
            {
                $this->assertContains($uuid, $allFound);
            }
        }

        public function testEvidenceCategoryWithPagination(): void
        {
            $entityUuid = $this->client->pushEntity('cat-ev-pag.com', 'cat_ev_pag_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $uuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $uuid = $this->client->submitEvidence($entityUuid, "Cat ev pag $i", "Note $i", 'cat_pag');
                $this->createdEvidenceRecords[] = $uuid;
                $uuids[] = $uuid;
            }

            $allFound = [];
            $page = 1;
            $pageSize = 2;
            do
            {
                $evidence = $this->client->listEvidence($page, $pageSize, true, 'NOT_CONFIDENTIAL');
                $this->assertIsArray($evidence);
                foreach ($evidence as $e)
                {
                    $allFound[] = $e->getUuid();
                }
                $page++;
            } while (count($evidence) === $pageSize && $page <= 10);

            foreach ($uuids as $uuid)
            {
                $this->assertContains($uuid, $allFound);
            }
        }

        public function testCategorySqlInjectionSafe(): void
        {
            $host = 'cat-sqli.com';
            $uuid = $this->client->pushEntity($host, 'cat_sqli_' . uniqid());
            $this->createdEntities[] = $uuid;

            $payloads = [
                "' OR '1'='1",
                "'; DROP TABLE entities; --",
                "1 OR 1=1",
                "NOT_WHITELISTED; DELETE FROM entities",
            ];

            foreach ($payloads as $payload)
            {
                $result = $this->client->listEntities(1, 10, $payload);
                $this->assertIsArray($result, "Malicious category '$payload' should not cause errors");
            }
        }

        public function testCategoryCombinedWithSortAndPagination(): void
        {
            $uuids = [];
            for ($i = 0; $i < 4; $i++)
            {
                $uuid = $this->client->pushEntity("cat-combo-$i.com", 'cat_combo_' . uniqid());
                $this->createdEntities[] = $uuid;
                $uuids[] = $uuid;
            }

            $page1 = $this->client->listEntities(1, 2, 'NOT_WHITELISTED', 'created', 'ASC');
            $page2 = $this->client->listEntities(2, 2, 'NOT_WHITELISTED', 'created', 'ASC');

            $page1Uuids = array_map(fn($e) => $e->getUuid(), $page1);
            $page2Uuids = array_map(fn($e) => $e->getUuid(), $page2);

            $this->assertCount(2, $page1);
            $this->assertCount(2, $page2);
            $this->assertEmpty(array_intersect($page1Uuids, $page2Uuids), 'Pages should not overlap');
        }

    }
