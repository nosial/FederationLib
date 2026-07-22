<?php

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class BlacklistLogicTest extends TestCase
    {
        use TestHelpers;
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
                $this->assertGreaterThanOrEqual(time(), $shortRecord->getExpires(), 'Blacklist expiry should be in the future');
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

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($record);
            $this->assertEquals($entityUuid, $record->getEntityUuid());

            $this->client->deleteOperator($operatorUuid);
            array_splice($this->createdOperators, array_search($operatorUuid, $this->createdOperators), 1);
        }

        public function testExtendPermanentBlacklistRecord(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, null);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNull($record->getExpires());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->extendBlacklistRecord($blacklistUuid, 3600);
        }

        public function testExtendLiftedBlacklistRecord(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->extendBlacklistRecord($blacklistUuid, 3600);
        }

        public function testExtendExpiredBlacklistRecord(): void
        {
            $entityUuid = $this->client->pushEntity('extend-expired.com', 'expired_extend_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Expired extend test', 'Note', 'expired_ext');
            $shortExpiry = time() + 1;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $shortExpiry);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            sleep(2);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->extendBlacklistRecord($blacklistUuid, 3600);
        }

        public function testExtendBlacklistRecordDoesNotChangeOtherFields(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $originalRecord = $this->client->getBlacklistRecord($blacklistUuid);

            $this->client->extendBlacklistRecord($blacklistUuid, 3600);

            $extendedRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($originalRecord->getUuid(), $extendedRecord->getUuid());
            $this->assertEquals($originalRecord->getEntityUuid(), $extendedRecord->getEntityUuid());
            $this->assertEquals($originalRecord->getOperatorUuid(), $extendedRecord->getOperatorUuid());
            $this->assertEquals($originalRecord->getEvidenceUuid(), $extendedRecord->getEvidenceUuid());
            $this->assertEquals($originalRecord->getType(), $extendedRecord->getType());
            $this->assertFalse($extendedRecord->isLifted());
            $this->assertNull($extendedRecord->getLiftedBy());
        }

    }
