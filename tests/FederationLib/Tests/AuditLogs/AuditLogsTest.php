<?php

    namespace FederationLib\Tests\AuditLogs;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class AuditLogsTest extends TestCase
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

        private function generateSampleAuditLogs(bool &$operatorUuid=null): void
        {
            $operatorUuid = $this->client->createOperator('sample-audit-operator');
            $this->createdOperators[] = $operatorUuid;

            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('sample-audit.com', 'sample_user');
            $this->createdEntities[] = $entityUuid;
        }

        public function testListAuditLogs(): void
        {
            $this->generateSampleAuditLogs();

            $auditLogs = $this->client->listAuditLogs();
            $this->assertIsArray($auditLogs);
            $this->assertNotEmpty($auditLogs);

            foreach ($auditLogs as $auditLog)
            {
                $this->assertNotEmpty($auditLog->getUuid());
                $this->assertNotNull($auditLog->getType());
                $this->assertNotEmpty($auditLog->getMessage());
                $this->assertIsInt($auditLog->getTimestamp());
                $this->assertGreaterThan(0, $auditLog->getTimestamp());
            }
        }

        public function testListAuditLogsWithPagination(): void
        {
            $this->generateSampleAuditLogs();

            $page1 = $this->client->listAuditLogs(1, 3);
            $page2 = $this->client->listAuditLogs(2, 3);

            $this->assertIsArray($page1);
            $this->assertIsArray($page2);
            $this->assertLessThanOrEqual(3, count($page1));
            $this->assertLessThanOrEqual(3, count($page2));

            if (count($page1) > 0 && count($page2) > 0)
            {
                $page1Uuids = array_map(fn($log) => $log->getUuid(), $page1);
                $page2Uuids = array_map(fn($log) => $log->getUuid(), $page2);
                $this->assertCount(0, array_intersect($page1Uuids, $page2Uuids), 'Pages should contain different records');
            }
        }

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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
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

        public function testAuditLogEntryRetrievableByUuid(): void
        {
            $operatorUuid = $this->client->createOperator('uuid-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('uuid-audit.com', 'uuid_audit_user');
            $this->createdEntities[] = $entityUuid;

            $logs = $this->client->listOperatorAuditLogs($operatorUuid);
            $this->assertNotEmpty($logs);

            $firstLog = reset($logs);
            $retrieved = $this->client->getAuditLogRecord($firstLog->getUuid());
            $this->assertEquals($firstLog->getUuid(), $retrieved->getUuid());
            $this->assertEquals($firstLog->getMessage(), $retrieved->getMessage());
            $this->assertEquals($firstLog->getType(), $retrieved->getType());
        }
    }
