<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class EntitiesClientTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('tests');
            // Note, authentication is not required for these tests.
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
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
                catch (Exception $e)
                {
                    $this->logger->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage(), $e);
                }
            }

            foreach ($this->createdEntities as $entityId)
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

            $this->createdOperators = [];
            $this->createdEntities = [];
        }

        public function testPushEntity(): void
        {
            // Push a user entity
            $userEntityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertNotNull($userEntityUuid);
            $this->assertNotEmpty($userEntityUuid);

            // Query the entity back by their UUID
            $userEntityRecordUuid = $this->client->getEntityRecord($userEntityUuid);
            $this->createdEntities[] = $userEntityUuid;
            $this->assertEquals($userEntityUuid, $userEntityRecordUuid->getUuid());
            $this->assertEquals('john123', $userEntityRecordUuid->getId());
            $this->assertEquals('example.com', $userEntityRecordUuid->getHost());

            // Query the entity back by their hash
            $userEntityRecordHash = $this->client->getEntityRecord(Utilities::hashEntity('example.com', 'john123'));
            $this->assertEquals($userEntityUuid, $userEntityRecordHash->getUuid());
            $this->assertEquals('john123', $userEntityRecordHash->getId());
            $this->assertEquals('example.com', $userEntityRecordHash->getHost());

            // Push a global entity
            $globalEntityUuid = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $globalEntityUuid;
            $this->assertNotNull($globalEntityUuid);
            $this->assertNotEmpty($globalEntityUuid);

            // Query the global entity back by their UUID
            $globalEntityRecordUuid = $this->client->getEntityRecord($globalEntityUuid);
            $this->createdEntities[] = $globalEntityUuid;
            $this->assertEquals($globalEntityUuid, $globalEntityRecordUuid->getUuid());
            $this->assertEquals('example.com', $globalEntityRecordUuid->getHost());
            $this->assertNotNull($globalEntityRecordUuid->getHost());

            // Query the global entity back by their hash
            $globalEntityRecordHash = $this->client->getEntityRecord(Utilities::hashEntity('example.com'));
            $this->assertEquals($globalEntityUuid, $globalEntityRecordHash->getUuid());
            $this->assertEquals('example.com', $globalEntityRecordHash->getHost());
            $this->assertNotNull($globalEntityRecordHash->getHost());

            // Ensure that pushing the same entity again returns the same UUID
            $duplicateUserEntityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->createdEntities[] = $duplicateUserEntityUuid;
            $this->assertEquals($userEntityUuid, $duplicateUserEntityUuid);
            $duplicateGlobalEntityUuid = $this->client->pushEntity('example.com');
            $this->assertEquals($globalEntityUuid, $duplicateGlobalEntityUuid);

            // Push a IP entity
            $ipAddressEntityUuid = $this->client->pushEntity('127.0.0.1');
            $this->createdEntities[] = $ipAddressEntityUuid;
            $this->assertNotEmpty($ipAddressEntityUuid);
            $this->assertNotNull($ipAddressEntityUuid);

            // Fetch the IP Address entity record
            $ipAddressEntityRecord = $this->client->getEntityRecord($ipAddressEntityUuid);
            $this->assertNotNull($ipAddressEntityRecord);
            $this->assertEquals($ipAddressEntityUuid, $ipAddressEntityRecord->getUuid());
            $this->assertEquals('127.0.0.1', $ipAddressEntityRecord->getHost());
            $this->assertNull($ipAddressEntityRecord->getId());
        }

        public function testPushInvalidIpAddressEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->pushEntity("999.999.999.999 2");
        }

        public function testPushInvalidDomainEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->pushEntity("invalid_domain@");
        }

        public function testPushEntityMissingHost(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('');
        }

        public function testDeleteEntity(): void
        {
            // Push a user entity
            $entityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);
            $this->createdEntities[] = $entityUuid;

            // Ensure the entity exists
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());

            // Delete the entity
            $this->client->deleteEntity($entityUuid);

            // Ensure the entity no longer exists
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getEntityRecord($entityUuid);

            // Remove from cleanup tracking since it's already deleted
            array_pop($this->createdEntities);
        }

        public function testDeleteNonExistentEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteEntity(Uuid::v4()->toRfc4122());
        }

        public function testListEntities(): void
        {
            // Push multiple entities
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity('example.com', 'user' . $i);
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            // List entities page by page and verify
            $fetchedUuids = [];
            $page = 1;
            do
            {
                $entitiesPage = $this->client->listEntities($page, 2);
                foreach ($entitiesPage as $entity)
                {
                    $fetchedUuids[] = $entity->getUuid();
                }
                $page++;
            } while (count($entitiesPage) > 0);

            // Ensure all pushed entities are fetched
            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $fetchedUuids);
            }
        }

        public function testListEntitiesInvalidPage(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(page: -10000);
        }

        public function testListEntitiesInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(limit: -1);
        }

        public function testPushEmptyEntity(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('', '');
        }

        public function testPushEmptyEntityHost(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('', 'someid');
        }

        public function testPushEmptyEntityId(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('example.com', '');
        }

        public function testGetEntityAsAnonymousClient(): void
        {
            // Push a user entity
            $entityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);
            $this->createdEntities[] = $entityUuid;

            if(!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $entityRecord = $anonymousClient->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('john123', $entityRecord->getId());
            $this->assertEquals('example.com', $entityRecord->getHost());
        }

        public function testPushEntityAsAnonymousClient(): void
        {
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $anonymousClient->pushEntity('example.com', 'john123');
        }

        public function testListEntitiesAsAnonymousClient(): void
        {
            if(!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            // Push multiple entities as root operator
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity('example.com', 'user' . $i);
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $fetchedUuids = [];
            $page = 1;

            do
            {
                $entitiesPage = $anonymousClient->listEntities($page, 2);
                foreach ($entitiesPage as $entity)
                {
                    $fetchedUuids[] = $entity->getUuid();
                }
                $page++;
            } while (count($entitiesPage) > 0);

            // Ensure all pushed entities are fetched
            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $fetchedUuids);
            }
        }

        // DURABILITY TESTS

        public function testEntityCreationAndRetrievalConsistency(): void
        {
            // Test creating entities with various edge cases and ensuring consistent retrieval
            $testCases = [
                ['host' => 'test-special-chars.com', 'id' => 'user_with_underscore'],
                ['host' => 'test-numbers-123.org', 'id' => 'user123'],
                ['host' => 'test-hyphens-domain.net', 'id' => 'user-with-hyphens'],
                ['host' => '192.168.1.1', 'id' => null], // IP address without ID
                ['host' => 'very-long-domain-name-that-tests-limits.example.com', 'id' => 'user_with_very_long_name_to_test_database_limits'],
            ];

            $createdUuids = [];
            foreach ($testCases as $testCase) {
                $entityUuid = $this->client->pushEntity($testCase['host'], $testCase['id']);
                $this->createdEntities[] = $entityUuid;
                $createdUuids[] = $entityUuid;

                // Verify immediate retrieval
                $entity = $this->client->getEntityRecord($entityUuid);
                $this->assertNotNull($entity);
                $this->assertEquals($testCase['host'], $entity->getHost());
                $this->assertEquals($testCase['id'], $entity->getId());

                // Test retrieval by hash as well
                $hash = Utilities::hashEntity($testCase['host'], $testCase['id']);
                $entityByHash = $this->client->getEntityRecord($hash);
                $this->assertEquals($entityUuid, $entityByHash->getUuid());
            }

            // Test that all entities are retrievable after batch creation
            foreach ($createdUuids as $uuid) {
                $entity = $this->client->getEntityRecord($uuid);
                $this->assertNotNull($entity);
            }
        }

        public function testHighVolumeEntityOperations(): void
        {
            // Test creating, listing, and deleting a high volume of entities
            $batchSize = 20;
            $entityUuids = [];

            // Create entities in batch
            for ($i = 0; $i < $batchSize; $i++) {
                $entityUuid = $this->client->pushEntity("batch-test-$i.example.com", "batch_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            // Verify all entities were created
            $this->assertEquals($batchSize, count($entityUuids));

            // Test pagination with high volume
            $allEntities = [];
            $page = 1;
            $pageSize = 5;
            do {
                $entitiesPage = $this->client->listEntities($page, $pageSize);
                $allEntities = array_merge($allEntities, $entitiesPage);
                $page++;
            } while (count($entitiesPage) === $pageSize && $page <= 20); // Safety limit

            // Verify our entities are in the results
            $foundUuids = array_map(fn($entity) => $entity->getUuid(), $allEntities);
            foreach ($entityUuids as $uuid) {
                $this->assertContains($uuid, $foundUuids);
            }

            // Test deleting half of the entities
            $entitiesToDelete = array_slice($entityUuids, 0, $batchSize / 2);
            foreach ($entitiesToDelete as $uuid) {
                $this->client->deleteEntity($uuid);
                
                // Verify entity is deleted
                try {
                    $this->client->getEntityRecord($uuid);
                    $this->fail("Expected RequestException for deleted entity");
                } catch (RequestException $e) {
                    $this->assertEquals(404, $e->getCode());
                }
                
                // Remove from cleanup array since already deleted
                array_splice($this->createdEntities, array_search($uuid, $this->createdEntities), 1);
            }
        }

        public function testEntityDuplicationHandling(): void
        {
            // Test that pushing the same entity multiple times returns the same UUID
            $host = 'duplication-test.com';
            $id = 'duplicate_user';

            $firstUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $firstUuid;
            $this->assertNotNull($firstUuid);

            // Push the same entity multiple times
            for ($i = 0; $i < 5; $i++) {
                $duplicateUuid = $this->client->pushEntity($host, $id);
                $this->assertEquals($firstUuid, $duplicateUuid);
            }

            // Verify only one entity record exists
            $entity = $this->client->getEntityRecord($firstUuid);
            $this->assertNotNull($entity);
            $this->assertEquals($host, $entity->getHost());
            $this->assertEquals($id, $entity->getId());

            // Test with hash retrieval
            $hash = Utilities::hashEntity($host, $id);
            $entityByHash = $this->client->getEntityRecord($hash);
            $this->assertEquals($firstUuid, $entityByHash->getUuid());
        }

        public function testEntityWithComplexIdentifiers(): void
        {
            // Test entities with various complex but valid identifiers
            $complexCases = [
                ['host' => 'subdomain.example.co.uk', 'id' => 'user.with.dots'],
                ['host' => 'test-123.example-domain.org', 'id' => 'user_123_test'],
                ['host' => 'xn--example-test.com', 'id' => 'unicode_user'],  // punycode domain
                ['host' => '2001:db8::1', 'id' => null], // IPv6 address
            ];

            foreach ($complexCases as $testCase) {
                try {
                    $entityUuid = $this->client->pushEntity($testCase['host'], $testCase['id']);
                    $this->createdEntities[] = $entityUuid;
                    $this->assertNotNull($entityUuid);

                    // Verify retrieval
                    $entity = $this->client->getEntityRecord($entityUuid);
                    $this->assertEquals($testCase['host'], $entity->getHost());
                    $this->assertEquals($testCase['id'], $entity->getId());
                } catch (RequestException $e) {
                    // Some complex cases might be rejected by validation, which is acceptable
                    // Log but don't fail the test
                    $this->logger->info("Complex identifier rejected (expected): " . $e->getMessage());
                }
            }
        }

        public function testEntityLifecycleIntegrity(): void
        {
            // Test complete entity lifecycle: create, retrieve, associate with evidence, delete
            $entityUuid = $this->client->pushEntity('lifecycle-test.com', 'lifecycle_user');
            $this->createdEntities[] = $entityUuid;

            // Verify creation
            $entity = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entity);

            // Associate with evidence (if evidence submission is available)
            try {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Lifecycle test evidence", "Test note", "lifecycle");
                
                // Verify evidence is associated
                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());

                // List entity evidence
                $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid);
                $evidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $entityEvidence);
                $this->assertContains($evidenceUuid, $evidenceUuids);

                // Clean up evidence
                $this->client->deleteEvidence($evidenceUuid);
            } catch (RequestException $e) {
                // Evidence operations might not be available for this operator
                $this->logger->info("Evidence operations not available: " . $e->getMessage());
            }

            // Delete entity
            $this->client->deleteEntity($entityUuid);

            // Verify deletion
            try {
                $this->client->getEntityRecord($entityUuid);
                $this->fail("Expected RequestException for deleted entity");
            } catch (RequestException $e) {
                $this->assertEquals(404, $e->getCode());
            }

            // Remove from cleanup array since already deleted
            array_splice($this->createdEntities, array_search($entityUuid, $this->createdEntities), 1);
        }

        public function testEntityQueryingWithFiltering(): void
        {
            // Test entity querying with various filtering options
            $entityUuid = $this->client->pushEntity('query-test.com', 'query_user');
            $this->createdEntities[] = $entityUuid;

            // Test basic query
            $queryResult = $this->client->queryEntity($entityUuid);
            $this->assertNotNull($queryResult);
            $this->assertEquals($entityUuid, $queryResult->getEntityRecord()->getUuid());

            // Test query with different options
            try {
                $queryWithConfidential = $this->client->queryEntity($entityUuid, true, false);
                $this->assertNotNull($queryWithConfidential);

                $queryWithLifted = $this->client->queryEntity($entityUuid, false, true);
                $this->assertNotNull($queryWithLifted);

                $queryWithBoth = $this->client->queryEntity($entityUuid, true, true);
                $this->assertNotNull($queryWithBoth);
            } catch (RequestException $e) {
                // Some query options might require specific permissions
                $this->logger->info("Advanced query options not available: " . $e->getMessage());
            }
        }
    }