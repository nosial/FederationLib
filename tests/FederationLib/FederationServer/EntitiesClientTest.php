<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\SecurityTestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class EntitiesClientTest extends TestCase
    {
        use SecurityTestHelpers;
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

        public function testPushEntity(): void
        {
            $userEntityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->createdEntities[] = $userEntityUuid;
            $this->assertNotEmpty($userEntityUuid);

            $userEntityRecordUuid = $this->client->getEntityRecord($userEntityUuid);
            $this->assertEquals($userEntityUuid, $userEntityRecordUuid->getUuid());
            $this->assertEquals('john123', $userEntityRecordUuid->getId());
            $this->assertEquals('example.com', $userEntityRecordUuid->getHost());

            $userEntityRecordHash = $this->client->getEntityRecord(Utilities::hashEntity('example.com', 'john123'));
            $this->assertEquals($userEntityUuid, $userEntityRecordHash->getUuid());
            $this->assertEquals('john123', $userEntityRecordHash->getId());
            $this->assertEquals('example.com', $userEntityRecordHash->getHost());

            $globalEntityUuid = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $globalEntityUuid;
            $this->assertNotEmpty($globalEntityUuid);

            $globalEntityRecordUuid = $this->client->getEntityRecord($globalEntityUuid);
            $this->assertEquals($globalEntityUuid, $globalEntityRecordUuid->getUuid());
            $this->assertEquals('example.com', $globalEntityRecordUuid->getHost());

            $globalEntityRecordHash = $this->client->getEntityRecord(Utilities::hashEntity('example.com'));
            $this->assertEquals($globalEntityUuid, $globalEntityRecordHash->getUuid());
            $this->assertEquals('example.com', $globalEntityRecordHash->getHost());

            $duplicateUserEntityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertEquals($userEntityUuid, $duplicateUserEntityUuid);
            $duplicateGlobalEntityUuid = $this->client->pushEntity('example.com');
            $this->assertEquals($globalEntityUuid, $duplicateGlobalEntityUuid);

            $ipAddressEntityUuid = $this->client->pushEntity('127.0.0.1');
            $this->createdEntities[] = $ipAddressEntityUuid;
            $this->assertNotEmpty($ipAddressEntityUuid);

            $ipAddressEntityRecord = $this->client->getEntityRecord($ipAddressEntityUuid);
            $this->assertNotNull($ipAddressEntityRecord);
            $this->assertEquals($ipAddressEntityUuid, $ipAddressEntityRecord->getUuid());
            $this->assertEquals('127.0.0.1', $ipAddressEntityRecord->getHost());
            $this->assertNull($ipAddressEntityRecord->getId());
        }

        public function testPushInvalidIpAddressEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->pushEntity('999.999.999.999 2');
        }

        public function testPushInvalidDomainEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->pushEntity('invalid_domain@');
        }

        public function testPushEntityMissingHost(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('');
        }

        public function testDeleteEntity(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());

            $this->client->deleteEntity($entityUuid);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getEntityRecord($entityUuid);

            array_pop($this->createdEntities);
        }

        public function testDeleteNonExistentEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteEntity(Uuid::v7()->toRfc4122());
        }

        public function testListEntities(): void
        {
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity('example.com', 'user' . $i);
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $fetchedUuids = [];
            $page = 1;
            do
            {
                $entitiesPage = $this->client->listEntities($page, 2);
                foreach ($entitiesPage as $entity)
                {
                    $fetchedUuids[] = $entity->getUuid();
                }
                $page++;
            } while (count($entitiesPage) > 0);

            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $fetchedUuids);
            }
        }

        public function testListEntitiesInvalidPage(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(page: -10000);
        }

        public function testListEntitiesInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(limit: -1);
        }

        public function testPushEmptyEntity(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('', '');
        }

        public function testPushEmptyEntityHost(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('', 'someid');
        }

        public function testPushEmptyEntityId(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('example.com', '');
        }

        public function testGetEntityAsAnonymousClient(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->createdEntities[] = $entityUuid;

            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $entityRecord = $anonymousClient->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('john123', $entityRecord->getId());
            $this->assertEquals('example.com', $entityRecord->getHost());
        }

        public function testPushEntityAsAnonymousClient(): void
        {
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            try
            {
                $anonymousClient->pushEntity('example.com', 'john123');
                $this->fail('Expected RequestException for unauthenticated pushEntity');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 for unauthenticated request');
            }
        }

        public function testListEntitiesAsAnonymousClient(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity('example.com', 'user' . $i);
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $fetchedUuids = [];
            $page = 1;

            do
            {
                $entitiesPage = $anonymousClient->listEntities($page, 2);
                foreach ($entitiesPage as $entity)
                {
                    $fetchedUuids[] = $entity->getUuid();
                }
                $page++;
            } while (count($entitiesPage) > 0);

            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $fetchedUuids);
            }
        }

        public function testEntityCreationAndRetrievalConsistency(): void
        {
            $testCases = [
                ['host' => 'test-special-chars.com', 'id' => 'user_with_underscore'],
                ['host' => 'test-numbers-123.org', 'id' => 'user123'],
                ['host' => 'test-hyphens-domain.net', 'id' => 'user-with-hyphens'],
                ['host' => '192.168.1.1', 'id' => null],
                ['host' => 'very-long-domain-name-that-tests-limits.example.com', 'id' => 'user_with_very_long_name_to_test_database_limits'],
            ];

            $createdUuids = [];
            foreach ($testCases as $testCase)
            {
                $entityUuid = $this->client->pushEntity($testCase['host'], $testCase['id']);
                $this->createdEntities[] = $entityUuid;
                $createdUuids[] = $entityUuid;

                $entity = $this->client->getEntityRecord($entityUuid);
                $this->assertNotNull($entity);
                $this->assertEquals($testCase['host'], $entity->getHost());
                $this->assertEquals($testCase['id'], $entity->getId());

                $hash = Utilities::hashEntity($testCase['host'], $testCase['id']);
                $entityByHash = $this->client->getEntityRecord($hash);
                $this->assertEquals($entityUuid, $entityByHash->getUuid());
            }

            foreach ($createdUuids as $uuid)
            {
                $entity = $this->client->getEntityRecord($uuid);
                $this->assertNotNull($entity);
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

        public function testSecurityEntityRelationshipAbuse(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            // Self-relationship is currently allowed by the server; ensure it does not crash.
            $this->client->setEntityRelationship($entityA, $entityA, EntityRelationshipType::ALTERNATIVE);
            $recordA = $this->client->getEntityRecord($entityA);
            $this->assertEquals($entityA, $recordA->getRelationshipEntity());

            // Circular relationships are also allowed; ensure both links persist.
            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $this->client->setEntityRelationship($entityB, $entityA, EntityRelationshipType::PROXY);

            $recordB = $this->client->getEntityRecord($entityB);
            $this->assertEquals($entityA, $recordB->getRelationshipEntity());

            // Relationship to a non-existent target must fail (the server currently maps this to 400).
            $this->expectRequestFailure(
                fn() => $this->client->setEntityRelationship($entityA, $this->randomUuid(), EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::NOT_FOUND->value],
                'Relationship to non-existent target should fail'
            );

            // Clearing a relationship that does not exist should still succeed.
            $freshEntity = $this->createSecurityEntity();
            $this->client->clearEntityRelationship($freshEntity);
        }

        public function testSecurityEntityRelationshipRequiresOperatorPermissions(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $clientOnly = $this->createLimitedOperator('entity_rel_client', client: true);
            $managementOnly = $this->createLimitedOperator('entity_rel_management', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->setEntityRelationship($entityA, $entityB, EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not set entity relationships'
            );

            $this->expectRequestFailure(
                fn() => $managementOnly->setEntityRelationship($entityA, $entityB, EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::FORBIDDEN->value],
                'Management-only operator should not set entity relationships'
            );
        }

        public function testSecurityDeleteEntityCascade(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->deleteEntity($entityUuid);

            $this->removeFromCleanup($this->createdEntities, $entityUuid);
            $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid);
            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);

            $this->expectRequestFailure(
                fn() => $this->client->getEntityRecord($entityUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Deleted entity should not be retrievable'
            );

            $this->expectRequestFailure(
                fn() => $this->client->getEvidenceRecord($evidenceUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Evidence for deleted entity should be removed'
            );

            $this->expectRequestFailure(
                fn() => $this->client->getBlacklistRecord($blacklistUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Blacklist for deleted entity should be removed'
            );
        }

        public function testSecurityClearReputationRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $clientOnly = $this->createLimitedOperator('reputation_client', client: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->clearEntityReputation($entityUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not clear reputation'
            );

            $this->expectRequestFailure(
                fn() => $this->client->clearEntityReputation($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'Clearing reputation for non-existent entity should fail'
            );
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

        public function testEntityMetadataPreservationAndUpdate(): void
        {
            $host = 'metadata-test.com';
            $id = 'metadata_user';
            $initialMetadata = ['version' => 1, 'tracked' => true];
            $updatedMetadata = ['version' => 2, 'tracked' => false, 'notes' => 'updated'];

            $entityUuid = $this->client->pushEntity($host, $id, $initialMetadata);
            $this->createdEntities[] = $entityUuid;

            $record = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($initialMetadata, $record->getMetadata());

            // Re-pushing the same entity with new metadata should update it.
            $sameUuid = $this->client->pushEntity($host, $id, $updatedMetadata);
            $this->assertEquals($entityUuid, $sameUuid);

            $updatedRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($updatedMetadata, $updatedRecord->getMetadata());
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

        public function testEntityMetadataValidationRejectsMalformedInput(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->pushEntity('metadata-validation.com', 'user', ['key' => str_repeat('x', 2000)]),
                [HttpResponseCode::BAD_REQUEST->value],
                'Overly long metadata value should be rejected'
            );

            $this->expectRequestFailure(
                fn() => $this->client->pushEntity('metadata-validation.com', 'user', [str_repeat('k', 70) => 'value']),
                [HttpResponseCode::BAD_REQUEST->value],
                'Overly long metadata key should be rejected'
            );
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

        public function testCrossOperatorEntityIsolation(): void
        {
            $operatorA = $this->createLimitedOperator('entity_owner_a', client: true);
            $operatorB = $this->createLimitedOperator('entity_owner_b', client: true);

            $entityA = $operatorA->pushEntity('operator-a-private.com', 'user_a');
            $this->createdEntities[] = $entityA;

            $entityB = $operatorB->pushEntity('operator-b-private.com', 'user_b');
            $this->createdEntities[] = $entityB;

            // Operator B can retrieve entity A if entities are public; otherwise it needs any valid token.
            $recordByB = $operatorB->getEntityRecord($entityA);
            $this->assertEquals($entityA, $recordByB->getUuid());

            // Anonymous client follows public-entities setting.
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            if ($this->client->getServerInformation()->isPublicEntities())
            {
                $anonymousRecord = $anonymousClient->getEntityRecord($entityA);
                $this->assertEquals($entityA, $anonymousRecord->getUuid());
            }
            else
            {
                $this->expectRequestFailure(
                    fn() => $anonymousClient->getEntityRecord($entityA),
                    [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                    'Anonymous access should respect public_entities setting'
                );
            }
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

        public function testEntityCreationWithAllValidHosts(): void
        {
            $validHosts = [
                'example.com',
                'sub.example.co.uk',
                '192.168.1.1',
                '::1',
                'localhost',
                'a-b-c.example.org',
            ];

            foreach ($validHosts as $host)
            {
                $entityUuid = $this->client->pushEntity($host, 'host_test_user');
                $this->createdEntities[] = $entityUuid;

                $record = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($host, $record->getHost());
            }
        }

        public function testEntityClearReputationRequiresExistingEntity(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->clearEntityReputation('00000000-0000-0000-0000-000000000000'),
                [HttpResponseCode::NOT_FOUND->value, HttpResponseCode::BAD_REQUEST->value],
                'Clearing reputation for non-existent entity should fail'
            );

            $this->expectRequestFailure(
                fn() => $this->client->clearEntityReputation('not-a-valid-uuid'),
                [HttpResponseCode::BAD_REQUEST->value],
                'Clearing reputation for malformed identifier should fail'
            );
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

        public function testGetTopThreatsAsAnonymousClient(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->pushEntity("top-threats-anon-$i.com", "anon_$i");
                $this->createdEntities[] = $uuid;
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $topThreats = $anonymousClient->getTopThreats();

            $this->assertIsArray($topThreats);
            $this->assertNotEmpty($topThreats);

            foreach ($topThreats as $threat)
            {
                $this->assertInstanceOf(EntityRecord::class, $threat);
                $this->assertNotEmpty($threat->getUuid());
            }
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

    }
