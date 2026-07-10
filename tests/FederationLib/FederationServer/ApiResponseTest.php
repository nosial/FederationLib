<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ServerInformation;
    use PHPUnit\Framework\TestCase;

    class ApiResponseTest extends TestCase
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

        public function testServerInformationResponseStructure(): void
        {
            $serverInfo = $this->client->getServerInformation();

            $this->assertInstanceOf(ServerInformation::class, $serverInfo);
            $this->assertIsString($serverInfo->getServerName());
            $this->assertIsString($serverInfo->getApiVersion());
            $this->assertIsBool($serverInfo->isPublicEntities());
            $this->assertIsBool($serverInfo->isPublicEvidence());
            $this->assertNotEmpty($serverInfo->getServerName());
            $this->assertNotEmpty($serverInfo->getApiVersion());
        }

        public function testEntityResponseStructure(): void
        {
            $entityUuid = $this->client->pushEntity('response-test.com', 'response_user');
            $this->createdEntities[] = $entityUuid;

            $this->assertIsString($entityUuid);
            $this->assertNotEmpty($entityUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $entityUuid);

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertInstanceOf(EntityRecord::class, $entityRecord);
            $this->assertIsString($entityRecord->getUuid());
            $this->assertIsString($entityRecord->getHost());
            $this->assertIsString($entityRecord->getId());
            $this->assertIsInt($entityRecord->getCreated());
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('response-test.com', $entityRecord->getHost());
            $this->assertEquals('response_user', $entityRecord->getId());
            $this->assertGreaterThan(0, $entityRecord->getCreated());

            $now = time();
            $this->assertLessThanOrEqual($now, $entityRecord->getCreated());
            $this->assertGreaterThan($now - 3600, $entityRecord->getCreated());
        }

        public function testGlobalEntityResponseStructure(): void
        {
            $entityUuid = $this->client->pushEntity('global-response-test.com');
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);

            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('global-response-test.com', $entityRecord->getHost());
            $this->assertNull($entityRecord->getId());
            $this->assertIsInt($entityRecord->getCreated());
        }

        public function testEntityListResponseStructure(): void
        {
            $entityUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $entityUuid = $this->client->pushEntity("list-test-$i.com", "list_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $entities = $this->client->listEntities(1, 10);
            $this->assertIsArray($entities);

            $foundEntities = array_filter($entities, function($entity) use ($entityUuids) {
                return in_array($entity->getUuid(), $entityUuids);
            });

            $this->assertGreaterThanOrEqual(3, count($foundEntities));

            foreach ($foundEntities as $entity)
            {
                $this->assertInstanceOf(EntityRecord::class, $entity);
                $this->assertIsString($entity->getUuid());
                $this->assertIsString($entity->getHost());
                $this->assertIsInt($entity->getCreated());
            }
        }

        public function testOperatorResponseStructure(): void
        {
            $operatorUuid = $this->client->createOperator('Response Test Operator');
            $this->createdOperators[] = $operatorUuid;

            $this->assertIsString($operatorUuid);
            $this->assertNotEmpty($operatorUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $operatorUuid);

            $operator = $this->client->getOperator($operatorUuid);
            $this->assertInstanceOf(OperatorRecord::class, $operator);
            $this->assertIsString($operator->getUuid());
            $this->assertIsString($operator->getName());
            $this->assertIsString($operator->getAccessToken());
            $this->assertIsInt($operator->getCreated());
            $this->assertIsBool($operator->hasManagementPermissions());
            $this->assertIsBool($operator->hasOperatorPermissions());
            $this->assertIsBool($operator->hasClientPermissions());
            $this->assertIsBool($operator->isDisabled());
            $this->assertEquals($operatorUuid, $operator->getUuid());
            $this->assertEquals('Response Test Operator', $operator->getName());
            $this->assertNotEmpty($operator->getAccessToken());
            $this->assertGreaterThan(0, $operator->getCreated());
            $this->assertFalse($operator->isDisabled());
        }

        public function testSelfOperatorResponseStructure(): void
        {
            $selfOperator = $this->client->getSelf();
            $this->assertInstanceOf(OperatorRecord::class, $selfOperator);
            $this->assertIsString($selfOperator->getUuid());
            $this->assertIsString($selfOperator->getName());
            $this->assertIsString($selfOperator->getAccessToken());
            $this->assertIsInt($selfOperator->getCreated());
            $this->assertIsBool($selfOperator->hasManagementPermissions());
            $this->assertIsBool($selfOperator->hasOperatorPermissions());
            $this->assertIsBool($selfOperator->hasClientPermissions());
            $this->assertIsBool($selfOperator->isDisabled());
            $this->assertTrue($selfOperator->hasManagementPermissions() || $selfOperator->hasOperatorPermissions());
        }

        public function testOperatorListResponseStructure(): void
        {
            for ($i = 0; $i < 3; $i++)
            {
                $operatorUuid = $this->client->createOperator("List Test Operator $i");
                $this->createdOperators[] = $operatorUuid;
            }

            $operators = $this->client->listOperators(1, 10);
            $this->assertIsArray($operators);
            $this->assertGreaterThanOrEqual(3, count($operators));

            foreach ($operators as $operator)
            {
                $this->assertInstanceOf(OperatorRecord::class, $operator);
                $this->assertIsString($operator->getUuid());
                $this->assertIsString($operator->getName());
                $this->assertIsString($operator->getAccessToken());
                $this->assertIsInt($operator->getCreated());
                $this->assertIsBool($operator->hasManagementPermissions());
                $this->assertIsBool($operator->hasOperatorPermissions());
                $this->assertIsBool($operator->hasClientPermissions());
                $this->assertIsBool($operator->isDisabled());
            }
        }

        public function testEvidenceResponseStructure(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-response-test.com', 'evidence_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence(
                $entityUuid,
                'Test evidence content',
                'Test evidence note',
                'response_test'
            );
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->assertIsString($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $evidenceUuid);

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertInstanceOf(EvidenceRecord::class, $evidence);
            $this->assertIsString($evidence->getUuid());
            $this->assertIsString($evidence->getEntityUuid());
            $this->assertIsString($evidence->getOperatorUuid());
            $this->assertIsString($evidence->getTextContent());
            $this->assertIsString($evidence->getNote());
            $this->assertIsString($evidence->getTag());
            $this->assertIsInt($evidence->getCreated());
            $this->assertIsBool($evidence->isConfidential());
            $this->assertEquals($evidenceUuid, $evidence->getUuid());
            $this->assertEquals($entityUuid, $evidence->getEntityUuid());
            $this->assertEquals('Test evidence content', $evidence->getTextContent());
            $this->assertEquals('Test evidence note', $evidence->getNote());
            $this->assertEquals('response_test', $evidence->getTag());
            $this->assertFalse($evidence->isConfidential());
            $this->assertGreaterThan(0, $evidence->getCreated());
        }

        public function testEvidenceListResponseStructure(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-list-test.com', 'evidence_list_user');
            $this->createdEntities[] = $entityUuid;

            for ($i = 0; $i < 3; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Evidence content $i",
                    "Evidence note $i",
                    "list_test_$i"
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $evidenceList = $this->client->listEvidence(1, 10);
            $this->assertIsArray($evidenceList);
            $this->assertGreaterThanOrEqual(3, count($evidenceList));

            foreach ($evidenceList as $evidence)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $evidence);
                $this->assertIsString($evidence->getUuid());
                $this->assertIsString($evidence->getEntityUuid());
                $this->assertIsString($evidence->getOperatorUuid());
                $this->assertIsString($evidence->getTextContent());
                $this->assertIsInt($evidence->getCreated());
                $this->assertIsBool($evidence->isConfidential());
            }
        }

        public function testBlacklistResponseStructure(): void
        {
            $entityUuid = $this->client->pushEntity('blacklist-response-test.com', 'blacklist_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Blacklist evidence', 'Blacklist note', 'blacklist_test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $expiration = time() + 3600;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $expiration);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $this->assertIsString($blacklistUuid);
            $this->assertNotEmpty($blacklistUuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $blacklistUuid);

            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertInstanceOf(BlacklistRecord::class, $blacklistRecord);
            $this->assertIsString($blacklistRecord->getUuid());
            $this->assertIsString($blacklistRecord->getEntityUuid());
            $this->assertIsString($blacklistRecord->getEvidenceUuid());
            $this->assertIsString($blacklistRecord->getOperatorUuid());
            $this->assertInstanceOf(IncidentType::class, $blacklistRecord->getType());
            $this->assertIsInt($blacklistRecord->getCreated());
            $this->assertIsInt($blacklistRecord->getExpires());
            $this->assertIsBool($blacklistRecord->isLifted());
            $this->assertEquals($blacklistUuid, $blacklistRecord->getUuid());
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertEquals(IncidentType::SPAM, $blacklistRecord->getType());
            $this->assertEquals($expiration, $blacklistRecord->getExpires());
            $this->assertFalse($blacklistRecord->isLifted());
            $this->assertGreaterThan(0, $blacklistRecord->getCreated());
        }

        public function testBlacklistListResponseStructure(): void
        {
            for ($i = 0; $i < 3; $i++)
            {
                $entityUuid = $this->client->pushEntity("blacklist-list-$i.com", "blacklist_list_user_$i");
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence $i", "Note $i", 'list_test');
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
            }

            $blacklistRecords = $this->client->listBlacklistRecords(1, 10);
            $this->assertIsArray($blacklistRecords);
            $this->assertGreaterThanOrEqual(3, count($blacklistRecords));

            foreach ($blacklistRecords as $blacklistRecord)
            {
                $this->assertInstanceOf(BlacklistRecord::class, $blacklistRecord);
                $this->assertIsString($blacklistRecord->getUuid());
                $this->assertIsString($blacklistRecord->getEntityUuid());
                $this->assertIsString($blacklistRecord->getEvidenceUuid());
                $this->assertIsString($blacklistRecord->getOperatorUuid());
                $this->assertInstanceOf(IncidentType::class, $blacklistRecord->getType());
                $this->assertIsInt($blacklistRecord->getCreated());
                $this->assertIsInt($blacklistRecord->getExpires());
                $this->assertIsBool($blacklistRecord->isLifted());
            }
        }

        public function testAuditLogResponseStructure(): void
        {
            $operatorUuid = $this->client->createOperator('Audit Log Test Operator');
            $this->client->deleteOperator($operatorUuid);

            $auditLogs = $this->client->listAuditLogs(1, 10);
            $this->assertIsArray($auditLogs);
            $this->assertGreaterThan(0, count($auditLogs));

            foreach ($auditLogs as $auditLog)
            {
                $this->assertInstanceOf(AuditLog::class, $auditLog);
                $this->assertIsString($auditLog->getUuid());
                $this->assertNotNull($auditLog->getType());
                $this->assertIsString($auditLog->getMessage());
                $this->assertIsInt($auditLog->getTimestamp());
                $this->assertNotEmpty($auditLog->getUuid());
                $this->assertNotEmpty($auditLog->getMessage());
                $this->assertGreaterThan(0, $auditLog->getTimestamp());

                if ($auditLog->getOperatorUuid() !== null)
                {
                    $this->assertIsString($auditLog->getOperatorUuid());
                }
            }
        }

        public function testErrorResponseStructure(): void
        {
            try
            {
                $this->client->getEntityRecord('invalid-uuid-format');
                $this->fail('Expected RequestException was not thrown');
            }
            catch (RequestException $e)
            {
                $this->assertIsInt($e->getCode());
                $this->assertIsString($e->getMessage());
                $this->assertNotEmpty($e->getMessage());
                $this->assertGreaterThan(0, $e->getCode());
            }
        }

        public function testTimestampConsistency(): void
        {
            $beforeTime = time();

            $entityUuid = $this->client->pushEntity('timestamp-test.com', 'timestamp_user');
            $this->createdEntities[] = $entityUuid;

            $afterTime = time();

            $entity = $this->client->getEntityRecord($entityUuid);
            $entityTimestamp = $entity->getCreated();

            $this->assertGreaterThanOrEqual($beforeTime, $entityTimestamp);
            $this->assertLessThanOrEqual($afterTime + 1, $entityTimestamp);
        }

        public function testResponseConsistencyAcrossMultipleCalls(): void
        {
            $entityUuid = $this->client->pushEntity('consistency-test.com', 'consistency_user');
            $this->createdEntities[] = $entityUuid;

            $entity1 = $this->client->getEntityRecord($entityUuid);
            $entity2 = $this->client->getEntityRecord($entityUuid);
            $entity3 = $this->client->getEntityRecord($entityUuid);

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
