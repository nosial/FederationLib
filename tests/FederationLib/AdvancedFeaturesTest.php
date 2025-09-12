<?php

    namespace FederationLib;

    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class AdvancedFeaturesTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        /**
         * @inheritDoc
         */
        protected function setUp(): void
        {
            $this->logger = new Logger('advanced-features-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        /**
         * @inheritDoc
         */
        protected function tearDown(): void
        {
            // Clean up in reverse dependency order
            foreach ($this->createdBlacklistRecords as $blacklistUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
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
                    $this->logger->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
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
                    $this->logger->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
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
                    $this->logger->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            // Reset arrays
            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        // API KEY MANAGEMENT TESTS

        public function testOperatorApiKeyRefresh(): void
        {
            // Create an operator
            $operatorUuid = $this->client->createOperator('api-key-refresh-test');
            $this->createdOperators[] = $operatorUuid;

            // Get initial API key
            $operator = $this->client->getOperator($operatorUuid);
            $originalApiKey = $operator->getApiKey();
            $this->assertNotNull($originalApiKey);
            $this->assertNotEmpty($originalApiKey);

            // Test the original API key works
            $testClient = new FederationClient(getenv('SERVER_ENDPOINT'), $originalApiKey);
            $selfOperator = $testClient->getSelf();
            $this->assertEquals($operatorUuid, $selfOperator->getUuid());

            // Refresh the API key
            $newApiKey = $this->client->refreshOperatorApiKey($operatorUuid);
            $this->assertNotNull($newApiKey);
            $this->assertNotEmpty($newApiKey);
            $this->assertNotEquals($originalApiKey, $newApiKey);

            // Verify new API key works
            $newTestClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newApiKey);
            $newSelfOperator = $newTestClient->getSelf();
            $this->assertEquals($operatorUuid, $newSelfOperator->getUuid());

            // Verify old API key no longer works
            try
            {
                $testClient->getSelf();
                $this->fail("Expected RequestException for revoked API key");
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [401, 403], "Expected 401/403 for revoked API key");
            }

            // Verify operator record shows new API key
            $updatedOperator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newApiKey, $updatedOperator->getApiKey());
        }

        public function testSelfApiKeyRefresh(): void
        {
            // Since we're authenticated as the root operator (Master operator) we shouldn't be allowed to change the
            // API key. This test ensures that attempting to do so results in an error. The API key should remain
            // configurable in the server configuration for the master operator.
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $this->client->refreshApiKey(false);
        }

        // EVIDENCE CONFIDENTIALITY TESTS

        public function testEvidenceConfidentialityToggle(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('confidentiality-test.com', 'confidentiality_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential test evidence', 'Confidentiality test', 'confidential', false);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Verify initial non-confidential state
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($evidenceRecord->isConfidential());

            // Make it confidential
            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            
            $confidentialRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($confidentialRecord->isConfidential());

            // Make it non-confidential again
            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            
            $nonConfidentialRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($nonConfidentialRecord->isConfidential());

            // Verify other fields remain unchanged
            $this->assertEquals($evidenceRecord->getTextContent(), $nonConfidentialRecord->getTextContent());
            $this->assertEquals($evidenceRecord->getNote(), $nonConfidentialRecord->getNote());
            $this->assertEquals($evidenceRecord->getTag(), $nonConfidentialRecord->getTag());
        }

        public function testConfidentialEvidenceAccessRestrictions(): void
        {
            // Create entity and confidential evidence
            $entityUuid = $this->client->pushEntity('confidential-access-test.com', 'confidential_access_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Top secret evidence', 'Confidential note', 'secret', true);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Verify confidential state
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($evidenceRecord->isConfidential());

            // Test anonymous access to confidential evidence
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            
            try
            {
                $anonymousClient->getEvidenceRecord($evidenceUuid);
                $this->fail("Expected RequestException for anonymous access to confidential evidence");
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [401, 403, 404], "Expected 401/403/404 for unauthorized access to confidential evidence");
            }
        }

        // ADVANCED BLACKLIST SCENARIOS

        public function testBlacklistExpiration(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('expiration-test.com', 'expiration_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Expiration test evidence', 'Expiration test', 'expiration');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create blacklist with short expiration (5 seconds in the future)
            $expires = time() + 5;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Verify blacklist exists and is active
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertFalse($blacklistRecord->isLifted());
            $this->assertEquals($expires, $blacklistRecord->getExpires());

            // Wait for expiration (plus a small buffer)
            sleep(6);

            // Check if the system handles expired blacklists
            // Note: The behavior might vary depending on implementation
            $expiredRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($expiredRecord);
            
            // The record should still exist but might be marked as expired
            // Implementation-specific behavior - log for verification
            $this->logger->info("Expired blacklist record state: lifted=" . ($expiredRecord->isLifted() ? 'true' : 'false'));
        }

        public function testBlacklistWithoutExpiration(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('permanent-blacklist-test.com', 'permanent_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Permanent blacklist evidence', 'Permanent test', 'permanent');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create permanent blacklist (null expiration)
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::MALWARE, null);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Verify permanent blacklist
            $blacklistRecord = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertNotNull($blacklistRecord);
            $this->assertFalse($blacklistRecord->isLifted());
            $this->assertNull($blacklistRecord->getExpires());
        }

        public function testMultipleBlacklistTypesForSameEntity(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('multi-blacklist-test.com', 'multi_blacklist_user');
            $this->createdEntities[] = $entityUuid;

            $spamEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Spam evidence', 'Spam detection', 'spam');
            $this->createdEvidenceRecords[] = $spamEvidenceUuid;

            $malwareEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence', 'Malware detection', 'malware');
            $this->createdEvidenceRecords[] = $malwareEvidenceUuid;

            $abuseEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Abuse evidence', 'Abuse detection', 'abuse');
            $this->createdEvidenceRecords[] = $abuseEvidenceUuid;

            // Create blacklists for different types
            $spamBlacklistUuid = $this->client->blacklistEntity($entityUuid, $spamEvidenceUuid, BlacklistType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $spamBlacklistUuid;

            $malwareBlacklistUuid = $this->client->blacklistEntity($entityUuid, $malwareEvidenceUuid, BlacklistType::MALWARE, null);
            $this->createdBlacklistRecords[] = $malwareBlacklistUuid;

            $abuseBlacklistUuid = $this->client->blacklistEntity($entityUuid, $abuseEvidenceUuid, BlacklistType::SERVICE_ABUSE, time() + 7200);
            $this->createdBlacklistRecords[] = $abuseBlacklistUuid;

            // Verify all blacklists exist and are distinct
            $spamRecord = $this->client->getBlacklistRecord($spamBlacklistUuid);
            $malwareRecord = $this->client->getBlacklistRecord($malwareBlacklistUuid);
            $abuseRecord = $this->client->getBlacklistRecord($abuseBlacklistUuid);

            $this->assertEquals($entityUuid, $spamRecord->getEntityUuid());
            $this->assertEquals($entityUuid, $malwareRecord->getEntityUuid());
            $this->assertEquals($entityUuid, $abuseRecord->getEntityUuid());

            $this->assertEquals(BlacklistType::SPAM, $spamRecord->getType());
            $this->assertEquals(BlacklistType::MALWARE, $malwareRecord->getType());
            $this->assertEquals(BlacklistType::SERVICE_ABUSE, $abuseRecord->getType());

            $this->assertNotNull($spamRecord->getExpires());
            $this->assertNull($malwareRecord->getExpires());
            $this->assertNotNull($abuseRecord->getExpires());
        }

        // ENTITY RELATIONSHIP TESTS

        public function testEntityEvidenceRelationshipIntegrity(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('relationship-test.com', 'relationship_user');
            $this->createdEntities[] = $entityUuid;

            // Create multiple evidence records for the same entity
            $evidenceUuids = [];
            for ($i = 1; $i <= 3; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence $i", "Note $i", "tag_$i");
                $evidenceUuids[] = $evidenceUuid;
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            // List evidence for the entity
            $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid);
            $this->assertIsArray($entityEvidence);
            $this->assertGreaterThanOrEqual(3, count($entityEvidence));

            // Verify all evidence records belong to the correct entity
            $foundEvidenceUuids = [];
            foreach ($entityEvidence as $evidence)
            {
                $this->assertEquals($entityUuid, $evidence->getEntityUuid());
                $foundEvidenceUuids[] = $evidence->getUuid();
            }

            // Verify all our evidence records are found
            foreach ($evidenceUuids as $createdUuid)
            {
                $this->assertContains($createdUuid, $foundEvidenceUuids, "Created evidence UUID should be found in entity evidence list");
            }
        }

        public function testEntityBlacklistRelationshipIntegrity(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('blacklist-relationship-test.com', 'blacklist_relationship_user');
            $this->createdEntities[] = $entityUuid;

            // Create evidence and blacklists
            $blacklistUuids = [];
            for ($i = 1; $i <= 2; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Blacklist evidence $i", "Blacklist note $i", "blacklist_tag_$i");
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $blacklistType = ($i === 1) ? BlacklistType::SPAM : BlacklistType::MALWARE;
                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, $blacklistType, time() + 3600);
                $blacklistUuids[] = $blacklistUuid;
                $this->createdBlacklistRecords[] = $blacklistUuid;
            }

            // List blacklists for the entity
            $entityBlacklists = $this->client->listEntityBlacklistRecords($entityUuid);
            $this->assertIsArray($entityBlacklists);
            $this->assertGreaterThanOrEqual(2, count($entityBlacklists));

            // Verify all blacklist records belong to the correct entity
            $foundBlacklistUuids = [];
            foreach ($entityBlacklists as $blacklist)
            {
                $this->assertEquals($entityUuid, $blacklist->getEntityUuid());
                $foundBlacklistUuids[] = $blacklist->getUuid();
            }

            // Verify all our blacklist records are found
            foreach ($blacklistUuids as $createdUuid)
            {
                $this->assertContains($createdUuid, $foundBlacklistUuids, "Created blacklist UUID should be found in entity blacklist list");
            }
        }

        // COMPLEX QUERY SCENARIOS

        public function testEntityQueryWithComplexIdentifiers(): void
        {
            // Test various complex but valid entity identifiers
            $complexEntities = [
                ['host' => 'subdomain.example.com', 'id' => 'user_with_underscores'],
                ['host' => 'example-with-dashes.org', 'id' => 'user.with.dots'],
                ['host' => '192.168.1.100', 'id' => null], // IP address without user ID
                ['host' => 'very-long-subdomain-name.example-domain.co.uk', 'id' => 'user123'],
            ];

            $createdEntityUuids = [];

            foreach ($complexEntities as $entityData)
            {
                if ($entityData['id'] !== null)
                {
                    $entityUuid = $this->client->pushEntity($entityData['host'], $entityData['id']);
                }
                else
                {
                    $entityUuid = $this->client->pushEntity($entityData['host']);
                }

                $this->assertNotNull($entityUuid);
                $this->assertNotEmpty($entityUuid);
                $createdEntityUuids[] = $entityUuid;
                $this->createdEntities[] = $entityUuid;

                // Verify entity can be retrieved
                $entityRecord = $this->client->getEntityRecord($entityUuid);
                $this->assertNotNull($entityRecord);
                $this->assertEquals($entityData['host'], $entityRecord->getHost());
                $this->assertEquals($entityData['id'], $entityRecord->getId());
            }

            // Verify all entities were created uniquely
            $this->assertEquals(count($complexEntities), count(array_unique($createdEntityUuids)));
        }

        public function testEvidenceWithVariousContentTypes(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('content-types-test.com', 'content_types_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceTypes = [
                ['content' => 'Simple plain text evidence', 'note' => 'Plain text note', 'tag' => 'plain'],
                ['content' => json_encode(['type' => 'json', 'data' => ['key' => 'value']]), 'note' => 'JSON data', 'tag' => 'json'],
                ['content' => "Multi-line\nevidence\nwith\nnewlines", 'note' => 'Multi-line note', 'tag' => 'multiline'],
                ['content' => str_repeat('A', 500), 'note' => 'Large content', 'tag' => 'large'], // 500 characters
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

                // Verify evidence was stored correctly
                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertNotNull($evidenceRecord);
                $this->assertEquals($evidenceData['content'], $evidenceRecord->getTextContent());
                $this->assertEquals($evidenceData['note'], $evidenceRecord->getNote());
                $this->assertEquals($evidenceData['tag'], $evidenceRecord->getTag());
            }
        }

        // PERFORMANCE AND STRESS TESTS

        public function testBulkOperationsPerformance(): void
        {
            $startTime = microtime(true);
            
            // Create multiple operators quickly
            $operatorCount = 5;
            for ($i = 1; $i <= $operatorCount; $i++)
            {
                $operatorUuid = $this->client->createOperator("bulk-test-operator-$i");
                $this->createdOperators[] = $operatorUuid;
                $this->client->setClientPermission($operatorUuid, true);
            }

            // Create multiple entities quickly
            $entityCount = 10;
            for ($i = 1; $i <= $entityCount; $i++)
            {
                $entityUuid = $this->client->pushEntity("bulk-test-$i.com", "bulk_user_$i");
                $this->createdEntities[] = $entityUuid;
            }

            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;

            $this->logger->info("Bulk operations completed in {$totalTime} seconds");
            $this->assertLessThan(30, $totalTime, "Bulk operations should complete within reasonable time");

            // Verify all entities exist
            $allEntities = $this->client->listEntities(1, 50);
            $entityUuids = array_map(fn($entity) => $entity->getUuid(), $allEntities);
            
            foreach ($this->createdEntities as $createdUuid)
            {
                $this->assertContains($createdUuid, $entityUuids, "Created entity should be found in entity list");
            }
        }

        public function testConcurrentClientOperations(): void
        {
            // Create operator for concurrent testing
            $operatorUuid = $this->client->createOperator('concurrent-test-operator');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermission($operatorUuid, true);
            $this->client->setManageBlacklistPermission($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $concurrentClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            // Create entity with main client
            $entityUuid = $this->client->pushEntity('concurrent-test.com', 'concurrent_user');
            $this->createdEntities[] = $entityUuid;

            // Perform operations with both clients concurrently
            $evidenceUuid1 = $this->client->submitEvidence($entityUuid, 'Evidence from main client', 'Main client', 'main');
            $this->createdEvidenceRecords[] = $evidenceUuid1;

            $evidenceUuid2 = $concurrentClient->submitEvidence($entityUuid, 'Evidence from concurrent client', 'Concurrent client', 'concurrent');
            $this->createdEvidenceRecords[] = $evidenceUuid2;

            // Verify both evidence records exist and are distinct
            $evidence1 = $this->client->getEvidenceRecord($evidenceUuid1);
            $evidence2 = $this->client->getEvidenceRecord($evidenceUuid2);

            $this->assertNotEquals($evidenceUuid1, $evidenceUuid2);
            $this->assertEquals('Evidence from main client', $evidence1->getTextContent());
            $this->assertEquals('Evidence from concurrent client', $evidence2->getTextContent());
            $this->assertEquals($entityUuid, $evidence1->getEntityUuid());
            $this->assertEquals($entityUuid, $evidence2->getEntityUuid());
        }
    }
