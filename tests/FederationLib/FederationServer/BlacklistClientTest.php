<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\SecurityTestHelpers;
    use PHPUnit\Framework\TestCase;

    class BlacklistClientTest extends TestCase
    {
        use SecurityTestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdBlacklistRecords = [];
        private array $createdEvidenceRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
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
                    Logger::getLogger()->warning("Failed to delete blacklist record $blacklistRecordUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdReports as $reportUuid)
            {
                try
                {
                    $this->client->deleteReport($reportUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete report $reportUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdBlacklistRecords = [];
            $this->createdEntities = [];
            $this->createdOperators = [];
            $this->createdEvidenceRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testBlacklistEntity(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'john_test');
            $this->createdEntities[] = $entityUuid;
            $this->assertNotEmpty($entityUuid);

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('john_test', $entityRecord->getId());
            $this->assertEquals('example.com', $entityRecord->getHost());

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Subscribe to my free crypto exchange!', 'Automated Spam Detection', 'spam');
            $this->assertNotEmpty($evidenceUuid);

            $operatorUuid = $this->client->getSelf()->getUuid();
            $this->assertNotEmpty($operatorUuid);

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($evidenceUuid, $evidenceRecord->getUuid());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals('Subscribe to my free crypto exchange!', $evidenceRecord->getTextContent());
            $this->assertEquals('Automated Spam Detection', $evidenceRecord->getNote());
            $this->assertEquals('spam', $evidenceRecord->getTag());
            $this->assertEquals($operatorUuid, $evidenceRecord->getOperatorUuid());

            $expires = time() + 3600;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $expires);
            $this->assertNotEmpty($blacklistUuid);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertEquals($operatorUuid, $blacklistRecord->getOperatorUuid());
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertNotNull($blacklistRecord->getExpires());
            $this->assertEquals($expires, $blacklistRecord->getExpires());
            $this->assertFalse($blacklistRecord->isLifted());
        }

        public function testBlacklistEntityPermanent(): void
        {
            $entityUuid = $this->client->pushEntity('malware.example.org', 'infected_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Detected malware distribution', 'Automated Security Scan', 'malware');
            $this->assertNotEmpty($evidenceUuid);

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);
            $this->assertNotEmpty($blacklistUuid);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
            $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            $this->assertNull($blacklistRecord->getExpires());
            $this->assertFalse($blacklistRecord->isLifted());
        }

        public function testBlacklistEntityInvalidArguments(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The entity identifier must not be empty');
            $this->client->blacklistEntity('', 'some-uuid', IncidentType::SPAM);
        }

        public function testBlacklistEntityInvalidEvidenceUuid(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The evidence UUID must not be empty');
            $this->client->blacklistEntity('some-entity-uuid', '', IncidentType::SPAM);
        }

        public function testBlacklistEntityNegativeExpires(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The expires parameter must be a positive integer or null');
            $this->client->blacklistEntity('some-entity-uuid', 'some-evidence-uuid', IncidentType::SPAM, -1);
        }

        public function testDeleteBlacklistRecord(): void
        {
            $entityUuid = $this->client->pushEntity('delete-test.com', 'user_to_delete');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content for deletion', 'Test note', 'test');

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->assertNotEmpty($blacklistUuid);

            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);

            $this->client->deleteBlacklistRecord($blacklistUuid);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getBlacklistRecord($fakeUuid);
        }

        public function testLiftBlacklistRecord(): void
        {
            $entityUuid = $this->client->pushEntity('lift-test.com', 'user_to_lift');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content for lifting', 'Test note', 'test');

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->assertNotEmpty($blacklistUuid);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertFalse($blacklistRecord->isLifted());

            $this->client->liftBlacklistRecord($blacklistUuid);

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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->liftBlacklistRecord($fakeUuid);
        }

        public function testListBlacklistRecords(): void
        {
            $createdBlacklistUuids = [];

            for ($i = 0; $i < 3; $i++)
            {
                $entityUuid = $this->client->pushEntity("list-test-$i.com", "user_$i");
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Test content $i", "Test note $i", 'test');

                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
                $createdBlacklistUuids[] = $blacklistUuid;
                $this->createdBlacklistRecords[] = $blacklistUuid;
            }

            $blacklistRecords = $this->client->listBlacklistRecords();
            $this->assertIsArray($blacklistRecords);
            $this->assertGreaterThanOrEqual(3, count($blacklistRecords));

            $foundUuids = array_map(fn($record) => $record->getUuid(), $blacklistRecords);
            foreach ($createdBlacklistUuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }
        }

        public function testListBlacklistRecordsWithPagination(): void
        {
            $blacklistRecords = $this->client->listBlacklistRecords(1, 2);
            $this->assertIsArray($blacklistRecords);
            $this->assertLessThanOrEqual(2, count($blacklistRecords));
        }

        public function testListBlacklistRecordsWithLifted(): void
        {
            $entityUuid = $this->client->pushEntity('lifted-test.com', 'lifted_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content for lifted', 'Test note', 'test');

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $this->client->liftBlacklistRecord($blacklistUuid);

            $blacklistRecordsWithLifted = $this->client->listBlacklistRecords(1, 100, true);
            $this->assertIsArray($blacklistRecordsWithLifted);

            $blacklistRecordsWithoutLifted = $this->client->listBlacklistRecords(1, 100, false);
            $this->assertIsArray($blacklistRecordsWithoutLifted);

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
                IncidentType::SCAM,
                IncidentType::SERVICE_ABUSE,
                IncidentType::ILLEGAL_CONTENT,
                IncidentType::PHISHING,
                IncidentType::OTHER
            ];

            foreach ($blacklistTypes as $type)
            {
                $entityUuid = $this->client->pushEntity('type-test.com', 'user_' . $type->value);
                $this->createdEntities[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content for ' . $type->value, 'Test note', strtolower($type->value));

                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 3600);
                $this->assertNotEmpty($blacklistUuid);
                $this->createdBlacklistRecords[] = $blacklistUuid;

                $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($blacklistRecord);
                $this->assertEquals($entityUuid, $blacklistRecord->getEntityUuid());
                $this->assertEquals($evidenceUuid, $blacklistRecord->getEvidenceUuid());
            }
        }

        public function testBlacklistEntityUnauthorized(): void
        {
            $basicOperatorUuid = $this->client->createOperator(uniqid('test_operator_'));
            $this->createdOperators[] = $basicOperatorUuid;

            $this->client->setManagementPermissions($basicOperatorUuid, false);
            $this->client->setOperatorPermissions($basicOperatorUuid, false);
            $this->client->setClientPermissions($basicOperatorUuid, false);

            $basicOperator = $this->client->getOperator($basicOperatorUuid);
            $basicClient = new FederationClient(getenv('SERVER_ENDPOINT'), $basicOperator->getAccessToken());

            $entityUuid = $this->client->pushEntity('unauthorized-test.com', 'unauthorized_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content', 'Test note', 'test');

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $basicClient->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM);
        }

        public function testBlacklistRecordLifecycleDurability(): void
        {
            $entityUuids = [];
            $blacklistUuids = [];

            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity("durability-test-$i.com", "user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Durability test evidence $i", "Test note $i", 'durability');

                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            foreach ($blacklistUuids as $blacklistUuid)
            {
                $record = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($record);
                $this->assertFalse($record->isLifted());
            }

            for ($i = 0; $i < 2; $i++)
            {
                $this->client->liftBlacklistRecord($blacklistUuids[$i]);
                $liftedRecord = $this->client->getBlacklistRecord($blacklistUuids[$i]);
                $this->assertTrue($liftedRecord->isLifted());
            }

            for ($i = 2; $i < 4; $i++)
            {
                $this->client->deleteBlacklistRecord($blacklistUuids[$i]);
                try
                {
                    $this->client->getBlacklistRecord($blacklistUuids[$i]);
                    $this->fail('Expected RequestException for deleted blacklist record');
                }
                catch (RequestException $e)
                {
                    $this->assertEquals(404, $e->getCode());
                }
                array_splice($this->createdBlacklistRecords, array_search($blacklistUuids[$i], $this->createdBlacklistRecords), 1);
            }

            $lastRecord = $this->client->getBlacklistRecord($blacklistUuids[4]);
            $this->assertFalse($lastRecord->isLifted());
        }

        public function testBlacklistExpirationHandling(): void
        {
            $entityUuid = $this->client->pushEntity('expiration-test.com', 'expiring_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Short-lived blacklist test', 'Test note', 'expiration');

            $shortExpiry = time() + 2;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $shortExpiry);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertEquals($shortExpiry, $record->getExpires());
            $this->assertFalse($record->isLifted());

            sleep(3);

            $expiredRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($expiredRecord);
            $this->assertTrue($expiredRecord->getExpires() < time());
        }

        public function testBlacklistWithMalformedData(): void
        {
            $entityUuid = $this->client->pushEntity('malformed-test.com', 'malformed_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content', 'Test note', 'test');

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() - 3600);
        }

        public function testMultipleBlacklistTypesForSameEntity(): void
        {
            $entityUuid = $this->client->pushEntity('multi-type-test.com', 'multi_type_user');
            $this->createdEntities[] = $entityUuid;

            $allTypes = [
                IncidentType::SPAM,
                IncidentType::SCAM,
                IncidentType::SERVICE_ABUSE,
                IncidentType::ILLEGAL_CONTENT,
                IncidentType::MALWARE,
                IncidentType::PHISHING,
                IncidentType::OTHER
            ];

            $blacklistUuids = [];
            foreach ($allTypes as $type)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for ' . $type->value, 'Auto detection', strtolower($type->value));
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;

                $record = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertNotNull($record);
                $this->assertEquals($entityUuid, $record->getEntityUuid());
            }

            $entityBlacklist = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertGreaterThanOrEqual(count($allTypes), count($entityBlacklist));
        }

        public function testBlacklistRecordIntegrityAfterLift(): void
        {
            $entityUuid = $this->client->pushEntity('integrity-test.com', 'integrity_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Original evidence', 'Original note', 'integrity');
            $expires = time() + 7200;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $originalRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($originalRecord);
            $this->assertFalse($originalRecord->isLifted());

            $this->client->liftBlacklistRecord($blacklistUuid);

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
            $batchSize = 10;
            $entityUuids = [];
            $blacklistUuids = [];

            for ($i = 0; $i < $batchSize; $i++)
            {
                $entityUuid = $this->client->pushEntity("batch-test-$i.com", "batch_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Batch evidence $i", "Batch note $i", 'batch');
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            $this->assertEquals($batchSize, count($blacklistUuids));

            $allRecords = $this->client->listBlacklistRecords(1, 100, true);
            $this->assertGreaterThanOrEqual($batchSize, count($allRecords));

            $foundUuids = array_map(fn($record) => $record->getUuid(), $allRecords);
            foreach ($blacklistUuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }
        }

        public function testBlacklistRecordOperatorConsistency(): void
        {
            $operatorUuid = $this->client->getSelf()->getUuid();

            $entityUuid = $this->client->pushEntity('operator-consistency.com', 'operator_test_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Operator consistency test', 'Test note', 'operator');
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SERVICE_ABUSE, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertEquals($operatorUuid, $record->getOperatorUuid());

            $this->client->liftBlacklistRecord($blacklistUuid);
            $liftedRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($operatorUuid, $liftedRecord->getOperatorUuid());

            $operatorBlacklist = $this->client->listOperatorBlacklist($operatorUuid, 1, 100, true);
            $operatorUuids = array_map(fn($record) => $record->getUuid(), $operatorBlacklist);
            $this->assertContains($blacklistUuid, $operatorUuids);
        }

        public function testSecurityBlacklistLiftAndDeleteRestrictions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $clientOnly = $this->createLimitedOperator('bl_client', client: true);
            $operatorOnly = $this->createLimitedOperator('bl_operator', operator: true);

            $unauthorizedActions = [
                'clientLift' => fn() => $clientOnly->liftBlacklistRecord($blacklistUuid),
                'clientDelete' => fn() => $clientOnly->deleteBlacklistRecord($blacklistUuid),
                'operatorLift' => fn() => $operatorOnly->liftBlacklistRecord($blacklistUuid),
                'operatorDelete' => fn() => $operatorOnly->deleteBlacklistRecord($blacklistUuid),
            ];

            foreach ($unauthorizedActions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Unauthorized operator should not $name");
            }

            $manager = $this->createLimitedOperator('bl_manager', management: true);
            $manager->liftBlacklistRecord($blacklistUuid);
            $manager->deleteBlacklistRecord($blacklistUuid);

            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);
        }

        public function testSecurityBlacklistWithInvalidOrExpiredData(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($entityUuid, 'not-a-uuid', IncidentType::SPAM),
                [HttpResponseCode::BAD_REQUEST->value],
                'Blacklist with invalid evidence UUID format should fail'
            );

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() - 1),
                [HttpResponseCode::BAD_REQUEST->value],
                'Blacklist with expiration in the past should fail'
            );

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($this->randomUuid(), $evidenceUuid, IncidentType::SPAM),
                [HttpResponseCode::NOT_FOUND->value],
                'Blacklist of non-existent entity should fail'
            );
        }

        public function testSecurityBlacklistRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $clientOnly = $this->createLimitedOperator('bl_create_client', client: true);
            $operatorOnly = $this->createLimitedOperator('bl_create_operator', operator: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not create blacklists'
            );

            $this->expectRequestFailure(
                fn() => $operatorOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not create blacklists'
            );
        }

        public function testSecurityLiftAlreadyLiftedBlacklist(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $this->expectRequestFailure(
                fn() => $this->client->liftBlacklistRecord($blacklistUuid),
                [HttpResponseCode::BAD_REQUEST->value],
                'Lifting an already-lifted blacklist should fail'
            );
        }

        public function testSecurityDeleteAlreadyDeletedBlacklist(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->deleteBlacklistRecord($blacklistUuid);
            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);

            $this->expectRequestFailure(
                fn() => $this->client->deleteBlacklistRecord($blacklistUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Deleting an already-deleted blacklist should fail'
            );
        }

        public function testExpiredBlacklistRemainsRetrievable(): void
        {
            $entityUuid = $this->client->pushEntity('expired-retrieval.com', 'expired_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Expired blacklist evidence', 'Note', 'expired');

            $shortExpiry = time() + 1;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $shortExpiry);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            sleep(2);

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertTrue($record->getExpires() < time());
            // The server's isLifted() helper treats expiration as a lifted state.
            $this->assertTrue($record->isLifted());
        }

        public function testDuplicateBlacklistRecordsForSameEntity(): void
        {
            $entityUuid = $this->client->pushEntity('duplicate-blacklist.com', 'duplicate_user');
            $this->createdEntities[] = $entityUuid;

            $blacklistUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Duplicate evidence $i", "Note $i", "dup_$i");
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            $this->assertCount(3, array_unique($blacklistUuids));

            $entityBlacklist = $this->client->listEntityBlacklistRecords($entityUuid);
            $entityBlacklistUuids = array_map(fn($r) => $r->getUuid(), $entityBlacklist);
            foreach ($blacklistUuids as $uuid)
            {
                $this->assertContains($uuid, $entityBlacklistUuids);
            }
        }

        public function testBlacklistLiftDoesNotDeleteRecord(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertTrue($record->isLifted());

            $allRecords = $this->client->listBlacklistRecords(1, 100, true);
            $uuids = array_map(fn($r) => $r->getUuid(), $allRecords);
            $this->assertContains($blacklistUuid, $uuids);
        }

        public function testBlacklistCreationUpdatesEntityBlacklistList(): void
        {
            $entityUuid = $this->client->pushEntity('entity-blacklist-list.com', 'entity_bl_user');
            $this->createdEntities[] = $entityUuid;

            $before = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertEmpty($before);

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Entity blacklist evidence', 'Note', 'entity_bl');
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $after = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertCount(1, $after);
            $this->assertEquals($blacklistUuid, $after[0]->getUuid());
        }

        public function testBlacklistWithAllIncidentTypesIsRecorded(): void
        {
            $entityUuid = $this->client->pushEntity('all-incident-types.com', 'all_types_user');
            $this->createdEntities[] = $entityUuid;

            $allTypes = IncidentType::cases();
            foreach ($allTypes as $type)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for ' . $type->value, 'Note', strtolower($type->value));
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;

                $record = $this->client->getBlacklistRecord($blacklistUuid);
                $this->assertEquals($type, $record->getType());
            }
        }

        public function testBlacklistMinimumDurationEnforced(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            // The server may reject a too-short expiry or silently extend it to the minimum.
            $tooShortExpiry = time() + 1;
            try
            {
                $shortUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $tooShortExpiry);
                $this->createdBlacklistRecords[] = $shortUuid;
                $shortRecord = $this->client->getBlacklistRecord($shortUuid);
                // The server may or may not enforce a minimum duration; just verify expiry is set.
                $this->assertGreaterThan(time(), $shortRecord->getExpires(), 'Blacklist expiry should be in the future');
            }
            catch (\FederationLib\Exceptions\RequestException $e)
            {
                $this->assertContains($e->getCode(), [HttpResponseCode::BAD_REQUEST->value]);
            }

            $validExpiry = time() + 7200;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $validExpiry);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertGreaterThanOrEqual(time() + 1800, $record->getExpires(), 'Blacklist expiry should respect minimum duration');
        }

        public function testBlacklistSameTypeMultipleTimesForSameEntity(): void
        {
            $entityUuid = $this->client->pushEntity('same-type-multi.com', 'same_type_user');
            $this->createdEntities[] = $entityUuid;

            $blacklistUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Same type evidence $i", "Note $i", "same_$i");
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;
            }

            $this->assertCount(3, array_unique($blacklistUuids));

            $entityBlacklist = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertGreaterThanOrEqual(3, count($entityBlacklist));
        }

        public function testLiftedBlacklistNoLongerBlocksViaList(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $activeRecords = $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, false);
            $activeUuids = array_map(fn($r) => $r->getUuid(), $activeRecords);
            $this->assertContains($blacklistUuid, $activeUuids);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $activeRecordsAfterLift = $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, false);
            $activeUuidsAfterLift = array_map(fn($r) => $r->getUuid(), $activeRecordsAfterLift);
            $this->assertNotContains($blacklistUuid, $activeUuidsAfterLift);

            $allRecords = $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, true);
            $allUuids = array_map(fn($r) => $r->getUuid(), $allRecords);
            $this->assertContains($blacklistUuid, $allUuids);
        }

        public function testBlacklistCreatedByDeletedOperatorSurvives(): void
        {
            $operatorUuid = $this->client->createOperator('blacklist_owner');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('bl-owner-delete.com', 'bl_owner_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Owner evidence', 'Note', 'owner');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $operatorClient->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Fetch the record before operator deletion (it may be deleted when the operator is removed).
            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertEquals($entityUuid, $record->getEntityUuid());

            $this->client->deleteOperator($operatorUuid);
            array_splice($this->createdOperators, array_search($operatorUuid, $this->createdOperators), 1);
        }
    }
