<?php

    namespace FederationLib\Tests\Entities;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\SearchResult;
    use InvalidArgumentException;

    class EntitiesTest extends ServerTestCase
    {
        private const array TEST_METADATA = ['source' => 'metadata_visibility_test', 'sensitive' => true];

        public function testPushEntity(): void
        {
            $host = $this->uniqueHost('push');

            // A named entity (an account of the host) and the host itself are distinct entities
            $userEntityUuid = $this->client->pushEntity($host, 'john123');
            $this->createdEntities[] = $userEntityUuid;
            $globalEntityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $globalEntityUuid;
            $this->assertNotEquals($userEntityUuid, $globalEntityUuid);

            // Each is resolved by its UUID and by its SHA-256 identifier
            foreach ([[$userEntityUuid, 'john123'], [$globalEntityUuid, null]] as [$entityUuid, $id])
            {
                foreach ([$entityUuid, Utilities::hashEntity($host, $id)] as $identifier)
                {
                    $record = $this->client->getEntityRecord($identifier);
                    $this->assertEquals($entityUuid, $record->getUuid());
                    $this->assertEquals($host, $record->getHost());
                    $this->assertSame($id, $record->getId());
                }
            }

            // Pushing an existing entity returns it
            $this->assertEquals($userEntityUuid, $this->client->pushEntity($host, 'john123'));
            $this->assertEquals($globalEntityUuid, $this->client->pushEntity($host));

            $ipAddressEntityUuid = $this->client->pushEntity('203.0.113.201');
            $this->createdEntities[] = $ipAddressEntityUuid;
            $ipAddressEntityRecord = $this->client->getEntityRecord($ipAddressEntityUuid);
            $this->assertEquals('203.0.113.201', $ipAddressEntityRecord->getHost());
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
            $this->client->updateEntity($this->randomUuid(), ['key' => 'value']);
        }

        public function testUpdateEntityInvalidMetadata(): void
        {
            $uuid = $this->client->pushEntity('invalid-meta-test.com', 'metauser');
            $this->createdEntities[] = $uuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->updateEntity($uuid, ['']);
        }

        public function testClientValidatesArguments(): void
        {
            $calls = [
                fn() => $this->client->pushEntity(''),
                fn() => $this->client->pushEntity('', ''),
                fn() => $this->client->pushEntity('', 'someid'),
                fn() => $this->client->pushEntity('example.com', ''),
                fn() => $this->client->updateEntity('', ['key' => 'value']),
                fn() => $this->client->listEntities(page: -10000),
                fn() => $this->client->listEntities(limit: -1),
            ];

            foreach ($calls as $index => $call)
            {
                try
                {
                    $call();
                    $this->fail("Call $index should have been rejected before reaching the server");
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }

        public function testDeleteEntity(): void
        {
            $entityUuid = $this->createSecurityEntity();

            $this->client->deleteEntity($entityUuid);
            $this->removeFromCleanup($this->createdEntities, $entityUuid);

            $this->expectRequestFailure(fn() => $this->client->getEntityRecord($entityUuid), [HttpResponseCode::NOT_FOUND->value]);
            $this->expectRequestFailure(fn() => $this->client->deleteEntity($entityUuid), [HttpResponseCode::NOT_FOUND->value], 'An entity is not deleted twice');
        }

        public function testListEntities(): void
        {
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuids[] = $this->createSecurityEntity();
            }

            // The newest entities are listed first
            $newest = array_map(fn(EntityRecord $entity) => $entity->getUuid(), $this->client->listEntities(1, 100, null, 'created', 'DESC'));
            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $newest);
            }

            $pageOne = array_map(fn(EntityRecord $entity) => $entity->getUuid(), $this->client->listEntities(1, 2));
            $pageTwo = array_map(fn(EntityRecord $entity) => $entity->getUuid(), $this->client->listEntities(2, 2));
            $this->assertCount(2, $pageOne);
            $this->assertCount(2, $pageTwo);
            $this->assertSame([], array_intersect($pageOne, $pageTwo));
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
                fn() => $this->client->clearEntityReputation($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
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
         * Asserts the default visibility of the test environment, which the metadata tests rely on: entities are public,
         * their metadata is only disclosed to authenticated operators (OFD "Entity Metadata")
         */
        private function assertDefaultVisibility(): void
        {
            $information = $this->client->getServerInformation();
            $this->assertTrue($information->isPublicEntities(), 'The test environment must expose entities publicly');
            $this->assertFalse($information->isPublicEntityMetadata(), 'The test environment must hide entity metadata from unauthenticated requests');
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
            $this->assertDefaultVisibility();

            $entity = $this->createEntityWithMetadata('user_anon_get');

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
            $this->assertDefaultVisibility();

            $entity = $this->createEntityWithMetadata('user_anon_list');

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
            $this->assertDefaultVisibility();

            $entity = $this->createEntityWithMetadata('user_anon_search');

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $results = $anonymousClient->searchEntities($entity['host'], 1, 10);

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
            $this->assertDefaultVisibility();

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
            $this->assertDefaultVisibility();

            $this->createEntityWithMetadata();
            $this->createEntityWithMetadata();

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $topThreats = $anonymousClient->getTopThreats(25);
            $this->assertNotEmpty($topThreats, 'Top threats should return at least one entity');

            foreach ($topThreats as $threat)
            {
                $this->assertNull($threat->getMetadata(), 'Entity metadata must be nulled out for unauthenticated users');
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
            $this->assertDefaultVisibility();

            $entity = $this->createEntityWithMetadata();

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            // Content scanning is public by default
            $scanned = $anonymousClient->scanContent('Check out ' . $entity['host'] . ' for details.');

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
