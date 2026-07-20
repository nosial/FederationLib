<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class AuditLogClientTest extends TestCase
    {
        use TestHelpers;
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

        public function testListOperatorAuditLogs(): void
        {
            $operatorUuid = $this->client->createOperator('operator-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('operator-audit-test.com', 'audit_user');
            $this->createdEntities[] = $entityUuid;

            $operatorAuditLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $this->assertIsArray($operatorAuditLogs);
            $this->assertNotEmpty($operatorAuditLogs);

            foreach ($operatorAuditLogs as $log)
            {
                $this->assertEquals($operatorUuid, $log->getOperatorUuid());
            }
        }

        public function testListOperatorAuditLogsWithPagination(): void
        {
            $operatorUuid = $this->client->createOperator('paginated-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            for ($i = 1; $i <= 3; $i++)
            {
                $entityUuid = $operatorClient->pushEntity("paginated-audit-$i.com", "user_$i");
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $operatorClient->submitEvidence($entityUuid, "Evidence $i", "Note $i", "tag_$i");
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $page1 = $this->client->listOperatorAuditLogs($operatorUuid, 1, 3);
            $page2 = $this->client->listOperatorAuditLogs($operatorUuid, 2, 3);

            $this->assertIsArray($page1);
            $this->assertIsArray($page2);
            $this->assertLessThanOrEqual(3, count($page1));

            foreach (array_merge($page1, $page2) as $log)
            {
                $this->assertEquals($operatorUuid, $log->getOperatorUuid());
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

        public function testListAuditLogsSortByTimestampDescending(): void
        {
            $operatorUuid = null;
            $this->generateSampleAuditLogs($operatorUuid);

            $logsAsc = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100, null, 'timestamp', 'ASC');
            $logsDesc = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100, null, 'timestamp', 'DESC');

            $this->assertNotEmpty($logsAsc);
            $this->assertNotEmpty($logsDesc);

            $uuidsAsc = array_map(fn($l) => $l->getUuid(), $logsAsc);
            $uuidsDesc = array_map(fn($l) => $l->getUuid(), $logsDesc);

            $this->assertSame(array_reverse($uuidsAsc), $uuidsDesc);
        }

        public function testListAuditLogsSortInvalidFieldFallsBack(): void
        {
            $operatorUuid = null;
            $this->generateSampleAuditLogs($operatorUuid);

            $resultDefault = $this->client->listOperatorAuditLogs($operatorUuid, 1, 10);
            $resultInvalid = $this->client->listOperatorAuditLogs($operatorUuid, 1, 10, null, 'bogus_field');

            $defaultUuids = array_map(fn($l) => $l->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($l) => $l->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testAuditLogAccessLimitedOperator(): void
        {
            $operatorUuid = $this->client->createOperator('limited-audit-access');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $limitedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $operatorAuditLogs = $limitedClient->listOperatorAuditLogs($operatorUuid);
            $this->assertIsArray($operatorAuditLogs);
        }

        public function testAuditLogContentForEntityOperations(): void
        {
            $operatorUuid = $this->client->createOperator('entity-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $initialLogCount = count($this->client->listOperatorAuditLogs($operatorUuid));

            $entityUuid = $operatorClient->pushEntity('audit-entity-test.com', 'audit_entity_user');
            $this->createdEntities[] = $entityUuid;

            $operatorLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $newLogCount = count($operatorLogs);

            $this->assertGreaterThan($initialLogCount, $newLogCount);

            $foundEntityCreation = false;
            foreach ($operatorLogs as $log)
            {
                if ($log->getEntityUuid() === $entityUuid && $log->getType() === AuditLogType::ENTITY_PUSHED)
                {
                    $foundEntityCreation = true;
                    break;
                }
            }

            $this->assertTrue($foundEntityCreation, 'Should find entity creation audit log');
        }

        public function testAuditLogContentForBlacklistOperations(): void
        {
            $operatorUuid = $this->client->createOperator('blacklist-audit-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('blacklist-audit-test.com', 'blacklist_audit_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Audit test evidence', 'Audit test', 'audit');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $initialLogCount = count($this->client->listOperatorAuditLogs($operatorUuid));

            $blacklistUuid = $operatorClient->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $operatorClient->liftBlacklistRecord($blacklistUuid);

            $operatorLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $newLogCount = count($operatorLogs);

            $this->assertGreaterThan($initialLogCount, $newLogCount);

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

            $this->assertTrue($foundBlacklistCreation, 'Should find blacklist creation audit log');
            $this->assertTrue($foundBlacklistLift, 'Should find blacklist lift audit log');
        }

        public function testAuditLogConsistencyOverTime(): void
        {
            $operatorUuid = $this->client->createOperator('consistency-test');
            $this->createdOperators[] = $operatorUuid;

            $immediateAuditLogs = $this->client->listAuditLogs(1, 10);

            sleep(1);
            $delayedAuditLogs = $this->client->listAuditLogs(1, 10);

            $this->assertEquals(count($immediateAuditLogs), count($delayedAuditLogs));

            for ($i = 0; $i < min(count($immediateAuditLogs), count($delayedAuditLogs)); $i++)
            {
                $this->assertEquals($immediateAuditLogs[$i]->getUuid(), $delayedAuditLogs[$i]->getUuid());
                $this->assertEquals($immediateAuditLogs[$i]->getMessage(), $delayedAuditLogs[$i]->getMessage());
            }
        }

        public function testHighVolumeAuditLogRetrieval(): void
        {
            for ($i = 1; $i <= 5; $i++)
            {
                $operatorUuid = $this->client->createOperator("high-volume-test-$i");
                $this->createdOperators[] = $operatorUuid;
            }

            $auditLogs = $this->client->listAuditLogs(1, 100);
            $this->assertIsArray($auditLogs);
            $this->assertGreaterThanOrEqual(5, count($auditLogs));

            foreach ($auditLogs as $log)
            {
                $this->assertNotEmpty($log->getUuid());
                $this->assertNotNull($log->getType());
                $this->assertNotEmpty($log->getMessage());
                $this->assertIsInt($log->getTimestamp());
            }
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

        public function testSecurityUnauthenticatedAuditLogAccessIsPublic(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);

            // Audit logs are public by default; unauthenticated clients can list and view
            // public entries, so these calls succeed rather than fail.
            $logs = $unauthenticatedClient->listAuditLogs();
            $this->assertIsArray($logs);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $unauthenticatedClient->getAuditLogRecord('00000000-0000-0000-0000-000000000000');
        }

        public function testAuditLogRecordsOperatorActorForEntityCreation(): void
        {
            $operatorUuid = $this->client->createOperator('actor-entity-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $beforeLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $beforeCount = count($beforeLogs);

            $entityUuid = $operatorClient->pushEntity('actor-entity.com', 'actor_user');
            $this->createdEntities[] = $entityUuid;

            $afterLogs = $this->client->listOperatorAuditLogs($operatorUuid);
            $this->assertGreaterThan($beforeCount, count($afterLogs));

            $found = false;
            foreach ($afterLogs as $log)
            {
                if ($log->getOperatorUuid() === $operatorUuid && $log->getEntityUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Audit log should record the acting operator and affected entity');
        }

        public function testAuditLogRecordsEvidenceAndBlacklistActor(): void
        {
            $operatorUuid = $this->client->createOperator('actor-evidence-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('actor-evidence.com', 'actor_evidence_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Actor evidence', 'Note', 'actor');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $operatorClient->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $logs = $this->client->listOperatorAuditLogs($operatorUuid);
            $foundEvidence = false;
            $foundBlacklist = false;

            foreach ($logs as $log)
            {
                if ($log->getEvidenceUuid() === $evidenceUuid && $log->getOperatorUuid() === $operatorUuid)
                {
                    $foundEvidence = true;
                }

                if ($log->getBlacklistUuid() === $blacklistUuid && $log->getOperatorUuid() === $operatorUuid)
                {
                    $foundBlacklist = true;
                }
            }

            $this->assertTrue($foundEvidence, 'Audit log should record evidence submitted by operator');
            $this->assertTrue($foundBlacklist, 'Audit log should record blacklist created by operator');
        }

        public function testAuditLogFiltersByType(): void
        {
            $operatorUuid = $this->client->createOperator('type-filter-test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('type-filter.com', 'type_filter_user');
            $this->createdEntities[] = $entityUuid;

            $allLogs = $this->client->listAuditLogs(1, 100);
            $entityPushLogs = array_filter(
                $allLogs,
                fn($log) => $log->getType() === AuditLogType::ENTITY_PUSHED && $log->getEntityUuid() === $entityUuid
            );
            $this->assertNotEmpty($entityPushLogs);
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

        public function testAuditLogRemainsAfterEntityDeletion(): void
        {
            $entityUuid = $this->client->pushEntity('audit-survive.com', 'audit_survive_user');
            $this->createdEntities[] = $entityUuid;

            $logsBefore = $this->client->listEntityAuditLogs($entityUuid);
            $this->assertNotEmpty($logsBefore);

            $this->client->deleteEntity($entityUuid);
            $this->removeFromCleanup($this->createdEntities, $entityUuid);

            foreach ($logsBefore as $log)
            {
                $retrieved = $this->client->getAuditLogRecord($log->getUuid());
                $this->assertNotNull($retrieved);
                // The entity UUID may be nullified when the entity is deleted.
                if ($retrieved->getEntityUuid() !== null)
                {
                    $this->assertEquals($entityUuid, $retrieved->getEntityUuid());
                }
            }
        }

        public function testListAuditLogsCategoryOperatorEvents(): void
        {
            $operatorUuid = $this->client->createOperator('aud_cat_op_' . uniqid());
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());
            $entityUuid = $operatorClient->pushEntity('aud-cat-op.com', 'aud_cat_op_user');
            $this->createdEntities[] = $entityUuid;

            $logs = $this->client->listAuditLogs(1, 100, 'OPERATOR_EVENTS');
            $this->assertIsArray($logs);
            $this->assertNotEmpty($logs);

            foreach ($logs as $log)
            {
                $this->assertContains($log->getType(), [
                    AuditLogType::OPERATOR_CREATED,
                    AuditLogType::OPERATOR_DELETED,
                    AuditLogType::OPERATOR_DISABLED,
                    AuditLogType::OPERATOR_ENABLED,
                    AuditLogType::OPERATOR_PERMISSIONS_CHANGED,
                    AuditLogType::OPERATOR_ACCESS_TOKEN_GENERATED,
                    AuditLogType::OPERATOR_NAME_CHANGED,
                ]);
            }
        }

        public function testListAuditLogsCategoryEntityEvents(): void
        {
            $operatorUuid = $this->client->createOperator('aud_cat_ent_' . uniqid());
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());
            $entityUuid = $operatorClient->pushEntity('aud-cat-ent.com', 'aud_cat_ent_user');
            $this->createdEntities[] = $entityUuid;

            $logs = $this->client->listAuditLogs(1, 100, 'ENTITY_EVENTS');
            $this->assertIsArray($logs);
            $this->assertNotEmpty($logs);

            foreach ($logs as $log)
            {
                $this->assertContains($log->getType(), [
                    AuditLogType::ENTITY_DELETED,
                    AuditLogType::ENTITY_BLACKLISTED,
                    AuditLogType::ENTITY_PUSHED,
                    AuditLogType::ENTITY_REPUTATION_CLEARED,
                ]);
            }
        }

        public function testListAuditLogsCategoryWithSort(): void
        {
            $operatorUuid = $this->client->createOperator('aud_cat_sort_' . uniqid());
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            for ($i = 0; $i < 3; $i++)
            {
                $entityUuid = $operatorClient->pushEntity("aud-cat-sort-$i.com", "aud_cat_sort_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            $logsAsc = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100, 'ENTITY_EVENTS', 'timestamp', 'ASC');
            $logsDesc = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100, 'ENTITY_EVENTS', 'timestamp', 'DESC');

            $this->assertNotEmpty($logsAsc);
            $this->assertNotEmpty($logsDesc);

            $uuidsAsc = array_map(fn($l) => $l->getUuid(), $logsAsc);
            $uuidsDesc = array_map(fn($l) => $l->getUuid(), $logsDesc);
            $this->assertSame(array_reverse($uuidsAsc), $uuidsDesc);
        }

        public function testListAuditLogsCategoryInvalidFallsBack(): void
        {
            $resultDefault = $this->client->listAuditLogs(1, 10);
            $resultInvalid = $this->client->listAuditLogs(1, 10, 'BOGUS_CATEGORY');

            $defaultUuids = array_map(fn($l) => $l->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($l) => $l->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListAuditLogsCategoryCaseInsensitive(): void
        {
            $resultUpper = $this->client->listAuditLogs(1, 10, 'OPERATOR_EVENTS');
            $resultLower = $this->client->listAuditLogs(1, 10, 'operator_events');
            $resultMixed = $this->client->listAuditLogs(1, 10, 'Operator_Events');

            $upperUuids = array_map(fn($l) => $l->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($l) => $l->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($l) => $l->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
        }

        public function testListEntityAuditLogsCategoryEntityEvents(): void
        {
            $entityUuid = $this->client->pushEntity('entity-cat-ent.com', 'entity_cat_ent_user');
            $this->createdEntities[] = $entityUuid;

            $logs = $this->client->listEntityAuditLogs($entityUuid, 1, 100, 'ENTITY_EVENTS');
            $this->assertIsArray($logs);
            $this->assertNotEmpty($logs);

            foreach ($logs as $log)
            {
                $this->assertContains($log->getType(), [
                    AuditLogType::ENTITY_DELETED,
                    AuditLogType::ENTITY_BLACKLISTED,
                    AuditLogType::ENTITY_PUSHED,
                    AuditLogType::ENTITY_REPUTATION_CLEARED,
                ]);
            }
        }

        public function testListEntityAuditLogsCategoryEvidenceEvents(): void
        {
            $operatorUuid = $this->client->createOperator('ent_cat_ev_' . uniqid());
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('ent-cat-ev.com', 'ent_cat_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Category test evidence', 'Testing evidence category', 'ev_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $logs = $this->client->listEntityAuditLogs($entityUuid, 1, 100, 'EVIDENCE_EVENTS');
            $this->assertIsArray($logs);
            $this->assertNotEmpty($logs);

            foreach ($logs as $log)
            {
                $this->assertContains($log->getType(), [
                    AuditLogType::EVIDENCE_SUBMITTED,
                    AuditLogType::EVIDENCE_UPDATED,
                    AuditLogType::EVIDENCE_DELETED,
                ]);
            }
        }

        public function testListEntityAuditLogsCategoryInvalidFallsBack(): void
        {
            $entityUuid = $this->client->pushEntity('entity-cat-bogus.com', 'entity_cat_bogus_user');
            $this->createdEntities[] = $entityUuid;

            $resultDefault = $this->client->listEntityAuditLogs($entityUuid, 1, 10);
            $resultInvalid = $this->client->listEntityAuditLogs($entityUuid, 1, 10, 'BOGUS_CATEGORY');

            $defaultUuids = array_map(fn($l) => $l->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($l) => $l->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListEntityAuditLogsCategoryWithSort(): void
        {
            $entityUuid = $this->client->pushEntity('entity-cat-sort.com', 'entity_cat_sort_user');
            $this->createdEntities[] = $entityUuid;

            $logsAsc = $this->client->listEntityAuditLogs($entityUuid, 1, 100, null, 'timestamp', 'ASC');
            $logsDesc = $this->client->listEntityAuditLogs($entityUuid, 1, 100, null, 'timestamp', 'DESC');

            $this->assertNotEmpty($logsAsc);
            $this->assertNotEmpty($logsDesc);

            $uuidsAsc = array_map(fn($l) => $l->getUuid(), $logsAsc);
            $uuidsDesc = array_map(fn($l) => $l->getUuid(), $logsDesc);
            $this->assertSame(array_reverse($uuidsAsc), $uuidsDesc);
        }

        public function testListEntityAuditLogsCategoryCaseInsensitive(): void
        {
            $entityUuid = $this->client->pushEntity('entity-cat-ci.com', 'entity_cat_ci_user');
            $this->createdEntities[] = $entityUuid;

            $resultUpper = $this->client->listEntityAuditLogs($entityUuid, 1, 10, 'ENTITY_EVENTS');
            $resultLower = $this->client->listEntityAuditLogs($entityUuid, 1, 10, 'entity_events');
            $resultMixed = $this->client->listEntityAuditLogs($entityUuid, 1, 10, 'Entity_Events');

            $upperUuids = array_map(fn($l) => $l->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($l) => $l->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($l) => $l->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
        }

        public function testSecurityOperatorAuditLogsAreIsolated(): void
        {
            $actor = $this->createLimitedOperator('audit_actor', operator: true);
            $victim = $this->createLimitedOperator('audit_victim', management: true);
            $snooper = $this->createLimitedOperator('audit_snooper', client: true);

            // Generate a private audit log entry (OPERATOR_PERMISSIONS_CHANGED) as the actor.
            $actor->setManagementPermissions($victim->getSelf()->getUuid(), false);

            $this->expectRequestFailure(
                fn() => $snooper->listOperatorAuditLogs($actor->getSelf()->getUuid()),
                [HttpResponseCode::FORBIDDEN->value],
                'One operator should not be able to list another operator\'s audit logs'
            );
        }
    }
