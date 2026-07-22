<?php

    namespace FederationLib\Tests\Entities;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class EntitiesTest extends TestCase
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

        public function testUpdateEntity(): void
        {
            $uuid = $this->client->pushEntity('update-test.com', 'user1');
            $this->createdEntities[] = $uuid;

            $initialRecord = $this->client->getEntityRecord($uuid);
            $this->assertNull($initialRecord->getMetadata());

            $this->client->updateEntity($uuid, ['key1' => 'value1', 'key2' => 42]);

            $updatedRecord = $this->client->getEntityRecord($uuid);
            $metadata = $updatedRecord->getMetadata();
            $this->assertIsArray($metadata);
            $this->assertEquals('value1', $metadata['key1']);
            $this->assertEquals(42, $metadata['key2']);
        }

        public function testUpdateEntityByHash(): void
        {
            $host = 'hash-update-test.com';
            $id = 'hashuser';
            $uuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $uuid;

            $hash = Utilities::hashEntity($host, $id);
            $this->client->updateEntity($hash, ['source' => 'hash_update']);

            $record = $this->client->getEntityRecord($uuid);
            $metadata = $record->getMetadata();
            $this->assertIsArray($metadata);
            $this->assertEquals('hash_update', $metadata['source']);
        }

        public function testUpdateEntityByAddress(): void
        {
            $host = 'address-update-test.com';
            $id = 'addressuser';
            $uuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $uuid;

            $address = $id . '@' . $host;
            $this->client->updateEntity($address, ['source' => 'address_update']);

            $record = $this->client->getEntityRecord($uuid);
            $metadata = $record->getMetadata();
            $this->assertIsArray($metadata);
            $this->assertEquals('address_update', $metadata['source']);
        }

        public function testUpdateEntityMetadataReplace(): void
        {
            $uuid = $this->client->pushEntity('replace-test.com', 'replaceuser', ['initial' => 'value', 'shared' => 'old']);
            $this->createdEntities[] = $uuid;

            $this->client->updateEntity($uuid, ['new_key' => 'new_value', 'shared' => 'updated']);

            $record = $this->client->getEntityRecord($uuid);
            $metadata = $record->getMetadata();
            $this->assertIsArray($metadata);
            $this->assertArrayNotHasKey('initial', $metadata, 'Keys not in PATCH payload should be removed');
            $this->assertEquals('updated', $metadata['shared'], 'Existing keys in new metadata should be overwritten');
            $this->assertEquals('new_value', $metadata['new_key'], 'New keys should be added');
        }

        public function testPushEntityMetadataMerge(): void
        {
            $uuid = $this->client->pushEntity('push-merge-test.com', 'pushmerge', ['initial' => 'value', 'shared' => 'old']);
            $this->createdEntities[] = $uuid;

            $this->client->pushEntity('push-merge-test.com', 'pushmerge', ['new_key' => 'new_value', 'shared' => 'updated']);

            $record = $this->client->getEntityRecord($uuid);
            $metadata = $record->getMetadata();
            $this->assertIsArray($metadata);
            $this->assertEquals('value', $metadata['initial'], 'Existing keys not in new push should be preserved via merge');
            $this->assertEquals('updated', $metadata['shared'], 'Existing keys in new push should be overwritten');
            $this->assertEquals('new_value', $metadata['new_key'], 'New keys should be added');
        }

        public function testUpdateNonExistentEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->updateEntity(Uuid::v7()->toRfc4122(), ['key' => 'value']);
        }

        public function testUpdateEntityInvalidMetadata(): void
        {
            $uuid = $this->client->pushEntity('invalid-meta-test.com', 'metauser');
            $this->createdEntities[] = $uuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->updateEntity($uuid, ['']);
        }

        public function testUpdateEntityMissingIdentifier(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->updateEntity('', ['key' => 'value']);
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
    }
