<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Features;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use PHPUnit\Framework\TestCase;

    class FeaturesTest extends TestCase
    {
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
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

            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        public function testOperatorAccessTokenRefresh(): void
        {
            $operatorUuid = $this->client->createOperator('access-token-refresh-test');
            $this->createdOperators[] = $operatorUuid;

            $operator = $this->client->getOperator($operatorUuid);
            $originalAccessToken = $operator->getAccessToken();
            $this->assertNotEmpty($originalAccessToken);

            $testClient = new FederationClient(getenv('SERVER_ENDPOINT'), $originalAccessToken);
            $selfOperator = $testClient->getSelf();
            $this->assertEquals($operatorUuid, $selfOperator->getUuid());

            $newAccessToken = $this->client->generateOperatorAccessToken($operatorUuid);
            $this->assertNotEmpty($newAccessToken);
            $this->assertNotEquals($originalAccessToken, $newAccessToken);

            $newTestClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newAccessToken);
            $newSelfOperator = $newTestClient->getSelf();
            $this->assertEquals($operatorUuid, $newSelfOperator->getUuid());

            try
            {
                $testClient->getSelf();
                $this->fail('Expected RequestException for revoked Access Token');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [401, 403], 'Expected 401/403 for revoked Access Token');
            }

            $updatedOperator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newAccessToken, $updatedOperator->getAccessToken());
        }

        public function testSelfAccessTokenRefresh(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $this->client->generateAccessToken(false);
        }

        public function testEvidenceConfidentialityToggle(): void
        {
            $entityUuid = $this->client->pushEntity('confidentiality-test.com', 'confidentiality_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential test evidence', 'Confidentiality test', 'confidential');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($evidenceRecord->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $confidentialRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($confidentialRecord->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $nonConfidentialRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($nonConfidentialRecord->isConfidential());

            $this->assertEquals($evidenceRecord->getTextContent(), $nonConfidentialRecord->getTextContent());
            $this->assertEquals($evidenceRecord->getNote(), $nonConfidentialRecord->getNote());
            $this->assertEquals($evidenceRecord->getTag(), $nonConfidentialRecord->getTag());
        }

        public function testConfidentialEvidenceAccessRestrictions(): void
        {
            $entityUuid = $this->client->pushEntity('confidential-access-test.com', 'confidential_access_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Top secret evidence', 'Confidential note', 'secret', true);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($evidenceRecord->isConfidential());

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $anonymousClient->getEvidenceRecord($evidenceUuid);
        }

        public function testEntityEvidenceRelationshipIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('relationship-test.com', 'relationship_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuids = [];
            for ($i = 1; $i <= 3; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence $i", "Note $i", "tag_$i");
                $evidenceUuids[] = $evidenceUuid;
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid);
            $this->assertIsArray($entityEvidence);
            $this->assertGreaterThanOrEqual(3, count($entityEvidence));

            $foundEvidenceUuids = [];
            foreach ($entityEvidence as $evidence)
            {
                $this->assertEquals($entityUuid, $evidence->getEntityUuid());
                $foundEvidenceUuids[] = $evidence->getUuid();
            }

            foreach ($evidenceUuids as $createdUuid)
            {
                $this->assertContains($createdUuid, $foundEvidenceUuids, 'Created evidence UUID should be found in entity evidence list');
            }
        }

        public function testEntityBlacklistRelationshipIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('blacklist-relationship-test.com', 'blacklist_relationship_user');
            $this->createdEntities[] = $entityUuid;

            $blacklistUuids = [];
            for ($i = 1; $i <= 2; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Blacklist evidence $i", "Blacklist note $i", "blacklist_tag_$i");
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $blacklistType = ($i === 1) ? IncidentType::SPAM : IncidentType::MALWARE;
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $blacklistType, time() + 3600);
                $blacklistUuids[] = $blacklistUuid;
                $this->createdBlacklistRecords[] = $blacklistUuid;
            }

            $entityBlacklists = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertIsArray($entityBlacklists);
            $this->assertGreaterThanOrEqual(2, count($entityBlacklists));

            $foundBlacklistUuids = [];
            foreach ($entityBlacklists as $blacklist)
            {
                $this->assertEquals($entityUuid, $blacklist->getEntityUuid());
                $foundBlacklistUuids[] = $blacklist->getUuid();
            }

            foreach ($blacklistUuids as $createdUuid)
            {
                $this->assertContains($createdUuid, $foundBlacklistUuids, 'Created blacklist UUID should be found in entity blacklist list');
            }
        }

        public function testEntityQueryWithComplexIdentifiers(): void
        {
            $complexEntities = [
                ['host' => 'subdomain.example.com', 'id' => 'user_with_underscores'],
                ['host' => 'example-with-dashes.org', 'id' => 'user.with.dots'],
                ['host' => '192.168.1.100', 'id' => null],
                ['host' => 'very-long-subdomain-name.example-domain.co.uk', 'id' => 'user123'],
            ];

            $createdEntityUuids = [];

            foreach ($complexEntities as $entityData)
            {
                $entityUuid = $this->client->pushEntity($entityData['host'], $entityData['id']);
                $this->assertNotEmpty($entityUuid);
                $createdEntityUuids[] = $entityUuid;
                $this->createdEntities[] = $entityUuid;

                $entityRecord = $this->client->getEntityRecord($entityUuid);
                $this->assertNotNull($entityRecord);
                $this->assertEquals($entityData['host'], $entityRecord->getHost());
                $this->assertEquals($entityData['id'], $entityRecord->getId());
            }

            $this->assertSameSize($complexEntities, array_unique($createdEntityUuids));
        }

        public function testEvidenceWithVariousContentTypes(): void
        {
            $entityUuid = $this->client->pushEntity('content-types-test.com', 'content_types_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceTypes = [
                ['content' => 'Simple plain text evidence', 'note' => 'Plain text note', 'tag' => 'plain'],
                ['content' => json_encode(['type' => 'json', 'data' => ['key' => 'value']]), 'note' => 'JSON data', 'tag' => 'json'],
                ['content' => "Multi-line\nevidence\nwith\nnewlines", 'note' => 'Multi-line note', 'tag' => 'multiline'],
                ['content' => str_repeat('A', 500), 'note' => 'Large content', 'tag' => 'large'],
                ['content' => 'Evidence with special chars: åäö ñ 中文 🚀', 'note' => 'Unicode test', 'tag' => 'unicode'],
            ];

            foreach ($evidenceTypes as $evidenceData)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    $evidenceData['content'],
                    $evidenceData['note'],
                    $evidenceData['tag']
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertNotNull($evidenceRecord);
                $this->assertEquals($evidenceData['content'], $evidenceRecord->getTextContent());
                $this->assertEquals($evidenceData['note'], $evidenceRecord->getNote());
                $this->assertEquals($evidenceData['tag'], $evidenceRecord->getTag());
            }
        }

        public function testBulkOperationsPerformance(): void
        {
            $startTime = microtime(true);

            for ($i = 1; $i <= 5; $i++)
            {
                $operatorUuid = $this->client->createOperator("bulk-test-operator-$i");
                $this->createdOperators[] = $operatorUuid;
                $this->client->setClientPermissions($operatorUuid, true);
            }

            for ($i = 1; $i <= 10; $i++)
            {
                $entityUuid = $this->client->pushEntity("bulk-test-$i.com", "bulk_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            $totalTime = microtime(true) - $startTime;

            Logger::getLogger()->info("Bulk operations completed in $totalTime seconds");
            $this->assertLessThan(30, $totalTime, 'Bulk operations should complete within reasonable time');

            $allEntities = $this->client->listEntities(1, 50);
            $entityUuids = array_map(fn($entity) => $entity->getUuid(), $allEntities);

            foreach ($this->createdEntities as $createdUuid)
            {
                $this->assertContains($createdUuid, $entityUuids, 'Created entity should be found in entity list');
            }
        }

        public function testConcurrentClientOperations(): void
        {
            $operatorUuid = $this->client->createOperator('concurrent-test-operator');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $concurrentClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $this->client->pushEntity('concurrent-test.com', 'concurrent_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid1 = $this->client->submitEvidence($entityUuid, 'Evidence from main client', 'Main client', 'main');
            $this->createdEvidenceRecords[] = $evidenceUuid1;

            $evidenceUuid2 = $concurrentClient->submitEvidence($entityUuid, 'Evidence from concurrent client', 'Concurrent client', 'concurrent');
            $this->createdEvidenceRecords[] = $evidenceUuid2;

            $evidence1 = $this->client->getEvidenceRecord($evidenceUuid1);
            $evidence2 = $this->client->getEvidenceRecord($evidenceUuid2);

            $this->assertNotEquals($evidenceUuid1, $evidenceUuid2);
            $this->assertEquals('Evidence from main client', $evidence1->getTextContent());
            $this->assertEquals('Evidence from concurrent client', $evidence2->getTextContent());
            $this->assertEquals($entityUuid, $evidence1->getEntityUuid());
            $this->assertEquals($entityUuid, $evidence2->getEntityUuid());
        }
    }
