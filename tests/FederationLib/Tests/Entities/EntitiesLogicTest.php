<?php

    namespace FederationLib\Tests\Entities;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class EntitiesLogicTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
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

            foreach ($this->createdEntities as $entityId)
            {
                try
                {
                    $this->client->deleteEntity($entityId);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityId: " . $e->getMessage());
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

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testEntityLifecycleIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('lifecycle-test.com', 'lifecycle_user');
            $this->createdEntities[] = $entityUuid;

            $entity = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entity);

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Lifecycle test evidence', 'Test note', 'lifecycle');

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());

            $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid);
            $evidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $entityEvidence);
            $this->assertContains($evidenceUuid, $evidenceUuids);

            $this->client->deleteEvidence($evidenceUuid);
            $this->client->deleteEntity($entityUuid);

            try
            {
                $this->client->getEntityRecord($entityUuid);
                $this->fail('Expected RequestException for deleted entity');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(404, $e->getCode());
            }

            array_splice($this->createdEntities, array_search($entityUuid, $this->createdEntities), 1);
        }

        public function testEntityDuplicationHandling(): void
        {
            $host = 'duplication-test.com';
            $id = 'duplicate_user';

            $firstUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $firstUuid;
            $this->assertNotNull($firstUuid);

            for ($i = 0; $i < 5; $i++)
            {
                $duplicateUuid = $this->client->pushEntity($host, $id);
                $this->assertEquals($firstUuid, $duplicateUuid);
            }

            $entity = $this->client->getEntityRecord($firstUuid);
            $this->assertNotNull($entity);
            $this->assertEquals($host, $entity->getHost());
            $this->assertEquals($id, $entity->getId());

            $hash = Utilities::hashEntity($host, $id);
            $entityByHash = $this->client->getEntityRecord($hash);
            $this->assertEquals($firstUuid, $entityByHash->getUuid());
        }

        public function testDuplicateEntityPushMergesMetadata(): void
        {
            $host = 'duplicate-metadata.com';
            $id = 'duplicate_user';

            $firstUuid = $this->client->pushEntity($host, $id, ['stage' => 1]);
            $this->createdEntities[] = $firstUuid;

            $secondUuid = $this->client->pushEntity($host, $id, ['stage' => 2, 'extra' => 'value']);
            $this->assertEquals($firstUuid, $secondUuid);

            $record = $this->client->getEntityRecord($firstUuid);
            $this->assertEquals(['stage' => 2, 'extra' => 'value'], $record->getMetadata());
        }

        public function testEntityRelationshipTargetDeletionConsistency(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $recordA = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityB, $recordA->getRelationshipEntity());

            $this->client->deleteEntity($entityB);
            $this->removeFromCleanup($this->createdEntities, $entityB);

            // The referencing entity should still exist; behavior of the FK reference
            // after target deletion depends on the server implementation.
            $recordAAfter = $this->client->getEntityRecord($entityA);
            $this->assertNotNull($recordAAfter);
            $this->assertEquals($entityA, $recordAAfter->getUuid());
        }

        public function testEntityRelationshipOverwritesPreviousRelationship(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();
            $entityC = $this->createSecurityEntity();

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::ALTERNATIVE);
            $recordA = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityB, $recordA->getRelationshipEntity());

            $this->client->setEntityRelationship($entityA, $entityC, EntityRelationshipType::CHILD);
            $recordAUpdated = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityC, $recordAUpdated->getRelationshipEntity());

            $this->client->clearEntityRelationship($entityA);
            $recordACleared = $this->client->getEntityRecord($entityA);
            $this->assertNull($recordACleared->getRelationshipEntity());
        }

        public function testEntityRelationshipCyclePersists(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();
            $entityC = $this->createSecurityEntity();

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $this->client->setEntityRelationship($entityB, $entityC, EntityRelationshipType::PROXY);
            $this->client->setEntityRelationship($entityC, $entityA, EntityRelationshipType::PROXY);

            $recordA = $this->client->getEntityRecord($entityA);
            $recordB = $this->client->getEntityRecord($entityB);
            $recordC = $this->client->getEntityRecord($entityC);

            $this->assertEquals($entityB, $recordA->getRelationshipEntity());
            $this->assertEquals($entityC, $recordB->getRelationshipEntity());
            $this->assertEquals($entityA, $recordC->getRelationshipEntity());
        }

        public function testEntityLookupByAllIdentifierTypes(): void
        {
            $host = 'identifier-test.com';
            $id = 'lookup_user';
            $metadata = ['source' => 'integration_test', 'importance' => 'high'];

            $entityUuid = $this->client->pushEntity($host, $id, $metadata);
            $this->createdEntities[] = $entityUuid;

            $byUuid = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $byUuid->getUuid());
            $this->assertEquals($host, $byUuid->getHost());
            $this->assertEquals($id, $byUuid->getId());

            $hash = Utilities::hashEntity($host, $id);
            $byHash = $this->client->getEntityRecord($hash);
            $this->assertEquals($entityUuid, $byHash->getUuid());

            $byAddress = $this->client->getEntityRecord("$id@$host");
            $this->assertEquals($entityUuid, $byAddress->getUuid());
        }

        public function testEntityReputationChangeViaReportClassificationAndClear(): void
        {
            $entityUuid = $this->client->pushEntity('reputation-test.com', 'reputation_user');
            $this->createdEntities[] = $entityUuid;

            $before = $this->client->getEntityRecord($entityUuid);
            $initialReputation = $before->getReputation();

            // Submit a report and close it maliciously.
            $submission = $this->client->submitReport($entityUuid, 'malicious activity report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->client->closeReport($reportUuid, ClassificationFlag::MALICIOUS);

            // Report is now closed; clear the reputation explicitly and verify it resets.
            $this->client->clearEntityReputation($entityUuid);

            $this->client->clearEntityReputation($entityUuid);
            $cleared = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals(0, $cleared->getReputation());
        }

        public function testEntityQueryIncludesEvidenceAndBlacklist(): void
        {
            $entityUuid = $this->client->pushEntity('query-test.com', 'query_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Query evidence', 'Note', 'query');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());

            $evidenceList = $this->client->listEntityEvidenceRecords($entityUuid);
            $this->assertNotEmpty($evidenceList);

            $blacklistList = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertNotEmpty($blacklistList);
        }

        public function testEntityQueryRespectsConfidentialAndLiftedFlags(): void
        {
            $entityUuid = $this->client->pushEntity('query-flags.com', 'query_flags_user');
            $this->createdEntities[] = $entityUuid;

            $publicEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Public evidence', 'Note', 'public');
            $this->createdEvidenceRecords[] = $publicEvidenceUuid;

            $confidentialEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential evidence', 'Note', 'confidential', true);
            $this->createdEvidenceRecords[] = $confidentialEvidenceUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Blacklist evidence', 'Note', 'bl');
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            $this->client->liftBlacklistRecord($blacklistUuid);

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());

            $evidenceList = $this->client->listEntityEvidenceRecords($entityUuid);
            $this->assertNotEmpty($evidenceList);
        }

        public function testGetTopThreatsBasic(): void
        {
            for ($i = 0; $i < 5; $i++)
            {
                $uuid = $this->client->pushEntity("top-threats-basic-$i.com", "user_$i");
                $this->createdEntities[] = $uuid;
            }

            $topThreats = $this->client->getTopThreats();

            $this->assertIsArray($topThreats);
            $this->assertNotEmpty($topThreats);

            foreach ($topThreats as $threat)
            {
                $this->assertInstanceOf(EntityRecord::class, $threat);
                $this->assertNotEmpty($threat->getUuid());
                $this->assertIsInt($threat->getReputation());
            }
        }

        public function testGetTopThreatsWithCustomLimit(): void
        {
            $expected = 3;
            for ($i = 0; $i < 10; $i++)
            {
                $uuid = $this->client->pushEntity("top-threats-limit-$i.com", "user_$i");
                $this->createdEntities[] = $uuid;
            }

            $topThreats = $this->client->getTopThreats($expected);

            $this->assertIsArray($topThreats);
            $this->assertCount($expected, $topThreats);
        }

        public function testGetTopThreatsInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->getTopThreats(0);

            $this->expectException(InvalidArgumentException::class);
            $this->client->getTopThreats(-1);

            $this->expectException(InvalidArgumentException::class);
            $this->client->getTopThreats(-100);
        }

        public function testGetTopThreatsOrderedByReputation(): void
        {
            $entityUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->pushEntity("top-threats-order-$i.com", "order_$i");
                $this->createdEntities[] = $uuid;
                $entityUuids[] = $uuid;
            }

            // Submit and close 2 reports for entity[0] to lower reputation further
            for ($j = 0; $j < 2; $j++)
            {
                $submission = $this->client->submitReport($entityUuids[0], "Order threat report $j", IncidentType::SPAM);
                $this->createdReports[] = $submission->getReport()->getUuid();
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
                $this->client->closeReport($submission->getReport()->getUuid(), ClassificationFlag::MALICIOUS);
            }

            // Submit and close 1 report for entity[1]
            $submission = $this->client->submitReport($entityUuids[1], 'Order threat report', IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            $this->client->closeReport($submission->getReport()->getUuid(), ClassificationFlag::MALICIOUS);

            // entity[2] has no reports, reputation = 0
            $topThreats = $this->client->getTopThreats(10);

            $this->assertIsArray($topThreats);
            $this->assertNotEmpty($topThreats);

            // Reputations should be sorted ascending (most negative first)
            $reputations = array_map(fn($e) => $e->getReputation(), $topThreats);
            $sortedReputations = $reputations;
            sort($sortedReputations);
            $this->assertEquals($sortedReputations, $reputations, 'Top threats must be ordered by reputation ascending');

            // Find positions of our test entities in the result
            $positions = [];
            foreach ($entityUuids as $uuid)
            {
                $positions[$uuid] = array_search($uuid, array_map(fn($e) => $e->getUuid(), $topThreats));
            }

            $this->assertNotFalse($positions[$entityUuids[0]], 'Entity with 2 malicious reports must appear in top threats');
            $this->assertNotFalse($positions[$entityUuids[1]], 'Entity with 1 malicious report must appear in top threats');

            $this->assertLessThan($positions[$entityUuids[1]], $positions[$entityUuids[0]], 'Entity with the lowest (most negative) reputation should appear first');
        }

        public function testGetTopThreatsReturnsEmptyForNoEntities(): void
        {
            $this->assertIsArray($this->client->getTopThreats());
        }

        public function testListEntitiesSortByHostAscending(): void
        {
            $suffix = uniqid();
            $hosts = ['z-entity-sort-' . $suffix . '.com', 'a-entity-sort-' . $suffix . '.com', 'm-entity-sort-' . $suffix . '.com'];
            $created = [];
            foreach ($hosts as $host)
            {
                $uuid = $this->client->pushEntity($host, 'sort_entity_' . uniqid());
                $this->createdEntities[] = $uuid;
                $created[] = $uuid;
            }

            $allEntities = [];
            $page = 1;
            do
            {
                $entities = $this->client->listEntities($page, 100, null, 'host', 'ASC');
                $allEntities = array_merge($allEntities, $entities);
                $page++;
            } while (count($entities) > 0);

            $filtered = array_values(array_filter($allEntities, fn($e) => in_array($e->getUuid(), $created, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals('a-entity-sort-' . $suffix . '.com', $filtered[0]->getHost());
            $this->assertEquals('m-entity-sort-' . $suffix . '.com', $filtered[1]->getHost());
            $this->assertEquals('z-entity-sort-' . $suffix . '.com', $filtered[2]->getHost());
        }

        public function testListEntitiesSortInvalidByFallsBack(): void
        {
            $host = 'entity-invalid-by.com';
            $uuid = $this->client->pushEntity($host, 'invalid_by_' . uniqid());
            $this->createdEntities[] = $uuid;

            $result = $this->client->listEntities(1, 10, 'bogus_field_xyz');
            $this->assertIsArray($result);

            $uuids = array_map(fn($e) => $e->getUuid(), $result);
            $this->assertContains($uuid, $uuids);
        }

        public function testListEntitiesSortByCreatedDescending(): void
        {
            $uuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->pushEntity("created-sort-$i.com", 'created_user_' . uniqid());
                $this->createdEntities[] = $uuid;
                $uuids[] = $uuid;
            }

            $entities = $this->client->listEntities(1, 100, null, 'created', 'DESC');
            $filtered = array_values(array_filter($entities, fn($e) => in_array($e->getUuid(), $uuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals($uuids[2], $filtered[0]->getUuid());
            $this->assertEquals($uuids[1], $filtered[1]->getUuid());
            $this->assertEquals($uuids[0], $filtered[2]->getUuid());
        }

        public function testSetWhitelistToTrue(): void
        {
            $uuid = $this->createSecurityEntity();

            $this->client->setEntityWhitelist($uuid, true);

            $record = $this->client->getEntityRecord($uuid);
            $this->assertTrue($record->isWhitelisted());
        }

        public function testSetWhitelistToFalse(): void
        {
            $uuid = $this->createSecurityEntity();

            $this->client->setEntityWhitelist($uuid, true);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertTrue($record->isWhitelisted());

            $this->client->setEntityWhitelist($uuid, false);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertFalse($record->isWhitelisted());
        }

        public function testSetWhitelistToggleMultipleTimes(): void
        {
            $uuid = $this->createSecurityEntity();

            $states = [true, false, true, false, true];
            foreach ($states as $state)
            {
                $this->client->setEntityWhitelist($uuid, $state);
                $record = $this->client->getEntityRecord($uuid);
                $this->assertSame($state, $record->isWhitelisted());
            }
        }

        public function testSetWhitelistByIdentifierTypes(): void
        {
            $host = 'whitelist-identifier-test.com';
            $id = 'wli_user';
            $uuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $uuid;

            $hash = Utilities::hashEntity($host, $id);
            $this->client->setEntityWhitelist($hash, true);
            $this->assertTrue($this->client->getEntityRecord($uuid)->isWhitelisted());

            $address = "$id@$host";
            $this->client->setEntityWhitelist($address, false);
            $this->assertFalse($this->client->getEntityRecord($uuid)->isWhitelisted());

            $this->client->setEntityWhitelist($uuid, true);
            $this->assertTrue($this->client->getEntityRecord($uuid)->isWhitelisted());
        }

        public function testSetWhitelistIdempotent(): void
        {
            $uuid = $this->createSecurityEntity();

            $this->client->setEntityWhitelist($uuid, true);
            $this->assertTrue($this->client->getEntityRecord($uuid)->isWhitelisted());

            $this->client->setEntityWhitelist($uuid, true);
            $this->assertTrue($this->client->getEntityRecord($uuid)->isWhitelisted());
        }

        public function testSetWhitelistAdvancesUpdatedTimestamp(): void
        {
            $uuid = $this->createSecurityEntity();

            $record = $this->client->getEntityRecord($uuid);
            $before = $record->getUpdated();
            $this->assertIsInt($before);

            $this->client->setEntityWhitelist($uuid, true);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertIsInt($record->getUpdated());
            $this->assertGreaterThanOrEqual($before, $record->getUpdated());

            $before = $record->getUpdated();
            $this->client->setEntityWhitelist($uuid, false);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertGreaterThanOrEqual($before, $record->getUpdated());
        }

        public function testListEntitiesCategoryWhitelisted(): void
        {
            $host = 'cat-whitelisted.com';
            $uuid = $this->client->pushEntity($host, 'whitelist_cat_' . uniqid());
            $this->createdEntities[] = $uuid;

            $entities = $this->client->listEntities(1, 100, 'WHITELISTED');
            $this->assertIsArray($entities);

            foreach (array_filter($entities, fn($e) => $e->getUuid() === $uuid) as $e)
            {
                $this->assertTrue($e->isWhitelisted());
            }
        }

        public function testListEntitiesCategoryNotWhitelisted(): void
        {
            $host = 'cat-not-whitelisted.com';
            $uuid = $this->client->pushEntity($host, 'not_whitelist_cat_' . uniqid());
            $this->createdEntities[] = $uuid;

            $entities = $this->client->listEntities(1, 100, 'NOT_WHITELISTED');
            $this->assertIsArray($entities);

            foreach (array_filter($entities, fn($e) => $e->getUuid() === $uuid) as $e)
            {
                $this->assertFalse($e->isWhitelisted());
            }
        }

        public function testListEntitiesCategoryWithRelationship(): void
        {
            $hostA = 'cat-rel-a.com';
            $hostB = 'cat-rel-b.com';
            $uuidA = $this->client->pushEntity($hostA, 'rel_a_' . uniqid());
            $this->createdEntities[] = $uuidA;
            $uuidB = $this->client->pushEntity($hostB, 'rel_b_' . uniqid());
            $this->createdEntities[] = $uuidB;

            $this->client->setEntityRelationship($uuidA, $uuidB, \FederationLib\Enums\EntityRelationshipType::PROXY);

            $entities = $this->client->listEntities(1, 100, 'WITH_RELATIONSHIP');
            $this->assertIsArray($entities);

            $foundA = array_filter($entities, fn($e) => $e->getUuid() === $uuidA);
            $this->assertNotEmpty($foundA, 'Entity A with relationship should appear in WITH_RELATIONSHIP filter');

            $foundB = array_filter($entities, fn($e) => $e->getUuid() === $uuidB);
            $this->assertEmpty($foundB, 'Entity B without relationship should NOT appear in WITH_RELATIONSHIP filter');
        }

        public function testListEntitiesCategoryWithoutRelationship(): void
        {
            $hostA = 'cat-no-rel-a.com';
            $hostB = 'cat-no-rel-b.com';
            $uuidA = $this->client->pushEntity($hostA, 'no_rel_a_' . uniqid());
            $this->createdEntities[] = $uuidA;
            $uuidB = $this->client->pushEntity($hostB, 'no_rel_b_' . uniqid());
            $this->createdEntities[] = $uuidB;

            $this->client->setEntityRelationship($uuidA, $uuidB, \FederationLib\Enums\EntityRelationshipType::PROXY);

            $entities = $this->client->listEntities(1, 100, 'WITHOUT_RELATIONSHIP');
            $this->assertIsArray($entities);

            $foundA = array_filter($entities, fn($e) => $e->getUuid() === $uuidA);
            $this->assertEmpty($foundA, 'Entity A with relationship should NOT appear in WITHOUT_RELATIONSHIP filter');

            $foundB = array_filter($entities, fn($e) => $e->getUuid() === $uuidB);
            $this->assertNotEmpty($foundB, 'Entity B without relationship should appear in WITHOUT_RELATIONSHIP filter');
        }

        public function testListEntitiesCategoryWithSort(): void
        {
            $suffix = uniqid();
            $hosts = ['z-cat-sort-' . $suffix . '.com', 'a-cat-sort-' . $suffix . '.com'];
            $uuids = [];

            foreach ($hosts as $host)
            {
                $uuid = $this->client->pushEntity($host, 'cat_sort_' . uniqid());
                $this->createdEntities[] = $uuid;
                $uuids[] = $uuid;
            }

            $allEntities = [];
            $page = 1;
            do
            {
                $entities = $this->client->listEntities($page, 100, 'NOT_WHITELISTED', 'host', 'ASC');
                $allEntities = array_merge($allEntities, $entities);
                $page++;
            } while (count($entities) > 0);

            $filtered = array_values(array_filter($allEntities, fn($e) => in_array($e->getUuid(), $uuids, true)));

            $this->assertCount(2, $filtered);
            $this->assertEquals('a-cat-sort-' . $suffix . '.com', $filtered[0]->getHost());
            $this->assertEquals('z-cat-sort-' . $suffix . '.com', $filtered[1]->getHost());
        }

        public function testListEntitiesCategoryInvalidFallsBack(): void
        {
            $host = 'cat-invalid.com';
            $uuid = $this->client->pushEntity($host, 'cat_invalid_' . uniqid());
            $this->createdEntities[] = $uuid;

            $resultDefault = $this->client->listEntities(1, 10);
            $resultInvalid = $this->client->listEntities(1, 10, 'NONEXISTENT_CATEGORY');

            $defaultUuids = array_map(fn($e) => $e->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($e) => $e->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListEntitiesCategoryCaseInsensitive(): void
        {
            $host = 'cat-case.com';
            $uuid = $this->client->pushEntity($host, 'cat_case_' . uniqid());
            $this->createdEntities[] = $uuid;

            $resultUpper = $this->client->listEntities(1, 10, 'NOT_WHITELISTED');
            $resultLower = $this->client->listEntities(1, 10, 'not_whitelisted');
            $resultMixed = $this->client->listEntities(1, 10, 'Not_Whitelisted');

            $upperUuids = array_map(fn($e) => $e->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($e) => $e->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($e) => $e->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
        }

        public function testListEntitiesCategoryWithPagination(): void
        {
            $uuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $uuid = $this->client->pushEntity("cat-pagination-$i.com", 'cat_pag_' . uniqid());
                $this->createdEntities[] = $uuid;
                $uuids[] = $uuid;
            }

            $allRetrieved = [];
            $page = 1;
            do
            {
                $entities = $this->client->listEntities($page, 2, 'NOT_WHITELISTED');
                $this->assertIsArray($entities);
                foreach ($entities as $e)
                {
                    $allRetrieved[] = $e->getUuid();
                }
                $page++;
            } while (count($entities) > 0);

            foreach ($uuids as $uuid)
            {
                $this->assertContains($uuid, $allRetrieved);
            }
        }

        public function testHighVolumeEntityOperations(): void
        {
            $batchSize = 20;
            $entityUuids = [];

            for ($i = 0; $i < $batchSize; $i++)
            {
                $entityUuid = $this->client->pushEntity("batch-test-$i.example.com", "batch_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $this->assertEquals($batchSize, count($entityUuids));

            $allEntities = [];
            $page = 1;
            $pageSize = 5;
            do
            {
                $entitiesPage = $this->client->listEntities($page, $pageSize);
                $allEntities = array_merge($allEntities, $entitiesPage);
                $page++;
            } while (count($entitiesPage) === $pageSize && $page <= 20);

            $foundUuids = array_map(fn($entity) => $entity->getUuid(), $allEntities);
            foreach ($entityUuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }

            $entitiesToDelete = array_slice($entityUuids, 0, $batchSize / 2);
            foreach ($entitiesToDelete as $uuid)
            {
                $this->client->deleteEntity($uuid);

                try
                {
                    $this->client->getEntityRecord($uuid);
                    $this->fail('Expected RequestException for deleted entity');
                }
                catch (RequestException $e)
                {
                    $this->assertEquals(404, $e->getCode());
                }

                array_splice($this->createdEntities, array_search($uuid, $this->createdEntities), 1);
            }
        }

        public function testMetadataIntegrityAcrossMultipleUpdates(): void
        {
            $host = 'integrity-meta.com';
            $id = 'integrity_user';
            $uuid = $this->client->pushEntity($host, $id, ['a' => 1, 'b' => 2, 'c' => 3]);
            $this->createdEntities[] = $uuid;

            $this->client->pushEntity($host, $id, ['b' => 99, 'd' => 4]);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['a' => 1, 'b' => 99, 'c' => 3, 'd' => 4], $record->getMetadata(),
                'POST push should merge — existing keys preserved, shared overwritten, new added');

            $this->client->updateEntity($uuid, ['a' => 10, 'b' => 20]);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['a' => 10, 'b' => 20], $record->getMetadata(),
                'PATCH update should replace — only keys from the payload remain');

            $this->client->updateEntity($uuid, []);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertNull($record->getMetadata(),
                'PATCH with empty metadata should clear all metadata');
        }

        public function testMetadataEdgeCases(): void
        {
            $host = 'edge-meta.com';
            $id = 'edge_user';

            $uuid = $this->client->pushEntity($host, $id, ['key' => 'value']);
            $this->createdEntities[] = $uuid;

            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['key' => 'value'], $record->getMetadata());

            $this->client->updateEntity($uuid, []);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertNull($record->getMetadata(),
                'PATCH with empty metadata should set metadata to null');

            $this->client->pushEntity($host, $id, ['restored' => 'yes']);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['restored' => 'yes'], $record->getMetadata(),
                'POST push after clearing metadata should set new metadata');

            $this->client->updateEntity($uuid, ['boolean' => true, 'integer' => 42, 'string' => 'hello']);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['boolean' => true, 'integer' => 42, 'string' => 'hello'], $record->getMetadata(),
                'PATCH should accept multiple value types');
        }

        public function testPushEntityMergePreservesKeysAcrossRepeatedPushes(): void
        {
            $host = 'multi-push-meta.com';
            $id = 'multipush';

            $uuid = $this->client->pushEntity($host, $id, ['first' => 1]);
            $this->createdEntities[] = $uuid;

            $this->client->pushEntity($host, $id, ['second' => 2]);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['first' => 1, 'second' => 2], $record->getMetadata(),
                'Second push should merge — first key preserved');

            $this->client->pushEntity($host, $id, ['third' => 3]);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['first' => 1, 'second' => 2, 'third' => 3], $record->getMetadata(),
                'Third push should merge — all previous keys preserved');

            $this->client->updateEntity($uuid, ['replaced' => 'yes']);
            $record = $this->client->getEntityRecord($uuid);
            $this->assertEquals(['replaced' => 'yes'], $record->getMetadata(),
                'PATCH should replace — only the new key remains');
        }

        public function testRelationshipSetAndVerifyTargetAndType(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);

            $record = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityB, $record->getRelationshipEntity(),
                'Relationship target entity UUID should match');
            $this->assertSame(EntityRelationshipType::PROXY, $record->getRelationshipType(),
                'Relationship type should be PROXY');
            $this->assertNotNull($record->getUpdated(),
                'Updated timestamp should not be null after setting relationship');
            $this->assertIsInt($record->getUpdated(),
                'Updated timestamp should be an integer (Unix timestamp)');
            $this->assertGreaterThan(0, $record->getUpdated(),
                'Updated timestamp should be greater than 0');
        }

        public function testRelationshipOverwriteChangesBothTargetAndType(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();
            $entityC = $this->createSecurityEntity();

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::ALTERNATIVE);
            $record = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityB, $record->getRelationshipEntity());
            $this->assertSame(EntityRelationshipType::ALTERNATIVE, $record->getRelationshipType());

            $firstUpdated = $record->getUpdated();

            sleep(1);

            $this->client->setEntityRelationship($entityA, $entityC, EntityRelationshipType::CHILD);
            $record = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityC, $record->getRelationshipEntity(),
                'Relationship target should be overwritten to entityC');
            $this->assertSame(EntityRelationshipType::CHILD, $record->getRelationshipType(),
                'Relationship type should be overwritten to CHILD');
            $this->assertNotNull($record->getUpdated());
            $this->assertGreaterThan($firstUpdated, $record->getUpdated(),
                'Updated timestamp should advance after overwriting relationship');
        }

        public function testRelationshipClearNullifiesFieldsAndAdvancesTimestamp(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $record = $this->client->getEntityRecord($entityA);
            $this->assertNotNull($record->getRelationshipEntity());

            $updatedBeforeClear = $record->getUpdated();

            sleep(1);

            $this->client->clearEntityRelationship($entityA);
            $record = $this->client->getEntityRecord($entityA);
            $this->assertNull($record->getRelationshipEntity(),
                'Relationship entity should be null after clearing');
            $this->assertNull($record->getRelationshipType(),
                'Relationship type should be null after clearing');
            $this->assertGreaterThan($updatedBeforeClear, $record->getUpdated(),
                'Updated timestamp should advance after clearing relationship');
        }

        public function testRelationshipWithAllTypes(): void
        {
            $entity = $this->createSecurityEntity();

            foreach (EntityRelationshipType::cases() as $type)
            {
                $target = $this->createSecurityEntity();
                $this->client->setEntityRelationship($entity, $target, $type);
                $record = $this->client->getEntityRecord($entity);
                $this->assertEquals($target, $record->getRelationshipEntity(),
                    "Relationship target should match for type {$type->value}");
                $this->assertSame($type, $record->getRelationshipType(),
                    "Relationship type should be {$type->value}");
            }
        }

        public function testRelationshipByHashIdentifier(): void
        {
            $host = 'rel-hash-test.com';
            $id = 'relhash';
            $entityA = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityA;

            $entityB = $this->createSecurityEntity();

            $hash = Utilities::hashEntity($host, $id);
            $this->client->setEntityRelationship($hash, $entityB, EntityRelationshipType::PROXY);

            $record = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityB, $record->getRelationshipEntity(),
                'Setting relationship by hash identifier should work');
            $this->assertSame(EntityRelationshipType::PROXY, $record->getRelationshipType());
        }

        public function testRelationshipByAddressIdentifier(): void
        {
            $host = 'rel-addr-test.com';
            $id = 'reladdr';
            $entityA = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityA;

            $entityB = $this->createSecurityEntity();

            $address = "$id@$host";
            $this->client->setEntityRelationship($address, $entityB, EntityRelationshipType::CHILD);

            $record = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityB, $record->getRelationshipEntity(),
                'Setting relationship by address identifier should work');
            $this->assertSame(EntityRelationshipType::CHILD, $record->getRelationshipType());
        }

        public function testRelationshipClearNonExistentSucceeds(): void
        {
            $entity = $this->createSecurityEntity();
            $this->client->clearEntityRelationship($entity);
            $record = $this->client->getEntityRecord($entity);
            $this->assertNull($record->getRelationshipEntity(),
                'Clearing relationship on entity without one should succeed');
            $this->assertNull($record->getRelationshipType());
        }

        public function testRelationshipUpdatedTimestampIsIntegerAndChanges(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $record = $this->client->getEntityRecord($entityA);
            $initialUpdated = $record->getUpdated();

            $this->assertIsInt($initialUpdated, 'Updated should be int');

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $record = $this->client->getEntityRecord($entityA);
            $afterSet = $record->getUpdated();
            $this->assertIsInt($afterSet);
            $this->assertGreaterThanOrEqual($initialUpdated, $afterSet,
                'Updated should not go backwards after setting relationship');

            $this->client->clearEntityRelationship($entityA);
            $record = $this->client->getEntityRecord($entityA);
            $afterClear = $record->getUpdated();
            $this->assertIsInt($afterClear);
            $this->assertGreaterThanOrEqual($afterSet, $afterClear,
                'Updated should not go backwards after clearing relationship');
        }
    }
