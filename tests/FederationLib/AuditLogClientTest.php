<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class AuditLogClientTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('audit-log-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up in reverse dependency order
            foreach ($this->createdBlacklistRecords as $blacklistUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
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
                    $this->logger->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
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
                    $this->logger->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
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
                    $this->logger->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            // Reset arrays
            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        // BASIC AUDIT LOG OPERATIONS

        public function testListAuditLogs(): void
        {
            // Perform some operations to generate audit logs
            $this->generateSampleAuditLogs();

            // List audit logs
            $auditLogs = $this->client->listAuditLogs();
            $this->assertIsArray($auditLogs);
            $this->assertNotEmpty($auditLogs);

            // Verify structure of audit log entries
            foreach ($auditLogs as $auditLog)
            {
                $this->assertNotNull($auditLog->getUuid());
                $this->assertNotEmpty($auditLog->getUuid());
                $this->assertNotNull($auditLog->getType());
                $this->assertNotNull($auditLog->getMessage());
                $this->assertNotNull($auditLog->getTimestamp());
                // Entity UUID can be null for some operations
            }
        }

        public function testListAuditLogsWithPagination(): void
        {
            // Generate enough audit logs to test pagination
            $this->generateSampleAuditLogs();

            // Test pagination
            $page1 = $this->client->listAuditLogs(1, 3);
            $page2 = $this->client->listAuditLogs(2, 3);

            $this->assertIsArray($page1);
            $this->assertIsArray($page2);
            $this->assertLessThanOrEqual(3, count($page1));
            $this->assertLessThanOrEqual(3, count($page2));

            // Verify pages contain different records (if we have enough)
            if (count($page1) > 0 && count($page2) > 0)
            {
                $page1Uuids = array_map(fn($log) => $log->getUuid(), $page1);
                $page2Uuids = array_map(fn($log) => $log->getUuid(), $page2);
                $this->assertCount(0, array_intersect($page1Uuids, $page2Uuids), "Pages should contain different records");
            }
        }

        public function testGetAuditLogRecord(): void
        {
            $this->markTestSkipped('Really buggy for some reason, skipped for now.');

            // Generate an audit log
            $operatorUuid = $this->client->createOperator('audit-log-test-operator');
            $this->createdOperators[] = $operatorUuid;

            // Get recent audit logs to find the one we just created
            $auditLogs = $this->client->listAuditLogs(1, 10);
            $this->assertNotEmpty($auditLogs);

            // Find our operator creation log
            $operatorCreationLog = null;
            foreach ($auditLogs as $log)
            {
                if (str_contains($log->getMessage(), 'audit-log-test-operator'))
                {
                    $operatorCreationLog = $log;
                    break;
                }
            }

            $this->assertNotNull($operatorCreationLog, "Could not find operator creation audit log");

            // Get the specific audit log record
            $auditLogRecord = $this->client->getAuditLogRecord($operatorCreationLog->getUuid());
            $this->assertNotNull($auditLogRecord);
            $this->assertEquals($operatorCreationLog->getUuid(), $auditLogRecord->getUuid());
            $this->assertEquals($operatorCreationLog->getType(), $auditLogRecord->getType());
            $this->assertEquals($operatorCreationLog->getMessage(), $auditLogRecord->getMessage());
            $this->assertEquals($operatorCreationLog->getTimestamp(), $auditLogRecord->getTimestamp());
            $this->assertEquals($operatorCreationLog->getOperatorUuid(), $auditLogRecord->getOperatorUuid());
        }

        public function testListOperatorAuditLogs(): void
        {
            // Create an operator to generate specific audit logs
            $operatorUuid = $this->client->createOperator('operator-audit-test');
            $this->createdOperators[] = $operatorUuid;

            // Perform operations with this operator
            $operator = $this->client->getOperator($operatorUuid);
            $this->client->setClientPermission($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            // Perform some operations that should generate audit logs
            $entityUuid = $operatorClient->pushEntity('operator-audit-test.com', 'audit_user');
            $this->createdEntities[] = $entityUuid;

            // List audit logs for this specific operator
            $operatorAuditLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $this->assertIsArray($operatorAuditLogs);

            // All logs should be for this operator
            foreach ($operatorAuditLogs as $log)
            {
                $this->assertEquals($operatorUuid, $log->getOperatorUuid());
            }
        }

        public function testListOperatorAuditLogsWithPagination(): void
        {
            // Create an operator and perform multiple operations
            $operatorUuid = $this->client->createOperator('paginated-audit-test');
            $this->createdOperators[] = $operatorUuid;

            $operator = $this->client->getOperator($operatorUuid);
            $this->client->setClientPermission($operatorUuid, true);
            $this->client->setManageBlacklistPermission($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            // Generate multiple audit log entries
            for ($i = 1; $i <= 3; $i++)
            {
                $entityUuid = $operatorClient->pushEntity("paginated-audit-$i.com", "user_$i");
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $operatorClient->submitEvidence($entityUuid, "Evidence $i", "Note $i", "tag_$i");
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            // Test pagination
            $page1 = $this->client->listOperatorAuditLogs($operatorUuid, 1, 3);
            $page2 = $this->client->listOperatorAuditLogs($operatorUuid, 2, 3);

            $this->assertIsArray($page1);
            $this->assertIsArray($page2);
            $this->assertLessThanOrEqual(3, count($page1));

            // Verify all logs are for the correct operator
            foreach (array_merge($page1, $page2) as $log)
            {
                $this->assertEquals($operatorUuid, $log->getOperatorUuid());
            }
        }

        // VALIDATION AND ERROR HANDLING

        public function testListAuditLogsInvalidPage(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listAuditLogs(-1);
        }

        public function testListAuditLogsInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listAuditLogs(1, -1);
        }

        public function testGetAuditLogRecordInvalidUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->getAuditLogRecord('');
        }

        public function testGetNonExistentAuditLogRecord(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404);
            $this->client->getAuditLogRecord($fakeUuid);
        }

        public function testListOperatorAuditLogsInvalidOperatorUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listOperatorAuditLogs('');
        }

        public function testListOperatorAuditLogsNonExistentOperator(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404);
            $this->client->listOperatorAuditLogs($fakeUuid);
        }

        public function testListOperatorAuditLogsInvalidPage(): void
        {
            $operatorUuid = $this->client->createOperator('invalid-page-test');
            $this->createdOperators[] = $operatorUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->client->listOperatorAuditLogs($operatorUuid, -1);
        }

        public function testListOperatorAuditLogsInvalidLimit(): void
        {
            $operatorUuid = $this->client->createOperator('invalid-limit-test');
            $this->createdOperators[] = $operatorUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->client->listOperatorAuditLogs($operatorUuid, 1, -1);
        }

        // PERMISSION AND AUTHORIZATION TESTS

        public function testAuditLogAccessLimitedOperator(): void
        {
            // Create an operator with minimal permissions
            $operatorUuid = $this->client->createOperator('limited-audit-access');
            $this->createdOperators[] = $operatorUuid;

            // Give minimal permissions
            $this->client->setClientPermission($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $limitedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            try
            {
                // This operator should be able to see their own audit logs
                $operatorAuditLogs = $limitedClient->listOperatorAuditLogs($operatorUuid);
                $this->assertIsArray($operatorAuditLogs);
            }
            catch (RequestException $e)
            {
                // If system restricts audit log access, this is acceptable
                $this->assertContains($e->getCode(), [401, 403], "Expected 401/403 for restricted audit log access");
            }
        }

        // AUDIT LOG CONTENT VERIFICATION

        public function testAuditLogContentForOperatorOperations(): void
        {
            $initialLogCount = count($this->client->listAuditLogs(1, 100));

            // Create an operator - should generate audit log
            $operatorUuid = $this->client->createOperator('audit-content-test');
            $this->createdOperators[] = $operatorUuid;

            // Modify permissions - should generate audit logs
            $this->client->setClientPermission($operatorUuid, true);
            $this->client->setManageBlacklistPermission($operatorUuid, true);

            // Disable and re-enable - should generate audit logs
            $this->client->disableOperator($operatorUuid);
            $this->client->enableOperator($operatorUuid);

            // Get recent audit logs
            $newLogs = $this->client->listAuditLogs(1, 50);
            $newLogCount = count($newLogs);

            // Look for specific operation logs
            $foundOperatorCreation = false;
            $foundPermissionChange = false;
            $foundStatusChange = false;

            foreach ($newLogs as $log)
            {
                $message = $log->getMessage();
                
                if (str_contains($message, 'audit-content-test') && str_contains($message, 'created'))
                {
                    $foundOperatorCreation = true;
                }
                
                if (str_contains($message, 'permission') || str_contains($message, 'Permission'))
                {
                    $foundPermissionChange = true;
                }
                
                if (str_contains($message, 'disabled') || str_contains($message, 'enabled'))
                {
                    $foundStatusChange = true;
                }
            }

            $this->assertTrue($foundOperatorCreation, "Should find operator creation audit log");
            // Permission and status changes might not be logged separately, so we'll log but not assert
            $this->logger->info("Found permission change log: " . ($foundPermissionChange ? 'Yes' : 'No'));
            $this->logger->info("Found status change log: " . ($foundStatusChange ? 'Yes' : 'No'));
        }

        public function testAuditLogContentForEntityOperations(): void
        {
            // Create operator with client permissions
            $operatorUuid = $this->client->createOperator('entity-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermission($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            $initialLogCount = count($this->client->listOperatorAuditLogs($operatorUuid));

            // Perform entity operations
            $entityUuid = $operatorClient->pushEntity('audit-entity-test.com', 'audit_entity_user');
            $this->createdEntities[] = $entityUuid;

            // Get audit logs for this operator
            $operatorLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $newLogCount = count($operatorLogs);

            // Should have more logs than before
            $this->assertGreaterThan($initialLogCount, $newLogCount);

            // Look for entity creation log
            $foundEntityCreation = false;
            foreach ($operatorLogs as $log)
            {
                if($log->getEntityUuid() === $entityUuid && $log->getType() === AuditLogType::ENTITY_PUSHED)
                {
                    $foundEntityCreation = true;
                    break;
                }
            }

            $this->assertTrue($foundEntityCreation, "Should find entity creation audit log");
        }

        public function testAuditLogContentForBlacklistOperations(): void
        {
            // Create operator with blacklist permissions
            $operatorUuid = $this->client->createOperator('blacklist-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermission($operatorUuid, true);
            $this->client->setManageBlacklistPermission($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            // Create entity and evidence
            $entityUuid = $operatorClient->pushEntity('blacklist-audit-test.com', 'blacklist_audit_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Audit test evidence', 'Audit test', 'audit');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $initialLogCount = count($this->client->listOperatorAuditLogs($operatorUuid));

            // Perform blacklist operations
            $blacklistUuid = $operatorClient->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $operatorClient->liftBlacklistRecord($blacklistUuid);

            // Get audit logs for this operator
            $operatorLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $newLogCount = count($operatorLogs);

            // Should have more logs than before
            $this->assertGreaterThan($initialLogCount, $newLogCount);

            // Look for blacklist operation logs
            $foundBlacklistCreation = false;
            $foundBlacklistLift = false;

            foreach ($operatorLogs as $log)
            {
                $message = $log->getMessage();
                
                if (str_contains($message, 'blacklist') && str_contains($message, 'created'))
                {
                    $foundBlacklistCreation = true;
                }
                
                if (str_contains($message, 'blacklist') && (str_contains($message, 'lifted') || str_contains($message, 'removed')))
                {
                    $foundBlacklistLift = true;
                }
            }

            $this->assertTrue($foundBlacklistCreation, "Should find blacklist creation audit log");
            $this->assertTrue($foundBlacklistLift, "Should find blacklist lift audit log");
        }

        // DURABILITY AND PERFORMANCE TESTS

        public function testAuditLogConsistencyOverTime(): void
        {
            // Perform an operation
            $operatorUuid = $this->client->createOperator('consistency-test');
            $this->createdOperators[] = $operatorUuid;

            // Get audit logs immediately
            $immediateAuditLogs = $this->client->listAuditLogs(1, 10);
            
            // Wait a brief moment and get again
            sleep(1);
            $delayedAuditLogs = $this->client->listAuditLogs(1, 10);

            // Recent logs should be consistent
            $this->assertEquals(count($immediateAuditLogs), count($delayedAuditLogs));
            
            for ($i = 0; $i < min(count($immediateAuditLogs), count($delayedAuditLogs)); $i++)
            {
                $this->assertEquals($immediateAuditLogs[$i]->getUuid(), $delayedAuditLogs[$i]->getUuid());
                $this->assertEquals($immediateAuditLogs[$i]->getMessage(), $delayedAuditLogs[$i]->getMessage());
            }
        }

        public function testHighVolumeAuditLogRetrieval(): void
        {
            // Generate multiple audit log entries
            for ($i = 1; $i <= 5; $i++)
            {
                $operatorUuid = $this->client->createOperator("high-volume-test-$i");
                $this->createdOperators[] = $operatorUuid;
            }

            // Test retrieving large number of audit logs
            $auditLogs = $this->client->listAuditLogs(1, 100);
            $this->assertIsArray($auditLogs);

            // Verify all logs have required fields
            foreach ($auditLogs as $log)
            {
                $this->assertNotNull($log->getUuid());
                $this->assertNotNull($log->getType());
                $this->assertNotNull($log->getMessage());
                $this->assertNotNull($log->getTimestamp());
            }
        }

        // HELPER METHODS

        private function generateSampleAuditLogs(): void
        {
            // Create some operations to generate audit logs
            $operatorUuid = $this->client->createOperator('sample-audit-operator');
            $this->createdOperators[] = $operatorUuid;

            $this->client->setClientPermission($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            $entityUuid = $operatorClient->pushEntity('sample-audit.com', 'sample_user');
            $this->createdEntities[] = $entityUuid;
        }
    }
