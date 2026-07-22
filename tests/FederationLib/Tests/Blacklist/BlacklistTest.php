<?php

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class BlacklistTest extends TestCase
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

        public function testListBlacklistRecordsSortByCreatedAscending(): void
        {
            $entityUuid = $this->client->pushEntity('blacklist-sort-asc.com', 'bl_sort_asc_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Blacklist sort ASC evidence', 'Note', 'bl_sort_asc');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600 * ($i + 1));
                $this->createdBlacklistRecords[] = $uuid;
                $blacklistUuids[] = $uuid;
            }

            $records = $this->client->listBlacklistRecords(1, 100, true, null, 'created', 'ASC');
            $filtered = array_values(array_filter($records, fn($r) => in_array($r->getUuid(), $blacklistUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals($blacklistUuids[0], $filtered[0]->getUuid());
            $this->assertEquals($blacklistUuids[1], $filtered[1]->getUuid());
            $this->assertEquals($blacklistUuids[2], $filtered[2]->getUuid());
        }

        public function testListBlacklistRecordsSortByTypeDescending(): void
        {
            $entityUuid = $this->client->pushEntity('blacklist-sort-type.com', 'bl_sort_type_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Blacklist sort type evidence', 'Note', 'bl_sort_type');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $types = [IncidentType::SPAM, IncidentType::PHISHING, IncidentType::MALWARE];
            $blacklistUuids = [];

            foreach ($types as $type)
            {
                $uuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 3600);
                $this->createdBlacklistRecords[] = $uuid;
                $blacklistUuids[] = $uuid;
            }

            $records = $this->client->listBlacklistRecords(1, 100, true, null, 'type', 'DESC');
            $filtered = array_values(array_filter($records, fn($r) => in_array($r->getUuid(), $blacklistUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals('PHISHING', $filtered[0]->getType()->value);
            $this->assertEquals('MALWARE', $filtered[1]->getType()->value);
            $this->assertEquals('SPAM', $filtered[2]->getType()->value);
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

        public function testExtendBlacklistRecord(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $originalRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $originalExpires = $originalRecord->getExpires();

            $extension = 3600;
            $this->client->extendBlacklistRecord($blacklistUuid, $extension);

            $extendedRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($extendedRecord);
            $this->assertFalse($extendedRecord->isLifted());
            $this->assertGreaterThan($originalExpires, $extendedRecord->getExpires());
            $this->assertEquals($originalExpires + $extension, $extendedRecord->getExpires());
        }

        public function testExtendBlacklistRecordInvalidUuid(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Blacklist record UUID cannot be empty');
            $this->client->extendBlacklistRecord('', 3600);
        }

        public function testExtendBlacklistRecordInvalidSeconds(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Extension seconds must be positive');
            $this->client->extendBlacklistRecord($this->randomUuid(), 0);
        }

        public function testExtendBlacklistRecordNegativeSeconds(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Extension seconds must be positive');
            $this->client->extendBlacklistRecord($this->randomUuid(), -1);
        }

        public function testExtendNonExistentBlacklistRecord(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->extendBlacklistRecord($this->randomUuid(), 3600);
        }

        public function testExtendBlacklistRecordMultipleTimes(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $originalRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $originalExpires = $originalRecord->getExpires();

            $this->client->extendBlacklistRecord($blacklistUuid, 1800);
            $firstExtendRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($originalExpires + 1800, $firstExtendRecord->getExpires());

            $this->client->extendBlacklistRecord($blacklistUuid, 3600);
            $secondExtendRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($originalExpires + 1800 + 3600, $secondExtendRecord->getExpires());
        }

        public function testExtendBlacklistRecordAndVerifyListContains(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->extendBlacklistRecord($blacklistUuid, 7200);

            $allRecords = $this->client->listBlacklistRecords(1, 100, true);
            $uuids = array_map(fn($r) => $r->getUuid(), $allRecords);
            $this->assertContains($blacklistUuid, $uuids);

            $entityRecords = $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, true);
            $entityUuids = array_map(fn($r) => $r->getUuid(), $entityRecords);
            $this->assertContains($blacklistUuid, $entityUuids);
        }

        public function testListBlacklistCategoryActive(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-active.com', 'bl_active_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Active bl cat', 'Note', 'bl_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $activeUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $activeUuid;

            $records = $this->client->listBlacklistRecords(1, 100, true, 'ACTIVE');
            $uuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertContains($activeUuid, $uuids);

            foreach ($records as $r)
            {
                $this->assertFalse($r->isLifted());
            }
        }

        public function testListBlacklistCategoryLifted(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-lifted.com', 'bl_lifted_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Lifted bl cat', 'Note', 'bl_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $liftedUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $liftedUuid;

            $this->client->liftBlacklistRecord($liftedUuid);

            $records = $this->client->listBlacklistRecords(1, 100, true, 'LIFTED');
            $uuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertContains($liftedUuid, $uuids);

            foreach ($records as $r)
            {
                $this->assertTrue($r->isLifted());
            }
        }

        public function testListBlacklistCategoryPermanent(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-perm.com', 'bl_perm_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Permanent bl cat', 'Note', 'bl_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $permUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, null);
            $this->createdBlacklistRecords[] = $permUuid;

            $records = $this->client->listBlacklistRecords(1, 100, true, 'PERMANENT');
            $uuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertContains($permUuid, $uuids);

            foreach ($records as $r)
            {
                $this->assertNull($r->getExpires());
                $this->assertFalse($r->isLifted());
            }
        }

        public function testListBlacklistCategoryExpired(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-expired.com', 'bl_exp_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Expired bl cat', 'Note', 'bl_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $expiredUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 1);
            $this->createdBlacklistRecords[] = $expiredUuid;

            sleep(2);

            $records = $this->client->listBlacklistRecords(1, 100, true, 'EXPIRED');
            $uuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertContains($expiredUuid, $uuids);

            foreach ($records as $r)
            {
                $this->assertTrue($r->getExpires() < time());
                $this->assertTrue($r->isLifted());
            }
        }

        public function testListBlacklistCategoryWithSort(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-sort.com', 'bl_cat_sort_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'BL cat sort evidence', 'Note', 'bl_cat_sort');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $types = [IncidentType::MALWARE, IncidentType::SPAM, IncidentType::PHISHING];
            $uuids = [];
            foreach ($types as $type)
            {
                $uuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $type, time() + 7200);
                $this->createdBlacklistRecords[] = $uuid;
                $uuids[] = $uuid;
            }

            $records = $this->client->listBlacklistRecords(1, 100, true, 'ACTIVE', 'type', 'DESC');
            $filtered = array_values(array_filter($records, fn($r) => in_array($r->getUuid(), $uuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals('PHISHING', $filtered[0]->getType()->value);
            $this->assertEquals('MALWARE', $filtered[1]->getType()->value);
            $this->assertEquals('SPAM', $filtered[2]->getType()->value);
        }

        public function testListBlacklistCategoryInvalidFallsBack(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-invalid.com', 'bl_cat_inv_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'BL cat invalid', 'Note', 'bl_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $uuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $uuid;

            $resultDefault = $this->client->listBlacklistRecords(1, 10, true);
            $resultInvalid = $this->client->listBlacklistRecords(1, 10, true, 'BOGUS_CATEGORY');

            $defaultUuids = array_map(fn($r) => $r->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($r) => $r->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListBlacklistCategoryCaseInsensitive(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-ci.com', 'bl_ci_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'BL CI test', 'Note', 'bl_ci');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $uuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $uuid;

            $resultUpper = $this->client->listBlacklistRecords(1, 10, true, 'ACTIVE');
            $resultLower = $this->client->listBlacklistRecords(1, 10, true, 'active');
            $resultMixed = $this->client->listBlacklistRecords(1, 10, true, 'Active');

            $upperUuids = array_map(fn($r) => $r->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($r) => $r->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($r) => $r->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
        }

        public function testListBlacklistCategoryActiveExcludesLifted(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-active-excl.com', 'bl_act_excl_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Active excl evidence', 'Note', 'bl_act_excl');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $activeUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $activeUuid;

            $this->client->liftBlacklistRecord($activeUuid);

            $records = $this->client->listBlacklistRecords(1, 100, true, 'ACTIVE');
            $uuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertNotContains($activeUuid, $uuids, 'Lifted blacklist should not appear in ACTIVE filter');
        }

        public function testListBlacklistCategoryLiftedExcludesActive(): void
        {
            $entityUuid = $this->client->pushEntity('bl-cat-lifted-excl.com', 'bl_lift_excl_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Lifted excl evidence', 'Note', 'bl_lift_excl');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $activeUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $activeUuid;

            $records = $this->client->listBlacklistRecords(1, 100, true, 'LIFTED');
            $uuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertNotContains($activeUuid, $uuids, 'Active (non-lifted) blacklist should not appear in LIFTED filter');
        }

    }
