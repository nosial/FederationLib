<?php

    namespace FederationLib\Tests\Entities;

    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class EntitiesSecurityTest extends TestCase
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

        public function testSecurityUpdateEntityRequiresClientPermissions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $managementOnly = $this->createLimitedOperator('update_entity_mgmt', management: true);

            $this->expectRequestFailure(
                fn() => $managementOnly->updateEntity($entityUuid, ['key' => 'value']),
                [HttpResponseCode::FORBIDDEN->value],
                'Management-only operator should not update entities'
            );
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
                $this->assertInstanceOf(\FederationLib\Objects\EntityRecord::class, $threat);
                $this->assertNotEmpty($threat->getUuid());
            }
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
    }
