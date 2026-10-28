<?php

    namespace FederationLib\Tests\Entities;

    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use InvalidArgumentException;

    class EntitiesSecurityTest extends ServerTestCase
    {
        public function testSecurityUpdateEntityRequiresClientPermissions(): void
        {
            $entityUuid = $this->createSecurityEntity();

            // Management permissions inherit client permissions, so a management-only operator can update entities.
            $managementOnly = $this->createLimitedOperator('update_entity_mgmt', management: true);
            $managementOnly->updateEntity($entityUuid, ['key' => 'value']);

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals(['key' => 'value'], $entityRecord->getMetadata());

            // An operator without client or management permissions cannot update entities.
            $operatorOnly = $this->createLimitedOperator('update_entity_operator', operator: true);
            $this->expectRequestFailure(
                fn() => $operatorOnly->updateEntity($entityUuid, ['bad' => 'value']),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not update entities'
            );
        }

        public function testSecurityEntityRelationshipAbuse(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            // A relationship of an entity to itself is rejected and leaves the entity without a relationship
            $this->expectRequestFailure(
                fn() => $this->client->setEntityRelationship($entityA, $entityA, EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::BAD_REQUEST->value],
                'A relationship of an entity to itself should be rejected'
            );
            $recordA = $this->client->getEntityRecord($entityA);
            $this->assertNull($recordA->getRelationshipEntity());

            // Circular relationships are also allowed; ensure both links persist.
            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $this->client->setEntityRelationship($entityB, $entityA, EntityRelationshipType::PROXY);

            $recordB = $this->client->getEntityRecord($entityB);
            $this->assertEquals($entityA, $recordB->getRelationshipEntity());

            // A relationship to a non-existent target references no record (OFD "Error Responses")
            $this->expectRequestFailure(
                fn() => $this->client->setEntityRelationship($entityA, $this->randomUuid(), EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::NOT_FOUND->value],
                'Relationship to non-existent target should fail'
            );

            // Clearing a relationship that does not exist should still succeed.
            $freshEntity = $this->createSecurityEntity();
            $this->client->clearEntityRelationship($freshEntity);
        }

        public function testSecurityEntityRelationshipRequiresClientPermissions(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $clientOnly = $this->createLimitedOperator('entity_rel_client', client: true);
            $managementOnly = $this->createLimitedOperator('entity_rel_management', management: true);
            $operatorOnly = $this->createLimitedOperator('entity_rel_operator', operator: true);

            // Client permissions authorize relationships, and management permissions inherit them.
            $clientOnly->setEntityRelationship($entityA, $entityB, EntityRelationshipType::ALTERNATIVE);
            $this->assertEquals($entityB, $this->client->getEntityRecord($entityA)->getRelationshipEntity());
            $clientOnly->clearEntityRelationship($entityA);
            $this->assertNull($this->client->getEntityRecord($entityA)->getRelationshipEntity());

            $managementOnly->setEntityRelationship($entityA, $entityB, EntityRelationshipType::PROXY);
            $this->assertEquals($entityB, $this->client->getEntityRecord($entityA)->getRelationshipEntity());
            $managementOnly->clearEntityRelationship($entityA);
            $this->assertNull($this->client->getEntityRecord($entityA)->getRelationshipEntity());

            // Operator permissions do not inherit client permissions.
            $this->expectRequestFailure(
                fn() => $operatorOnly->setEntityRelationship($entityA, $entityB, EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only operator should not set entity relationships'
            );

            $this->client->setEntityRelationship($entityA, $entityB, EntityRelationshipType::CHILD);
            $this->expectRequestFailure(
                fn() => $operatorOnly->clearEntityRelationship($entityA),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only operator should not clear entity relationships'
            );
            $this->assertEquals($entityB, $this->client->getEntityRecord($entityA)->getRelationshipEntity());
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

        public function testSecurityEntityPushWithMaliciousMetadata(): void
        {
            $maliciousMetadataCases = [
                ['source' => new \stdClass()],
                ['' => 'empty_key'],
                [str_repeat('k', 65) => 'overlong_key'],
            ];

            foreach ($maliciousMetadataCases as $metadata)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->pushEntity('malicious-meta.com', 'meta_user', $metadata),
                    [HttpResponseCode::BAD_REQUEST->value],
                    'Entity push with malformed metadata should be rejected'
                );
            }
        }

        public function testSecuritySetWhitelistRequiresManagementPermissions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $clientOnly = $this->createLimitedOperator('whitelist_client', client: true);
            $operatorOnly = $this->createLimitedOperator('whitelist_operator', operator: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->setEntityWhitelist($entityUuid, true),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not set whitelist state'
            );

            $this->expectRequestFailure(
                fn() => $operatorOnly->setEntityWhitelist($entityUuid, true),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only operator should not set whitelist state'
            );
        }

        public function testSecuritySetWhitelistAnonymousClient(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            $this->expectRequestFailure(fn() => $anonymousClient->setEntityWhitelist($entityUuid, true), [HttpResponseCode::UNAUTHORIZED->value], 'Anonymous request should be rejected');
            $this->assertFalse($this->client->getEntityRecord($entityUuid)->isWhitelisted());
        }

        public function testSecuritySetWhitelistNonExistentEntity(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->setEntityWhitelist($this->randomUuid(), true),
                [HttpResponseCode::NOT_FOUND->value],
                'Setting whitelist on non-existent entity should fail'
            );
        }

        public function testSecuritySetWhitelistInvalidIdentifier(): void
        {
            // A malformed identifier is a client error, a well-formed entity address of no entity is not found
            foreach (['Not_A_Valid_Identifier', 'Example.COM'] as $identifier)
            {
                $this->expectRequestFailure(fn() => $this->client->setEntityWhitelist($identifier, true), [HttpResponseCode::BAD_REQUEST->value], "Malformed identifier $identifier should be rejected");
            }
            $this->expectRequestFailure(fn() => $this->client->setEntityWhitelist($this->uniqueHost('unregistered'), true), [HttpResponseCode::NOT_FOUND->value], 'An unregistered entity address should not be found');

            $this->expectException(InvalidArgumentException::class);
            $this->client->setEntityWhitelist('', true);
        }

        public function testSecuritySetWhitelistMissingParameter(): void
        {
            $entityUuid = $this->createSecurityEntity();

            [$code, $body] = $this->rawRequest('PATCH', 'entities/' . $entityUuid . '/whitelist',
                getenv('SERVER_ACCESS_TOKEN'),
                json_encode([])
            );

            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code,
                'Missing whitelisted parameter should return 400');
        }

        public function testSecuritySetWhitelistInvalidParameterType(): void
        {
            $entityUuid = $this->createSecurityEntity();

            $invalidParams = [
                'yes',
                'no',
                'not_a_boolean',
                ['nested' => 'object'],
                1.5,
            ];

            foreach ($invalidParams as $value)
            {
                [$code, $body] = $this->rawRequest('PATCH', 'entities/' . $entityUuid . '/whitelist',
                    getenv('SERVER_ACCESS_TOKEN'),
                    json_encode(['whitelisted' => $value])
                );

                $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code,
                    "Invalid whitelisted value '" . (is_scalar($value) ? (string)$value : gettype($value)) . "' should return 400");
            }
        }

        public function testSecuritySetWhitelistManagementOperatorSucceeds(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $managementOnly = $this->createLimitedOperator('whitelist_mgmt', management: true);

            $managementOnly->setEntityWhitelist($entityUuid, true);
            $record = $this->client->getEntityRecord($entityUuid);
            $this->assertTrue($record->isWhitelisted());
        }

        public function testEntitiesArePublicButOnlyClientsPushThem(): void
        {
            $this->assertTrue($this->client->getServerInformation()->isPublicEntities(), 'The test environment must expose entities publicly');
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            $host = $this->uniqueHost('anonymous');
            $entityUuid = $this->client->pushEntity($host, 'john123');
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $anonymousClient->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('john123', $entityRecord->getId());
            $this->assertEquals($host, $entityRecord->getHost());

            $listed = array_map(fn($entity) => $entity->getUuid(), $anonymousClient->listEntities(1, 100, null, 'created', 'DESC'));
            $this->assertContains($entityUuid, $listed);

            $blacklistedUuid = $this->createSecurityEntity();
            $this->createSecurityBlacklist($blacklistedUuid);
            $this->assertContains($blacklistedUuid, array_map(fn($entity) => $entity->getUuid(), $anonymousClient->getTopThreats(100)));

            $this->expectRequestFailure(
                fn() => $anonymousClient->pushEntity($this->uniqueHost('anonymous-push'), 'john123'),
                [HttpResponseCode::UNAUTHORIZED->value],
                'An anonymous client should not push entities'
            );
        }

        public function testQueryEntityIsPublicByDefaultAndExcludesSelfReferences(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $this->expectRequestFailure(
                fn() => $this->client->setEntityRelationship($entityUuid, $entityUuid, EntityRelationshipType::ALTERNATIVE),
                [HttpResponseCode::BAD_REQUEST->value],
                'A relationship of an entity to itself should be rejected'
            );

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $queryResult = $anonymousClient->queryEntity($entityUuid);

            $this->assertSame($entityUuid, $queryResult->getEntityRecord()->getUuid());
            $this->assertSame([], $queryResult->getRelatedEntities());
        }

        public function testQueryEntityRejectsMissingAndUnknownIdentifiers(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->queryEntity('');
        }

        public function testQueryEntityReturnsNotFoundForUnknownEntity(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->queryEntity($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'Querying an unknown entity must not disclose or create relationship data'
            );
        }

        public function testQueryEntityExcludesLiftedBlacklists(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $queryResult = $this->client->queryEntity($entityUuid);
            $this->assertSame([], $queryResult->getActiveBlacklists());
            $this->assertNull($queryResult->getSuggestedAction());
            $this->assertNull($queryResult->getSuggestedLiftTimestamp());
        }
    }
