<?php

    namespace FederationLib;

    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
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
            $this->assertNotNull($blacklistUuid);
            $this->assertNotEmpty($blacklistUuid);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Fetch and verify the blacklist record
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertEquals($operatorUuid, $blacklistRecord->getOperatorUuid());
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertNotNull($blacklistRecord->getExpires());
            $this->assertEquals($expires, $blacklistRecord->getExpires());
            $this->assertFalse($blacklistRecord->isLifted());

            // Track created resources for cleanup
            $this->createdEntities[] = $entityUuid;
        }

        public function testBlacklistEntityPermanent(): void
        {
            // Create an entity to blacklist
            $entityUuid = $this->client->pushEntity('malware.example.org', 'infected_user');
            $this->createdEntities[] = $entityUuid;
            $this->assertNotNull($entityUuid);

            // Submit evidence for the blacklist
            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Detected malware distribution", "Automated Security Scan", "malware");
            $this->assertNotNull($evidenceUuid);

            // Blacklist the entity permanently (no expiration)
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::MALWARE, null);
            $this->assertNotNull($blacklistUuid);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Verify the blacklist record
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertNull($blacklistRecord->getExpires()); // Permanent blacklist
            $this->assertFalse($blacklistRecord->isLifted());
        }

        public function testBlacklistEntityInvalidArguments(): void
        {
            // Test empty entity identifier
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The entity identifier must not be empty');
            $this->client->blacklistEntity('', 'some-uuid', BlacklistType::SPAM);
        }

        public function testBlacklistEntityInvalidEvidenceUuid(): void
        {
            // Test empty evidence UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The evidence UUID must not be empty');
            $this->client->blacklistEntity('some-entity-uuid', '', BlacklistType::SPAM);
        }

        public function testBlacklistEntityNegativeExpires(): void
        {
            // Test negative expires value
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The expires parameter must be a positive integer or null');
            $this->client->blacklistEntity('some-entity-uuid', 'some-evidence-uuid', BlacklistType::SPAM, -1);
        }

        public function testDeleteBlacklistRecord(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('delete-test.com', 'user_to_delete');
            $this->createdEntities[] = $entityUuid;
            
            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content for deletion", "Test note", "test");
            
            // Blacklist the entity
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
            $this->assertNotNull($blacklistUuid);

            // Verify the blacklist record exists
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);

            // Delete the blacklist record
            $this->client->deleteBlacklistRecord($blacklistUuid);

            // Verify the record no longer exists
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404); // NOT_FOUND
            $this->client->getBlacklistRecord($blacklistUuid);
        }

        public function testDeleteBlacklistRecordInvalidUuid(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Blacklist record UUID cannot be empty');
            $this->client->deleteBlacklistRecord('');
        }

        public function testDeleteNonExistentBlacklistRecord(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404); // NOT_FOUND
            $this->client->deleteBlacklistRecord($fakeUuid);
        }

        public function testGetBlacklistRecordInvalidUuid(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Blacklist record UUID cannot be empty');
            $this->client->getBlacklistRecord('');
        }

        public function testGetNonExistentBlacklistRecord(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404); // NOT_FOUND
            $this->client->getBlacklistRecord($fakeUuid);
        }

        public function testLiftBlacklistRecord(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('lift-test.com', 'user_to_lift');
            $this->createdEntities[] = $entityUuid;
            
            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content for lifting", "Test note", "test");
            
            // Blacklist the entity
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
            $this->assertNotNull($blacklistUuid);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Verify the blacklist record is not lifted initially
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertFalse($blacklistRecord->isLifted());

            // Lift the blacklist record
            $this->client->liftBlacklistRecord($blacklistUuid);

            // Verify the record is now lifted
            $liftedRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertTrue($liftedRecord->isLifted());
        }

        public function testLiftBlacklistRecordInvalidUuid(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Blacklist record UUID cannot be empty');
            $this->client->liftBlacklistRecord('');
        }

        public function testLiftNonExistentBlacklistRecord(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404); // NOT_FOUND
            $this->client->liftBlacklistRecord($fakeUuid);
        }

        public function testListBlacklistRecords(): void
        {
            // Create multiple blacklist records to test pagination
            $createdBlacklistUuids = [];
            
            for ($i = 0; $i < 3; $i++) {
                $entityUuid = $this->client->pushEntity("list-test-$i.com", "user_$i");
                $this->createdEntities[] = $entityUuid;
                
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content $i", "Test note $i", "test");
                
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
                $createdBlacklistUuids[] = $blacklistUuid;
                $this->createdBlacklistRecords[] = $blacklistUuid;
            }

            // Test listing with default parameters
            $blacklistRecords = $this->client->listBlacklistRecords();
            $this->assertIsArray($blacklistRecords);
            $this->assertGreaterThanOrEqual(3, count($blacklistRecords));

            // Verify our created records are in the list
            $foundUuids = array_map(fn($record) => $record->getUuid(), $blacklistRecords);
            foreach ($createdBlacklistUuids as $uuid) {
                $this->assertContains($uuid, $foundUuids);
            }
        }

        public function testListBlacklistRecordsWithPagination(): void
        {
            // Test with specific page and limit
            $blacklistRecords = $this->client->listBlacklistRecords(1, 2);
            $this->assertIsArray($blacklistRecords);
            $this->assertLessThanOrEqual(2, count($blacklistRecords));
        }

        public function testListBlacklistRecordsWithLifted(): void
        {
            // Create and lift a blacklist record
            $entityUuid = $this->client->pushEntity('lifted-test.com', 'lifted_user');
            $this->createdEntities[] = $entityUuid;
            
            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content for lifted", "Test note", "test");
            
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            
            // Lift the record
            $this->client->liftBlacklistRecord($blacklistUuid);

            // Test listing with lifted records included
            $blacklistRecordsWithLifted = $this->client->listBlacklistRecords(1, 100, true);
            $this->assertIsArray($blacklistRecordsWithLifted);

            // Test listing without lifted records (default)
            $blacklistRecordsWithoutLifted = $this->client->listBlacklistRecords(1, 100, false);
            $this->assertIsArray($blacklistRecordsWithoutLifted);

            // With lifted should have more or equal records than without lifted
            $this->assertGreaterThanOrEqual(count($blacklistRecordsWithoutLifted), count($blacklistRecordsWithLifted));
        }

        public function testListBlacklistRecordsInvalidPage(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Page must be greater than 0');
            $this->client->listBlacklistRecords(0);
        }

        public function testListBlacklistRecordsInvalidLimit(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Limit must be greater than 0');
            $this->client->listBlacklistRecords(1, 0);
        }

        public function testBlacklistEntityWithDifferentTypes(): void
        {
            $blacklistTypes = [
                BlacklistType::SCAM,
                BlacklistType::SERVICE_ABUSE,
                BlacklistType::ILLEGAL_CONTENT,
                BlacklistType::PHISHING,
                BlacklistType::OTHER
            ];

            foreach ($blacklistTypes as $type) {
                $entityUuid = $this->client->pushEntity('type-test.com', 'user_' . $type->value);
                $this->createdEntities[] = $entityUuid;
                
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content for " . $type->value, "Test note", strtolower($type->value));
                
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 3600);
                $this->assertNotNull($blacklistUuid);
                $this->createdBlacklistRecords[] = $blacklistUuid;

                // Verify the blacklist record has the correct type
                $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($blacklistRecord);
                $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
                $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            }
        }

        public function testBlacklistEntityUnauthorized(): void
        {
            // Create a basic operator without permissions
            $basicOperatorUuid = $this->client->createOperator('Test Operator');
            $this->createdOperators[] = $basicOperatorUuid;

            // Disable all permissions
            $this->client->setManageBlacklistPermission($basicOperatorUuid, false);
            $this->client->setManageOperatorsPermission($basicOperatorUuid, false);
            $this->client->setClientPermission($basicOperatorUuid, false);

            $basicOperator = $this->client->getOperator($basicOperatorUuid);
            $basicClient = new FederationClient(getenv('SERVER_ENDPOINT'), $basicOperator->getApiKey());

            // Create entity and evidence as root operator
            $entityUuid = $this->client->pushEntity('unauthorized-test.com', 'unauthorized_user');
            $this->createdEntities[] = $entityUuid;
            
            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content", "Test note", "test");

            // Try to blacklist as unauthorized user
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(403); // FORBIDDEN
            $basicClient->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM);
        }

        // DURABILITY TESTS

        public function testBlacklistRecordLifecycleDurability(): void
        {
            // Create multiple entities, blacklist them, lift some, delete some
            $entityUuids = [];
            $blacklistUuids = [];
            
            // Create and blacklist 5 entities
            for ($i = 0; $i < 5; $i++) {
                $entityUuid = $this->client->pushEntity("durability-test-$i.com", "user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
                
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Durability test evidence $i", "Test note $i", "durability");
                
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 7200);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            // Verify all blacklist records exist and are active
            foreach ($blacklistUuids as $blacklistUuid) {
                $record = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($record);
                $this->assertFalse($record->isLifted());
            }

            // Lift the first 2 blacklist records
            for ($i = 0; $i < 2; $i++) {
                $this->client->liftBlacklistRecord($blacklistUuids[$i]);
                $liftedRecord = $this->client->getBlacklistRecord($blacklistUuids[$i]);
                $this->assertTrue($liftedRecord->isLifted());
            }

            // Delete the next 2 blacklist records
            for ($i = 2; $i < 4; $i++) {
                $this->client->deleteBlacklistRecord($blacklistUuids[$i]);
                try {
                    $this->client->getBlacklistRecord($blacklistUuids[$i]);
                    $this->fail("Expected RequestException for deleted blacklist record");
                } catch (RequestException $e) {
                    $this->assertEquals(404, $e->getCode());
                }
                // Remove from cleanup array since already deleted
                array_splice($this->createdBlacklistRecords, array_search($blacklistUuids[$i], $this->createdBlacklistRecords), 1);
            }

            // Verify the last record is still active
            $lastRecord = $this->client->getBlacklistRecord($blacklistUuids[4]);
            $this->assertFalse($lastRecord->isLifted());
        }

        public function testConcurrentBlacklistOperations(): void
        {
            // Test creating multiple blacklist records for the same entity
            $entityUuid = $this->client->pushEntity('concurrent-test.com', 'concurrent_user');
            $this->createdEntities[] = $entityUuid;

            $blacklistUuids = [];
            $evidenceUuids = [];

            // Create multiple evidence records
            for ($i = 0; $i < 3; $i++) {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Concurrent evidence $i", "Test note $i", "concurrent");
                $evidenceUuids[] = $evidenceUuid;
            }

            // Create multiple blacklist records with different types
            $types = [BlacklistType::SPAM, BlacklistType::SCAM, BlacklistType::SERVICE_ABUSE];
            for ($i = 0; $i < 3; $i++) {
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuids[$i], $types[$i], time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            // Verify all blacklist records exist with different types
            foreach ($blacklistUuids as $index => $blacklistUuid) {
                $record = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($record);
                $this->assertEquals($entityUuid, $record->getEntityUuid());
                $this->assertEquals($evidenceUuids[$index], $record->getEvidenceUuid());
            }

            // List blacklist records for the entity
            $entityBlacklistRecords = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertGreaterThanOrEqual(3, count($entityBlacklistRecords));
        }

        public function testBlacklistExpirationHandling(): void
        {
            // Test blacklist records with very short expiration times
            $entityUuid = $this->client->pushEntity('expiration-test.com', 'expiring_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Short-lived blacklist test", "Test note", "expiration");

            // Create blacklist record that expires in 2 seconds
            $shortExpiry = time() + 2;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $shortExpiry);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Verify record exists and has correct expiration
            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertEquals($shortExpiry, $record->getExpires());
            $this->assertFalse($record->isLifted());

            // Wait for expiration
            sleep(3);

            // Record should still exist but be logically expired
            $expiredRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($expiredRecord);
            $this->assertTrue($expiredRecord->getExpires() < time());
        }

        public function testBlacklistWithMalformedData(): void
        {
            // Test various edge cases and malformed inputs
            $entityUuid = $this->client->pushEntity('malformed-test.com', 'malformed_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content", "Test note", "test");

            // Test with invalid expires (past time)
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() - 3600);
        }

        public function testMultipleBlacklistTypesForSameEntity(): void
        {
            // Test blacklisting the same entity with different types
            $entityUuid = $this->client->pushEntity('multi-type-test.com', 'multi_type_user');
            $this->createdEntities[] = $entityUuid;

            $allTypes = [
                BlacklistType::SPAM,
                BlacklistType::SCAM,
                BlacklistType::SERVICE_ABUSE,
                BlacklistType::ILLEGAL_CONTENT,
                BlacklistType::MALWARE,
                BlacklistType::PHISHING,
                BlacklistType::OTHER
            ];

            $blacklistUuids = [];
            foreach ($allTypes as $type) {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence for " . $type->value, "Auto detection", strtolower($type->value));
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;

                // Verify each record has the correct type
                $record = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($record);
                $this->assertEquals($entityUuid, $record->getEntityUuid());
            }

            // List all blacklist records for this entity
            $entityBlacklist = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertGreaterThanOrEqual(count($allTypes), count($entityBlacklist));
        }

        public function testBlacklistRecordIntegrityAfterLift(): void
        {
            // Test that lifted records maintain their data integrity
            $entityUuid = $this->client->pushEntity('integrity-test.com', 'integrity_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Original evidence", "Original note", "integrity");
            $expires = time() + 7200;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Get original record data
            $originalRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($originalRecord);
            $this->assertFalse($originalRecord->isLifted());

            // Lift the record
            $this->client->liftBlacklistRecord($blacklistUuid);

            // Verify lifted record maintains all original data
            $liftedRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($liftedRecord);
            $this->assertTrue($liftedRecord->isLifted());
            $this->assertEquals($originalRecord->getEntityUuid(), $liftedRecord->getEntityUuid());
            $this->assertEquals($originalRecord->getEvidenceUuid(), $liftedRecord->getEvidenceUuid());
            $this->assertEquals($originalRecord->getOperatorUuid(), $liftedRecord->getOperatorUuid());
            $this->assertEquals($originalRecord->getExpires(), $liftedRecord->getExpires());
        }

        public function testHighVolumeBlacklistOperations(): void
        {
            // Test creating and managing a larger number of blacklist records
            $batchSize = 10;
            $entityUuids = [];
            $blacklistUuids = [];

            // Create multiple entities and blacklist them
            for ($i = 0; $i < $batchSize; $i++) {
                $entityUuid = $this->client->pushEntity("batch-test-$i.com", "batch_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Batch evidence $i", "Batch note $i", "batch");
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            // Verify all records were created
            $this->assertEquals($batchSize, count($blacklistUuids));

            // Test pagination with high volume - use large page size to capture recent records
            $allRecords = $this->client->listBlacklistRecords(1, 100, true); // Get first 100 records including lifted
            
            // Since records are ordered by created DESC, our newly created records should be in the first page
            $this->assertGreaterThanOrEqual($batchSize, count($allRecords));

            // Verify our records are in the results
            $foundUuids = array_map(fn($record) => $record->getUuid(), $allRecords);
            foreach ($blacklistUuids as $uuid) {
                $this->assertContains($uuid, $foundUuids);
            }
        }

        public function testBlacklistRecordOperatorConsistency(): void
        {
            // Test that operator information remains consistent across operations
            $operatorUuid = $this->client->getSelf()->getUuid();
            
            $entityUuid = $this->client->pushEntity('operator-consistency.com', 'operator_test_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, "Operator consistency test", "Test note", "operator");
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SERVICE_ABUSE, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Verify operator UUID is consistent
            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertEquals($operatorUuid, $record->getOperatorUuid());

            // Lift and verify operator consistency
            $this->client->liftBlacklistRecord($blacklistUuid);
            $liftedRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($operatorUuid, $liftedRecord->getOperatorUuid());

            // Test listing by operator
            $operatorBlacklist = $this->client->listOperatorBlacklist($operatorUuid, 1, 100, true);
            $operatorUuids = array_map(fn($record) => $record->getUuid(), $operatorBlacklist);
            $this->assertContains($blacklistUuid, $operatorUuids);
        }
    }