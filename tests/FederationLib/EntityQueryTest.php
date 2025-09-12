<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class EntityQueryTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdEntities = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('entity-query-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdEntities as $entityUuid) {
                try {
                    $this->client->deleteEntity($entityUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting entity $entityUuid: " . $e->getMessage());
                }
            }

            $this->createdEntities = [];
        }

        // ENTITY HASH QUERY TESTS

        public function testQueryEntityByHash(): void
        {
            // Create entity with specific host and ID
            $host = 'query-test.com';
            $id = 'query_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            // Generate hash and query by it
            $hash = Utilities::hashEntity($host, $id);
            $this->assertNotNull($hash);
            $this->assertNotEmpty($hash);

            // Query entity by hash
            $entityRecord = $this->client->getEntityRecord($hash);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals($host, $entityRecord->getHost());
            $this->assertEquals($id, $entityRecord->getId());
        }

        public function testQueryEntityByHashGlobal(): void
        {
            // Create global entity (host only)
            $host = 'global-query-test.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            // Generate hash for global entity and query by it
            $hash = Utilities::hashEntity($host);
            $this->assertNotNull($hash);
            $this->assertNotEmpty($hash);

            // Query entity by hash
            $entityRecord = $this->client->getEntityRecord($hash);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals($host, $entityRecord->getHost());
            $this->assertNull($entityRecord->getId());
        }

        public function testQueryEntityByUuid(): void
        {
            // Create entity
            $host = 'uuid-query-test.com';
            $id = 'uuid_query_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            // Query by UUID
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals($host, $entityRecord->getHost());
            $this->assertEquals($id, $entityRecord->getId());
        }

        public function testQueryNonExistentEntity(): void
        {
            // Try to query an entity that doesn't exist
            $fakeUuid = 'bc1d8716-df05-4551-935a-007192550f17';
            
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getEntityRecord($fakeUuid);
        }

        public function testQueryWithInvalidHash(): void
        {
            // Try to query with an invalid hash format
            $invalidHash = 'invalid-hash-format';
            
            $this->expectException(RequestException::class);
            $this->client->getEntityRecord($invalidHash);
        }

        public function testQueryWithInvalidUuid(): void
        {
            // Try to query with an invalid UUID format
            $invalidUuid = 'invalid-uuid-format';
            
            $this->expectException(RequestException::class);
            $this->client->getEntityRecord($invalidUuid);
        }

        // HASH GENERATION CONSISTENCY TESTS

        public function testHashConsistencyForSameEntity(): void
        {
            $host = 'consistency-test.com';
            $id = 'consistency_user';

            // Generate hash multiple times for the same entity
            $hash1 = Utilities::hashEntity($host, $id);
            $hash2 = Utilities::hashEntity($host, $id);
            $hash3 = Utilities::hashEntity($host, $id);

            $this->assertEquals($hash1, $hash2);
            $this->assertEquals($hash1, $hash3);
            $this->assertEquals($hash2, $hash3);
        }

        public function testHashUniquenessForDifferentEntities(): void
        {
            // Generate hashes for different entities
            $hash1 = Utilities::hashEntity('test1.com', 'user1');
            $hash2 = Utilities::hashEntity('test1.com', 'user2');
            $hash3 = Utilities::hashEntity('test2.com', 'user1');
            $hash4 = Utilities::hashEntity('test1.com'); // Global entity

            $this->assertNotEquals($hash1, $hash2);
            $this->assertNotEquals($hash1, $hash3);
            $this->assertNotEquals($hash1, $hash4);
            $this->assertNotEquals($hash2, $hash3);
            $this->assertNotEquals($hash2, $hash4);
            $this->assertNotEquals($hash3, $hash4);
        }

        public function testHashFormatAndLength(): void
        {
            $host = 'format-test.com';
            $id = 'format_user';

            $hash = Utilities::hashEntity($host, $id);
            $this->assertNotNull($hash);
            $this->assertNotEmpty($hash);
            
            // Hash should be a string of reasonable length (depends on implementation)
            $this->assertIsString($hash);
            $this->assertGreaterThan(10, strlen($hash)); // Minimum reasonable hash length
        }

        // QUERY RESULT VALIDATION TESTS

        public function testQueryResultStructure(): void
        {
            $host = 'structure-test.com';
            $id = 'structure_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            
            // Verify all expected properties are present and have correct types
            $this->assertIsString($entityRecord->getUuid());
            $this->assertIsString($entityRecord->getHost());
            $this->assertIsString($entityRecord->getId());
            $this->assertIsInt($entityRecord->getCreated());
            
            // UUID should be valid format
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $entityRecord->getUuid()
            );
            
            // Timestamp should be reasonable (within last hour and not in future)
            $now = time();
            $this->assertLessThanOrEqual($now, $entityRecord->getCreated());
            $this->assertGreaterThan($now - 3600, $entityRecord->getCreated());
        }

        public function testQueryGlobalEntityStructure(): void
        {
            $host = 'global-structure-test.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            
            // Verify structure for global entity
            $this->assertIsString($entityRecord->getUuid());
            $this->assertIsString($entityRecord->getHost());
            $this->assertNull($entityRecord->getId()); // Should be null for global entity
            $this->assertIsInt($entityRecord->getCreated());
        }

        // COMPLEX QUERY SCENARIOS

        public function testQueryEntitiesWithSpecialCharacters(): void
        {
            $testCases = [
                ['host' => 'special-chars.com', 'id' => 'user_with_underscore'],
                ['host' => 'test-domain.org', 'id' => 'user-with-hyphens'],
                ['host' => 'numbers123.net', 'id' => 'user123'],
                ['host' => 'subdomain.example.co.uk', 'id' => 'user.with.dots'],
            ];

            foreach ($testCases as $testCase) {
                $entityUuid = $this->client->pushEntity($testCase['host'], $testCase['id']);
                $this->createdEntities[] = $entityUuid;

                // Query by UUID
                $entityByUuid = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($testCase['host'], $entityByUuid->getHost());
                $this->assertEquals($testCase['id'], $entityByUuid->getId());

                // Query by hash
                $hash = Utilities::hashEntity($testCase['host'], $testCase['id']);
                $entityByHash = $this->client->getEntityRecord($hash);
                $this->assertEquals($entityUuid, $entityByHash->getUuid());
                $this->assertEquals($testCase['host'], $entityByHash->getHost());
                $this->assertEquals($testCase['id'], $entityByHash->getId());
            }
        }

        public function testQueryIpAddressEntities(): void
        {
            $ipAddresses = [
                '192.168.1.1',
                '10.0.0.1',
                '172.16.0.1',
                '127.0.0.1',
                '8.8.8.8'
            ];

            foreach ($ipAddresses as $ip) {
                $entityUuid = $this->client->pushEntity($ip);
                $this->createdEntities[] = $entityUuid;

                // Query by UUID
                $entityByUuid = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($ip, $entityByUuid->getHost());
                $this->assertNull($entityByUuid->getId());

                // Query by hash
                $hash = Utilities::hashEntity($ip);
                $entityByHash = $this->client->getEntityRecord($hash);
                $this->assertEquals($entityUuid, $entityByHash->getUuid());
                $this->assertEquals($ip, $entityByHash->getHost());
                $this->assertNull($entityByHash->getId());
            }
        }

        // PERFORMANCE AND DURABILITY TESTS

        public function testBulkQueryPerformance(): void
        {
            $batchSize = 10;
            $entities = [];

            // Create entities
            for ($i = 0; $i < $batchSize; $i++) {
                $host = "bulk-query-$i.com";
                $id = "bulk_user_$i";
                $entityUuid = $this->client->pushEntity($host, $id);
                $this->createdEntities[] = $entityUuid;
                
                $entities[] = [
                    'uuid' => $entityUuid,
                    'host' => $host,
                    'id' => $id,
                    'hash' => Utilities::hashEntity($host, $id)
                ];
            }

            // Query all entities by UUID and measure performance
            $startTime = microtime(true);
            foreach ($entities as $entity) {
                $result = $this->client->getEntityRecord($entity['uuid']);
                $this->assertEquals($entity['host'], $result->getHost());
                $this->assertEquals($entity['id'], $result->getId());
            }
            $uuidQueryTime = microtime(true) - $startTime;

            // Query all entities by hash and measure performance
            $startTime = microtime(true);
            foreach ($entities as $entity) {
                $result = $this->client->getEntityRecord($entity['hash']);
                $this->assertEquals($entity['uuid'], $result->getUuid());
                $this->assertEquals($entity['host'], $result->getHost());
                $this->assertEquals($entity['id'], $result->getId());
            }
            $hashQueryTime = microtime(true) - $startTime;

            // Log performance metrics
            $this->logger->info("UUID query time for $batchSize entities: {$uuidQueryTime}s");
            $this->logger->info("Hash query time for $batchSize entities: {$hashQueryTime}s");

            // Both should complete in reasonable time (adjust threshold as needed)
            $this->assertLessThan(30.0, $uuidQueryTime, "UUID queries took too long");
            $this->assertLessThan(30.0, $hashQueryTime, "Hash queries took too long");
        }

        public function testQueryConsistencyAfterMultipleCreations(): void
        {
            $host = 'consistency-multi.com';
            $id = 'consistency_user';

            // Create the same entity multiple times (should return same UUID)
            $uuid1 = $this->client->pushEntity($host, $id);
            $uuid2 = $this->client->pushEntity($host, $id);
            $uuid3 = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $uuid1; // Only need to clean up once

            $this->assertEquals($uuid1, $uuid2);
            $this->assertEquals($uuid1, $uuid3);

            // Query should always return consistent results
            $hash = Utilities::hashEntity($host, $id);
            
            for ($i = 0; $i < 5; $i++) {
                $resultByUuid = $this->client->getEntityRecord($uuid1);
                $resultByHash = $this->client->getEntityRecord($hash);

                $this->assertEquals($uuid1, $resultByUuid->getUuid());
                $this->assertEquals($uuid1, $resultByHash->getUuid());
                $this->assertEquals($host, $resultByUuid->getHost());
                $this->assertEquals($host, $resultByHash->getHost());
                $this->assertEquals($id, $resultByUuid->getId());
                $this->assertEquals($id, $resultByHash->getId());
            }
        }

        // ANONYMOUS ACCESS TESTS

        public function testQueryEntityAsAnonymousClient(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities()) {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            // Create entity as authenticated user
            $host = 'anonymous-query.com';
            $id = 'anonymous_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            // Query as anonymous client
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            
            // Query by UUID
            $entityByUuid = $anonymousClient->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityByUuid->getUuid());
            $this->assertEquals($host, $entityByUuid->getHost());
            $this->assertEquals($id, $entityByUuid->getId());

            // Query by hash
            $hash = Utilities::hashEntity($host, $id);
            $entityByHash = $anonymousClient->getEntityRecord($hash);
            $this->assertEquals($entityUuid, $entityByHash->getUuid());
            $this->assertEquals($host, $entityByHash->getHost());
            $this->assertEquals($id, $entityByHash->getId());
        }
    }
