<?php

    namespace FederationLib\Tests\Entities;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\SearchResult;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class EntitiesTest extends TestCase
    {
        use TestHelpers;
        private const array TEST_METADATA = ['source' => 'metadata_visibility_test', 'sensitive' => true];

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

        public function testPushEntityStripsWwwPrefix(): void
        {
            $entityUuid = $this->client->pushEntity('www.www-strip-test.com');
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals('www-strip-test.com', $entityRecord->getHost());

            $this->assertEquals($entityUuid, $this->client->pushEntity('www-strip-test.com'));
            $this->assertEquals($entityUuid, $this->client->getEntityRecord('www.www-strip-test.com')->getUuid());
            $this->assertEquals($entityUuid, $this->client->getEntityRecord(Utilities::hashEntity('www-strip-test.com'))->getUuid());

            $namedEntityUuid = $this->client->pushEntity('www.www-strip-test.com', 'john123');
            $this->createdEntities[] = $namedEntityUuid;
            $this->assertEquals($namedEntityUuid, $this->client->pushEntity('www-strip-test.com', 'john123'));
            $this->assertEquals($namedEntityUuid, $this->client->getEntityRecord('john123@www-strip-test.com')->getUuid());
        }

        public function testPushSubdomainEntityCreatesParentDomain(): void
        {
            $subdomain1Uuid = $this->client->pushEntity('sub1.subdomain-parent-test.com');
            $this->createdEntities[] = $subdomain1Uuid;
            $subdomain2Uuid = $this->client->pushEntity('sub2.subdomain-parent-test.com');
            $this->createdEntities[] = $subdomain2Uuid;

            // The parent domain is created automatically and shared by both subdomains
            $parentRecord = $this->client->getEntityRecord('subdomain-parent-test.com');
            $this->createdEntities[] = $parentRecord->getUuid();
            $this->assertNull($parentRecord->getRelationshipEntity());

            foreach([$subdomain1Uuid, $subdomain2Uuid] as $subdomainUuid)
            {
                $subdomainRecord = $this->client->getEntityRecord($subdomainUuid);
                $this->assertNotEquals($parentRecord->getUuid(), $subdomainUuid);
                $this->assertEquals($parentRecord->getUuid(), $subdomainRecord->getRelationshipEntity());
                $this->assertEquals(EntityRelationshipType::CHILD, $subdomainRecord->getRelationshipType());
            }

            // Pushing the parent domain again returns the existing entity
            $this->assertEquals($parentRecord->getUuid(), $this->client->pushEntity('subdomain-parent-test.com'));
        }

        public function testPushParentDomainLinksExistingSubdomainEntities(): void
        {
            $parentHost = uniqid('reverse-parent-test-') . '.com';
            $subdomain1Uuid = $this->client->pushEntity('sub1.' . $parentHost);
            $this->createdEntities[] = $subdomain1Uuid;
            $subdomain2Uuid = $this->client->pushEntity('deep.sub2.' . $parentHost);
            $this->createdEntities[] = $subdomain2Uuid;

            // Deleting the parent domain leaves the subdomains unlinked, as if they were registered before it
            $this->client->deleteEntity($this->client->getEntityRecord($parentHost)->getUuid());
            foreach([$subdomain1Uuid, $subdomain2Uuid] as $subdomainUuid)
            {
                $this->assertNull($this->client->getEntityRecord($subdomainUuid)->getRelationshipEntity());
            }

            // Registering the parent domain afterwards links the existing subdomains to it
            $parentUuid = $this->client->pushEntity($parentHost);
            $this->createdEntities[] = $parentUuid;
            $this->assertNull($this->client->getEntityRecord($parentUuid)->getRelationshipEntity());

            foreach([$subdomain1Uuid, $subdomain2Uuid] as $subdomainUuid)
            {
                $subdomainRecord = $this->client->getEntityRecord($subdomainUuid);
                $this->assertEquals($parentUuid, $subdomainRecord->getRelationshipEntity());
                $this->assertEquals(EntityRelationshipType::CHILD, $subdomainRecord->getRelationshipType());
            }
        }

        public function testPushParentDomainKeepsExistingSubdomainRelationships(): void
        {
            $parentHost = uniqid('reverse-keep-test-') . '.com';
            $subdomainUuid = $this->client->pushEntity('sub.' . $parentHost);
            $this->createdEntities[] = $subdomainUuid;
            $this->client->deleteEntity($this->client->getEntityRecord($parentHost)->getUuid());

            // A relationship assigned by an operator is not replaced by the parent domain
            $targetUuid = $this->client->pushEntity(uniqid('reverse-keep-target-') . '.com');
            $this->createdEntities[] = $targetUuid;
            $this->client->setEntityRelationship($subdomainUuid, $targetUuid, EntityRelationshipType::PROXY);

            $parentUuid = $this->client->pushEntity($parentHost);
            $this->createdEntities[] = $parentUuid;

            $subdomainRecord = $this->client->getEntityRecord($subdomainUuid);
            $this->assertEquals($targetUuid, $subdomainRecord->getRelationshipEntity());
            $this->assertEquals(EntityRelationshipType::PROXY, $subdomainRecord->getRelationshipType());
        }

        public function testPushParentDomainDoesNotLinkOtherDomainsOrNamedEntities(): void
        {
            $parentHost = uniqid('reverse-scope-test-') . '.com';

            // Shares the suffix of the parent host, but belongs to a different registrable domain
            $otherDomainUuid = $this->client->pushEntity('other' . $parentHost);
            $this->createdEntities[] = $otherDomainUuid;

            // Named entities are never linked to a parent domain
            $namedEntityUuid = $this->client->pushEntity('sub.' . $parentHost, 'john123');
            $this->createdEntities[] = $namedEntityUuid;

            $parentUuid = $this->client->pushEntity($parentHost);
            $this->createdEntities[] = $parentUuid;

            $this->assertNull($this->client->getEntityRecord($otherDomainUuid)->getRelationshipEntity());
            $this->assertNull($this->client->getEntityRecord($namedEntityUuid)->getRelationshipEntity());
        }

        public function testPushRegistrableDomainEntityHasNoParent(): void
        {
            $entityUuid = $this->client->pushEntity('no-parent-test.co.uk');
            $this->createdEntities[] = $entityUuid;
            $this->assertNull($this->client->getEntityRecord($entityUuid)->getRelationshipEntity());

            $namedEntityUuid = $this->client->pushEntity('sub.no-parent-test.co.uk', 'john123');
            $this->createdEntities[] = $namedEntityUuid;
            $this->assertNull($this->client->getEntityRecord($namedEntityUuid)->getRelationshipEntity());
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

        public function testEntityPathRoutesResolveByHostAddress(): void
        {
            // Issue #1: every /entities/{identifier} route must resolve a host entity by its entity address (the host
            // alone), not only by its UUID or SHA-256 identifier
            $host = 'host-only-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $this->assertSame($entityUuid, $this->client->getEntityRecord($host)->getUuid());
            $this->assertSame($entityUuid, $this->client->queryEntity($host)->getEntityRecord()->getUuid());

            $this->client->updateEntity($host, ['source' => 'host_address_test']);
            $this->assertSame('host_address_test', $this->client->getEntityRecord($entityUuid)->getMetadata()['source'] ?? null);

            $this->client->setEntityWhitelist($host, true);
            $this->assertTrue($this->client->getEntityRecord($entityUuid)->isWhitelisted());
            $this->client->setEntityWhitelist($host, false);
            $this->assertFalse($this->client->getEntityRecord($entityUuid)->isWhitelisted());

            $this->client->clearEntityReputation($host);
            $this->assertSame(0, $this->client->getEntityRecord($entityUuid)->getReputation());

            $this->assertIsArray($this->client->listEntityEvidenceRecords($host));
            $this->assertIsArray($this->client->listEntityAuditLogs($host));
            $this->assertIsArray($this->client->listEntityBlacklistRecords($host));
            $this->assertIsArray($this->client->listEntityReports($host));

            $childHost = 'child.' . $host;
            $childUuid = $this->client->pushEntity($childHost);
            $this->createdEntities[] = $childUuid;

            $this->client->setEntityRelationship($childHost, $host, EntityRelationshipType::CHILD);
            $this->assertSame($entityUuid, $this->client->getEntityRecord($childUuid)->getRelationshipEntity());
            $this->client->clearEntityRelationship($childHost);
            $this->assertNull($this->client->getEntityRecord($childUuid)->getRelationshipEntity());
        }

        public function testEntityRequestMembersResolveByHostAddress(): void
        {
            // Issue #1: request members carrying an entity identifier must accept the host alone as well
            $host = 'host-member-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($host, 'Host address evidence', 'Note', 'host');
            $this->createdEvidenceRecords[] = $evidenceUuid;
            $this->assertContains($evidenceUuid, array_map(fn($evidence) => $evidence->getUuid(), $this->client->listEntityEvidenceRecords($host)));

            $submission = $this->client->submitReport($host, ['text_content' => 'Host address report'], IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()[0]->getUuid();
            $this->assertSame($entityUuid, $submission->getReport()->getReportingEntity());
            $this->assertContains($reportUuid, array_map(fn($report) => $report->getUuid(), $this->client->listEntityReports($host)));

            $blacklistUuid = $this->client->blacklistEntity($host, $reportUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            $this->assertContains($blacklistUuid, array_map(fn($blacklist) => $blacklist->getUuid(), $this->client->listEntityBlacklistRecords($host)));
        }

        public function testEntityResolvableByNamedAddressWithIpHost(): void
        {
            // Named entity addresses whose entity host is an IP address are routable in a path as well
            $id = 'ip_user_' . uniqid();
            foreach (['203.0.113.77', '2001:db8::77'] as $ipHost)
            {
                $entityUuid = $this->client->pushEntity($ipHost, $id);
                $this->createdEntities[] = $entityUuid;
                $this->assertSame($entityUuid, $this->client->getEntityRecord("$id@$ipHost")->getUuid(), "$id@$ipHost");
            }
        }

        public function testEntityDeletableByHostAddress(): void
        {
            $host = 'host-delete-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $this->client->deleteEntity($host);
            $this->expectRequestFailure(
                fn() => $this->client->getEntityRecord($entityUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Entity deleted by its host address must no longer exist'
            );
        }

        public function testEntityIdentifierRejectsNonCanonicalAddress(): void
        {
            // OFD: an entity address must be canonical; anything else is not a valid identifier (HTTP 400), in a path
            // segment and in a request member alike
            foreach (['Example.COM', 'john@Example.com', '2001:0db8::1', 'Not_A_Valid_Identifier'] as $identifier)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->getEntityRecord($identifier),
                    [HttpResponseCode::BAD_REQUEST->value],
                    "Non-canonical path identifier $identifier must be rejected"
                );
                $this->expectRequestFailure(
                    fn() => $this->client->submitEvidence($identifier, 'Non-canonical evidence'),
                    [HttpResponseCode::BAD_REQUEST->value],
                    "Non-canonical request member $identifier must be rejected"
                );
            }
        }

        public function testEntityClearReputationRequiresExistingEntity(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->clearEntityReputation('00000000-0000-0000-0000-000000000000'),
                [HttpResponseCode::NOT_FOUND->value, HttpResponseCode::BAD_REQUEST->value],
                'Clearing reputation for non-existent entity should fail'
            );

            // A lowercase string like 'not-a-valid-uuid' is a valid host entity address, so use one that is not
            $this->expectRequestFailure(
                fn() => $this->client->clearEntityReputation('Not_A_Valid_Identifier'),
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

        /**
         * Pushes an entity with TEST_METADATA and registers it for cleanup.
         *
         * @param string|null $id Optional local-part identifier for the entity
         * @return array{uuid: string, host: string}
         * @throws RequestException
         */
        private function createEntityWithMetadata(?string $id = null): array
        {
            $host = 'metadata-vis-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host, $id, self::TEST_METADATA);
            $this->createdEntities[] = $entityUuid;
            return ['uuid' => $entityUuid, 'host' => $host];
        }

        /**
         * Determines whether the server hides entity metadata from unauthenticated users by
         * probing the anonymous response of the given entity record.
         *
         * @param string $entityUuid The UUID of the entity to probe
         * @return bool True if the server nulls the metadata for anonymous users, false otherwise
         */
        private function isEntityMetadataHidden(string $entityUuid): bool
        {
            [$code, $body] = $this->rawRequest('GET', 'entities/' . $entityUuid);
            if ($code !== 200)
            {
                return false;
            }

            $data = json_decode($body, true);
            return is_array($data) && array_key_exists('metadata', $data) && $data['metadata'] === null;
        }

        /**
         * Skips the test unless the server hides entity metadata from unauthenticated users.
         *
         * @param string $entityUuid The UUID of the entity to probe
         * @return void
         */
        private function requireHiddenEntityMetadata(string $entityUuid): void
        {
            if (!$this->isEntityMetadataHidden($entityUuid))
            {
                $this->markTestSkipped('Server is configured to expose entity metadata to unauthenticated users');
            }
        }

        /**
         * Skips the test unless the server exposes entity metadata to unauthenticated users.
         *
         * @param string $entityUuid The UUID of the entity to probe
         * @return void
         */
        private function requirePublicEntityMetadata(string $entityUuid): void
        {
            if ($this->isEntityMetadataHidden($entityUuid))
            {
                $this->markTestSkipped('Server is configured to hide entity metadata from unauthenticated users');
            }
        }

        public function testAuthenticatedGetEntityRecordIncludesMetadata(): void
        {
            $entity = $this->createEntityWithMetadata('user_get');

            $record = $this->client->getEntityRecord($entity['uuid']);
            $this->assertInstanceOf(EntityRecord::class, $record);
            $this->assertEquals(self::TEST_METADATA, $record->getMetadata());

            [$code, $body] = $this->rawRequest('GET', 'entities/' . $entity['uuid'], getenv('SERVER_ACCESS_TOKEN'));
            $this->assertEquals(200, $code);
            $data = json_decode($body, true);
            $this->assertEquals(self::TEST_METADATA, $data['metadata'], 'Entity metadata must be serialized as a JSON object');
        }

        public function testAnonymousGetEntityRecordOmitsMetadata(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $entity = $this->createEntityWithMetadata('user_anon_get');
            $this->requireHiddenEntityMetadata($entity['uuid']);

            [$code, $body] = $this->rawRequest('GET', 'entities/' . $entity['uuid']);
            $this->assertEquals(200, $code);
            $data = json_decode($body, true);
            $this->assertArrayHasKey('metadata', $data, 'The metadata field must remain present in the response');
            $this->assertNull($data['metadata'], 'Entity metadata must be nulled out for unauthenticated users');
        }

        public function testAuthenticatedListEntitiesIncludesMetadata(): void
        {
            $entity = $this->createEntityWithMetadata('user_list');
            $metadataByUuid = [];

            for ($page = 1; $page <= 20; $page++)
            {
                $entities = $this->client->listEntities($page, 100);
                if (empty($entities))
                {
                    break;
                }

                foreach ($entities as $record)
                {
                    $metadataByUuid[$record->getUuid()] = $record->getMetadata();
                }
            }

            $this->assertArrayHasKey($entity['uuid'], $metadataByUuid, 'Created entity should appear in the entity listing');
            $this->assertEquals(self::TEST_METADATA, $metadataByUuid[$entity['uuid']]);
        }

        public function testAnonymousListEntitiesOmitsMetadata(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $entity = $this->createEntityWithMetadata('user_anon_list');
            $this->requireHiddenEntityMetadata($entity['uuid']);

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $found = false;

            for ($page = 1; $page <= 20; $page++)
            {
                $entities = $anonymousClient->listEntities($page, 100);
                if (empty($entities))
                {
                    break;
                }

                foreach ($entities as $record)
                {
                    if ($record->getUuid() !== $entity['uuid'])
                    {
                        continue;
                    }

                    $found = true;
                    $this->assertNull($record->getMetadata(), 'Entity metadata must be nulled out for unauthenticated users');
                    break 2;
                }
            }

            $this->assertTrue($found, 'Created entity should appear in the anonymous entity listing');
        }

        public function testAuthenticatedSearchEntitiesIncludesMetadata(): void
        {
            $entity = $this->createEntityWithMetadata('user_search');

            $results = $this->client->searchEntities($entity['host'], 1, 10);
            $this->assertNotEmpty($results);

            foreach ($results as $record)
            {
                if ($record->getUuid() === $entity['uuid'])
                {
                    $this->assertEquals(self::TEST_METADATA, $record->getMetadata());
                    return;
                }
            }

            $this->fail('Created entity should appear in the search results');
        }

        public function testAnonymousSearchEntitiesOmitsMetadata(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $entity = $this->createEntityWithMetadata('user_anon_search');
            $this->requireHiddenEntityMetadata($entity['uuid']);

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            try
            {
                $results = $anonymousClient->searchEntities($entity['host'], 1, 10);
            }
            catch (RequestException $e)
            {
                $this->markTestSkipped('Anonymous entity search is not available: ' . $e->getMessage());
            }

            foreach ($results as $record)
            {
                if ($record->getUuid() === $entity['uuid'])
                {
                    $this->assertNull($record->getMetadata(), 'Entity metadata must be nulled out for unauthenticated users');
                    return;
                }
            }

            $this->fail('Created entity should appear in the anonymous search results');
        }

        public function testGlobalSearchEntityResultsIncludeMetadataForAuthenticatedClient(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $entity = $this->createEntityWithMetadata('user_global_search');

            $results = $this->client->search($entity['host'], [RecordType::ENTITY->value], 1, 10);
            $this->assertNotEmpty($results);

            foreach ($results as $result)
            {
                $this->assertInstanceOf(SearchResult::class, $result);
                if ($result->getType() === RecordType::ENTITY && $result->getRecord() instanceof EntityRecord)
                {
                    $this->assertEquals($entity['uuid'], $result->getRecord()->getUuid());
                    $this->assertEquals(self::TEST_METADATA, $result->getRecord()->getMetadata());
                    return;
                }
            }

            $this->fail('Created entity should appear in the global search results');
        }


        public function testAnonymousTopThreatsOmitMetadata(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $this->createEntityWithMetadata();
            $this->createEntityWithMetadata();

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $topThreats = $anonymousClient->getTopThreats(25);
            $this->assertNotEmpty($topThreats, 'Top threats should return at least one entity');

            foreach ($topThreats as $threat)
            {
                if ($this->isEntityMetadataHidden($threat->getUuid()))
                {
                    $this->assertNull($threat->getMetadata(), 'Entity metadata must be nulled out for unauthenticated users');
                }
            }
        }

        public function testAuthenticatedScanContentIncludesResolvedEntityMetadata(): void
        {
            $entity = $this->createEntityWithMetadata();

            $scanned = $this->client->scanContent('Check out ' . $entity['host'] . ' for details.');
            $this->assertNotNull($scanned);
            $this->assertNotEmpty($scanned->getResolvedEntities());

            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getHost() !== $entity['host'])
                {
                    continue;
                }

                $this->assertEquals(self::TEST_METADATA, $resolvedEntity->getEntity()->getMetadata());
                return;
            }

            $this->fail('Created entity should be resolved by the content scan');
        }

        public function testAnonymousScanContentOmitsResolvedEntityMetadata(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $entity = $this->createEntityWithMetadata();
            $this->requireHiddenEntityMetadata($entity['uuid']);

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            try
            {
                $scanned = $anonymousClient->scanContent('Check out ' . $entity['host'] . ' for details.');
            }
            catch (RequestException $e)
            {
                $this->markTestSkipped('Anonymous content scanning is not available: ' . $e->getMessage());
            }

            $this->assertNotNull($scanned);
            $this->assertNotEmpty($scanned->getResolvedEntities());

            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                if ($resolvedEntity->getEntity()->getHost() !== $entity['host'])
                {
                    continue;
                }

                $this->assertNull($resolvedEntity->getEntity()->getMetadata(), 'Entity metadata must be nulled out for unauthenticated users');
                return;
            }

            $this->fail('Created entity should be resolved by the content scan');
        }
    }
