<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ApiResponseTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('api-response-tests');
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

        // SERVER INFORMATION RESPONSE TESTS

        public function testServerInformationResponseStructure(): void
        {
            $serverInfo = $this->client->getServerInformation();
            
            // Test object type
            $this->assertInstanceOf(\FederationLib\Objects\ServerInformation::class, $serverInfo);
            
            // Test required properties
            $this->assertIsString($serverInfo->getServerName());
            $this->assertIsString($serverInfo->getApiVersion());
            $this->assertIsBool($serverInfo->isPublicEntities());
            $this->assertIsBool($serverInfo->isPublicEvidence());
            
            // Test property constraints
            $this->assertNotEmpty($serverInfo->getServerName());
            $this->assertNotEmpty($serverInfo->getApiVersion());
        }

        // ENTITY RESPONSE TESTS

        public function testEntityResponseStructure(): void
        {
            // Create test entity
            $entityUuid = $this->client->pushEntity('response-test.com', 'response_user');
            $this->createdEntities[] = $entityUuid;
            
            // Test pushEntity response
            $this->assertIsString($entityUuid);
            $this->assertNotEmpty($entityUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $entityUuid);
            
            // Test getEntityRecord response
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertInstanceOf(\FederationLib\Objects\Entity::class, $entityRecord);
            
            // Test required properties
            $this->assertIsString($entityRecord->getUuid());
            $this->assertIsString($entityRecord->getHost());
            $this->assertIsString($entityRecord->getId());
            $this->assertIsInt($entityRecord->getCreated());
            
            // Test property values
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('response-test.com', $entityRecord->getHost());
            $this->assertEquals('response_user', $entityRecord->getId());
            $this->assertGreaterThan(0, $entityRecord->getCreated());
            
            // Timestamp should be reasonable (within last hour)
            $now = time();
            $this->assertLessThanOrEqual($now, $entityRecord->getCreated());
            $this->assertGreaterThan($now - 3600, $entityRecord->getCreated());
        }

        public function testGlobalEntityResponseStructure(): void
        {
            // Create global entity
            $entityUuid = $this->client->pushEntity('global-response-test.com');
            $this->createdEntities[] = $entityUuid;
            
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            
            // Test global entity specific properties
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('global-response-test.com', $entityRecord->getHost());
            $this->assertNull($entityRecord->getId()); // Should be null for global entity
            $this->assertIsInt($entityRecord->getCreated());
        }

        public function testEntityListResponseStructure(): void
        {
            // Create multiple entities
            $entityUuids = [];
            for ($i = 0; $i < 3; $i++) {
                $entityUuid = $this->client->pushEntity("list-test-$i.com", "list_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }
            
            // Test listEntities response
            $entities = $this->client->listEntities(1, 10);
            $this->assertIsArray($entities);
            
            // Find our test entities
            $foundEntities = array_filter($entities, function($entity) use ($entityUuids) {
                return in_array($entity->getUuid(), $entityUuids);
            });
            
            $this->assertGreaterThanOrEqual(3, count($foundEntities));
            
            foreach ($foundEntities as $entity) {
                $this->assertInstanceOf(\FederationLib\Objects\Entity::class, $entity);
                $this->assertIsString($entity->getUuid());
                $this->assertIsString($entity->getHost());
                $this->assertIsInt($entity->getCreated());
            }
        }

        // OPERATOR RESPONSE TESTS

        public function testOperatorResponseStructure(): void
        {
            // Create test operator
            $operatorUuid = $this->client->createOperator('Response Test Operator');
            $this->createdOperators[] = $operatorUuid;
            
            // Test createOperator response
            $this->assertIsString($operatorUuid);
            $this->assertNotEmpty($operatorUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $operatorUuid);
            
            // Test getOperator response
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertInstanceOf(\FederationLib\Objects\Operator::class, $operator);
            
            // Test required properties
            $this->assertIsString($operator->getUuid());
            $this->assertIsString($operator->getName());
            $this->assertIsString($operator->getApiKey());
            $this->assertIsInt($operator->getCreated());
            $this->assertIsBool($operator->canManageBlacklist());
            $this->assertIsBool($operator->canManageOperators());
            $this->assertIsBool($operator->isClient());
            $this->assertIsBool($operator->isDisabled());
            
            // Test property values
            $this->assertEquals($operatorUuid, $operator->getUuid());
            $this->assertEquals('Response Test Operator', $operator->getName());
            $this->assertNotEmpty($operator->getApiKey());
            $this->assertGreaterThan(0, $operator->getCreated());
            $this->assertFalse($operator->isDisabled()); // Should be enabled by default
        }

        public function testSelfOperatorResponseStructure(): void
        {
            $selfOperator = $this->client->getSelf();
            $this->assertInstanceOf(\FederationLib\Objects\Operator::class, $selfOperator);
            
            // Self operator should have all standard properties
            $this->assertIsString($selfOperator->getUuid());
            $this->assertIsString($selfOperator->getName());
            $this->assertIsString($selfOperator->getApiKey());
            $this->assertIsInt($selfOperator->getCreated());
            $this->assertIsBool($selfOperator->canManageBlacklist());
            $this->assertIsBool($selfOperator->canManageOperators());
            $this->assertIsBool($selfOperator->isClient());
            $this->assertIsBool($selfOperator->isDisabled());
            
            // Self operator should typically have elevated permissions
            $this->assertTrue($selfOperator->canManageBlacklist() || $selfOperator->canManageOperators());
        }

        public function testOperatorListResponseStructure(): void
        {
            // Create multiple operators
            for ($i = 0; $i < 3; $i++) {
                $operatorUuid = $this->client->createOperator("List Test Operator $i");
                $this->createdOperators[] = $operatorUuid;
            }
            
            // Test listOperators response
            $operators = $this->client->listOperators(1, 10);
            $this->assertIsArray($operators);
            $this->assertGreaterThanOrEqual(3, count($operators));
            
            foreach ($operators as $operator) {
                $this->assertInstanceOf(\FederationLib\Objects\Operator::class, $operator);
                $this->assertIsString($operator->getUuid());
                $this->assertIsString($operator->getName());
                $this->assertIsString($operator->getApiKey());
                $this->assertIsInt($operator->getCreated());
                $this->assertIsBool($operator->canManageBlacklist());
                $this->assertIsBool($operator->canManageOperators());
                $this->assertIsBool($operator->isClient());
                $this->assertIsBool($operator->isDisabled());
            }
        }

        // EVIDENCE RESPONSE TESTS

        public function testEvidenceResponseStructure(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('evidence-response-test.com', 'evidence_user');
            $this->createdEntities[] = $entityUuid;
            
            $evidenceUuid = $this->client->submitEvidence(
                $entityUuid,
                'Test evidence content',
                'Test evidence note',
                'response_test'
            );
            $this->createdEvidenceRecords[] = $evidenceUuid;
            
            // Test submitEvidence response
            $this->assertIsString($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $evidenceUuid);
            
            // Test getEvidenceRecord response
            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(\FederationLib\Objects\EvidenceRecord::class, $evidence);
            
            // Test required properties
            $this->assertIsString($evidence->getUuid());
            $this->assertIsString($evidence->getEntityUuid());
            $this->assertIsString($evidence->getOperatorUuid());
            $this->assertIsString($evidence->getTextContent());
            $this->assertIsString($evidence->getNote());
            $this->assertIsString($evidence->getTag());
            $this->assertIsInt($evidence->getCreated());
            $this->assertIsBool($evidence->isConfidential());
            
            // Test property values
            $this->assertEquals($evidenceUuid, $evidence->getUuid());
            $this->assertEquals($entityUuid, $evidence->getEntityUuid());
            $this->assertEquals('Test evidence content', $evidence->getTextContent());
            $this->assertEquals('Test evidence note', $evidence->getNote());
            $this->assertEquals('response_test', $evidence->getTag());
            $this->assertFalse($evidence->isConfidential()); // Default should be false
            $this->assertGreaterThan(0, $evidence->getCreated());
        }

        public function testEvidenceListResponseStructure(): void
        {
            // Create entity and multiple evidence records
            $entityUuid = $this->client->pushEntity('evidence-list-test.com', 'evidence_list_user');
            $this->createdEntities[] = $entityUuid;
            
            for ($i = 0; $i < 3; $i++) {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Evidence content $i",
                    "Evidence note $i",
                    "list_test_$i"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }
            
            // Test listEvidence response
            $evidenceList = $this->client->listEvidence(1, 10);
            $this->assertIsArray($evidenceList);
            $this->assertGreaterThanOrEqual(3, count($evidenceList));
            
            foreach ($evidenceList as $evidence) {
                $this->assertInstanceOf(\FederationLib\Objects\EvidenceRecord::class, $evidence);
                $this->assertIsString($evidence->getUuid());
                $this->assertIsString($evidence->getEntityUuid());
                $this->assertIsString($evidence->getOperatorUuid());
                $this->assertIsString($evidence->getTextContent());
                $this->assertIsString($evidence->getNote());
                $this->assertIsString($evidence->getTag());
                $this->assertIsInt($evidence->getCreated());
                $this->assertIsBool($evidence->isConfidential());
            }
        }

        // BLACKLIST RESPONSE TESTS

        public function testBlacklistResponseStructure(): void
        {
            // Create entity, evidence, and blacklist
            $entityUuid = $this->client->pushEntity('blacklist-response-test.com', 'blacklist_user');
            $this->createdEntities[] = $entityUuid;
            
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Blacklist evidence', 'Blacklist note', 'blacklist_test');
            $this->createdEvidenceRecords[] = $evidenceUuid;
            
            $expiration = time() + 3600;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $expiration);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            
            // Test blacklistEntity response
            $this->assertIsString($blacklistUuid);
            $this->assertNotEmpty($blacklistUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $blacklistUuid);
            
            // Test getBlacklistRecord response
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertInstanceOf(\FederationLib\Objects\BlacklistRecord::class, $blacklistRecord);
            
            // Test required properties
            $this->assertIsString($blacklistRecord->getUuid());
            $this->assertIsString($blacklistRecord->getEntityUuid());
            $this->assertIsString($blacklistRecord->getEvidenceUuid());
            $this->assertIsString($blacklistRecord->getOperatorUuid());
            $this->assertInstanceOf(BlacklistType::class, $blacklistRecord->getType());
            $this->assertIsInt($blacklistRecord->getCreated());
            $this->assertIsInt($blacklistRecord->getExpires());
            $this->assertIsBool($blacklistRecord->isLifted());
            
            // Test property values
            $this->assertEquals($blacklistUuid, $blacklistRecord->getUuid());
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertEquals(BlacklistType::SPAM, $blacklistRecord->getType());
            $this->assertEquals($expiration, $blacklistRecord->getExpires());
            $this->assertFalse($blacklistRecord->isLifted()); // Should not be lifted initially
            $this->assertGreaterThan(0, $blacklistRecord->getCreated());
        }

        public function testBlacklistListResponseStructure(): void
        {
            // Create entities, evidence, and blacklist records
            for ($i = 0; $i < 3; $i++) {
                $entityUuid = $this->client->pushEntity("blacklist-list-$i.com", "blacklist_list_user_$i");
                $this->createdEntities[] = $entityUuid;
                
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence $i", "Note $i", "list_test");
                $this->createdEvidenceRecords[] = $evidenceUuid;
                
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
            }
            
            // Test listBlacklistRecords response
            $blacklistRecords = $this->client->listBlacklistRecords(1, 10);
            $this->assertIsArray($blacklistRecords);
            $this->assertGreaterThanOrEqual(3, count($blacklistRecords));
            
            foreach ($blacklistRecords as $blacklistRecord) {
                $this->assertInstanceOf(\FederationLib\Objects\BlacklistRecord::class, $blacklistRecord);
                $this->assertIsString($blacklistRecord->getUuid());
                $this->assertIsString($blacklistRecord->getEntityUuid());
                $this->assertIsString($blacklistRecord->getEvidenceUuid());
                $this->assertIsString($blacklistRecord->getOperatorUuid());
                $this->assertInstanceOf(BlacklistType::class, $blacklistRecord->getType());
                $this->assertIsInt($blacklistRecord->getCreated());
                $this->assertIsInt($blacklistRecord->getExpires());
                $this->assertIsBool($blacklistRecord->isLifted());
            }
        }

        // AUDIT LOG RESPONSE TESTS

        public function testAuditLogResponseStructure(): void
        {
            // Generate audit logs by creating and deleting an operator
            $operatorUuid = $this->client->createOperator('Audit Log Test Operator');
            $this->client->deleteOperator($operatorUuid);
            
            // Test listAuditLogs response
            $auditLogs = $this->client->listAuditLogs(1, 10);
            $this->assertIsArray($auditLogs);
            $this->assertGreaterThan(0, count($auditLogs));
            
            foreach ($auditLogs as $auditLog) {
                $this->assertInstanceOf(\FederationLib\Objects\AuditLog::class, $auditLog);
                
                // Test required properties
                $this->assertIsString($auditLog->getUuid());
                $this->assertNotNull($auditLog->getType());
                $this->assertIsString($auditLog->getMessage());
                $this->assertIsInt($auditLog->getTimestamp());
                
                // OperatorUuid might be null for some audit types
                if ($auditLog->getOperatorUuid() !== null) {
                    $this->assertIsString($auditLog->getOperatorUuid());
                }
                
                $this->assertNotEmpty($auditLog->getUuid());
                $this->assertNotEmpty($auditLog->getMessage());
                $this->assertGreaterThan(0, $auditLog->getTimestamp());
            }
        }

        // ERROR RESPONSE TESTS

        public function testErrorResponseStructure(): void
        {
            try {
                // Attempt an operation that should fail
                $this->client->getEntityRecord('invalid-uuid-format');
                $this->fail('Expected RequestException was not thrown');
            } catch (RequestException $e) {
                // Test error response properties
                $this->assertIsInt($e->getCode());
                $this->assertIsString($e->getMessage());
                $this->assertNotEmpty($e->getMessage());
                $this->assertGreaterThan(0, $e->getCode());
            }
        }

        // TIMESTAMP VALIDATION TESTS

        public function testTimestampConsistency(): void
        {
            $beforeTime = time();
            
            // Create entity
            $entityUuid = $this->client->pushEntity('timestamp-test.com', 'timestamp_user');
            $this->createdEntities[] = $entityUuid;
            
            $afterTime = time();
            
            // Get entity and check timestamp
            $entity = $this->client->getEntityRecord($entityUuid);
            $entityTimestamp = $entity->getCreated();
            
            $this->assertGreaterThanOrEqual($beforeTime, $entityTimestamp);
            $this->assertLessThanOrEqual($afterTime + 1, $entityTimestamp); // Allow 1 second tolerance
        }

        // RESPONSE CONSISTENCY TESTS

        public function testResponseConsistencyAcrossMultipleCalls(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('consistency-test.com', 'consistency_user');
            $this->createdEntities[] = $entityUuid;
            
            // Get entity multiple times
            $entity1 = $this->client->getEntityRecord($entityUuid);
            $entity2 = $this->client->getEntityRecord($entityUuid);
            $entity3 = $this->client->getEntityRecord($entityUuid);
            
            // All responses should be identical
            $this->assertEquals($entity1->getUuid(), $entity2->getUuid());
            $this->assertEquals($entity1->getUuid(), $entity3->getUuid());
            $this->assertEquals($entity1->getHost(), $entity2->getHost());
            $this->assertEquals($entity1->getHost(), $entity3->getHost());
            $this->assertEquals($entity1->getId(), $entity2->getId());
            $this->assertEquals($entity1->getId(), $entity3->getId());
            $this->assertEquals($entity1->getCreated(), $entity2->getCreated());
            $this->assertEquals($entity1->getCreated(), $entity3->getCreated());
        }
    }
