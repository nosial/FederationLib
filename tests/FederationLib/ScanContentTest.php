<?php

    namespace FederationLib;

    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ScanContentTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('scan-content-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up in reverse dependency order
            foreach ($this->createdBlacklistRecords as $blacklistUuid) {
                try {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage(), $e);
                }
            }

            foreach ($this->createdEvidenceRecords as $evidenceUuid) {
                try {
                    $this->client->deleteEvidence($evidenceUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage(), $e);
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
                    $this->logger->warning("Failed to delete entity record $entityUuid: " . $e->getMessage(), $e);
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
                    $this->logger->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage(), $e);
                }
            }

            // Reset arrays
            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        // BASIC SCAN CONTENT TESTS

        public function testScanContentBasic(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $this->client->pushEntity('example.com', 'contact');

            $content = "Visit https://example.com or email us at contact@example.com";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertCount(3, $results);
            
            // Check that we found URL and email
            $foundTypes = [];
            foreach ($results as $namedEntity) {
                $this->assertInstanceOf(\FederationLib\Objects\NamedEntity::class, $namedEntity);
                $entityPosition = $namedEntity->getEntityPosition();
                $foundTypes[] = $entityPosition->getType();
                
                // Verify position information
                $this->assertGreaterThanOrEqual(0, $entityPosition->getOffset());
                $this->assertGreaterThan(0, $entityPosition->getLength());
                $this->assertNotEmpty($entityPosition->getValue());
            }
            
            $this->assertContains(NamedEntityType::URL, $foundTypes);
            $this->assertContains(NamedEntityType::EMAIL, $foundTypes);
        }

        public function testScanContentEmpty(): void
        {
            $results = $this->client->scanContent("");
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testScanContentNoEntities(): void
        {
            $content = "This is just plain text with no entities to extract.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        // ENTITY DETECTION TESTS

        public function testScanContentDetectsEmails(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com', 'john');
            $this->createdEntities[] = $this->client->pushEntity('test.org', 'alice');
            $this->createdEntities[] = $this->client->pushEntity('company.net', 'support');

            $content = "Contact john@example.com, alice@test.org, or support@company.net for help.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertCount(3, $results);
            
            $foundEmails = [];
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                $this->assertEquals(NamedEntityType::EMAIL, $entityPosition->getType());
                $foundEmails[] = $entityPosition->getValue();
            }
            
            $this->assertContains('john@example.com', $foundEmails);
            $this->assertContains('alice@test.org', $foundEmails);
            $this->assertContains('support@company.net', $foundEmails);
        }

        public function testScanContentDetectsUrls(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $this->client->pushEntity('test.org');
            $this->createdEntities[] = $this->client->pushEntity('secure.site.net');

            $content = "Visit https://example.com, http://test.org, or https://secure.site.net/path?param=value#section";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThanOrEqual(3, count($results));
            
            $foundUrls = [];
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                if ($entityPosition->getType() === NamedEntityType::URL) {
                    $foundUrls[] = $entityPosition->getValue();
                }
            }
            
            $this->assertContains('https://example.com', $foundUrls);
            $this->assertContains('http://test.org', $foundUrls);
        }

        public function testScanContentDetectsDomains(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $this->client->pushEntity('test.org');
            $this->createdEntities[] = $this->client->pushEntity('secure.site.net');

            $content = "Check example.com, test.org, and secure.site.net for more information.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThanOrEqual(3, count($results));
            
            $foundDomains = [];
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                if ($entityPosition->getType() === NamedEntityType::DOMAIN) {
                    $foundDomains[] = $entityPosition->getValue();
                }
            }
            
            $this->assertContains('example.com', $foundDomains);
            $this->assertContains('test.org', $foundDomains);
            $this->assertContains('secure.site.net', $foundDomains);
        }

        public function testScanContentDetectsIpv4(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('192.168.1.1');
            $this->createdEntities[] = $this->client->pushEntity('10.0.0.1');
            $this->createdEntities[] = $this->client->pushEntity('8.8.8.8');

            $content = "Server is at 192.168.1.1 or backup at 10.0.0.1 and public at 8.8.8.8";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThanOrEqual(3, count($results));
            
            $foundIpv4 = [];
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                if ($entityPosition->getType() === NamedEntityType::IPv4) {
                    $foundIpv4[] = $entityPosition->getValue();
                }
            }
            
            $this->assertContains('192.168.1.1', $foundIpv4);
            $this->assertContains('10.0.0.1', $foundIpv4);
            $this->assertContains('8.8.8.8', $foundIpv4);
        }

        public function testScanContentDetectsIpv6(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('2001:db8::1');
            $this->createdEntities[] = $this->client->pushEntity('::1');

            $content = "IPv6 addresses: 2001:db8::1, ::1, and fe80::1%lo0.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThanOrEqual(2, count($results));
            
            $foundIpv6 = [];
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                if ($entityPosition->getType() === NamedEntityType::IPv6) {
                    $foundIpv6[] = $entityPosition->getValue();
                }
            }
            
            $this->assertContains('2001:db8::1', $foundIpv6);
            $this->assertContains('::1', $foundIpv6);
        }

        // REGISTERED ENTITY DETECTION TESTS

        public function testScanContentWithRegisteredEntity(): void
        {
            // Register an entity
            $entityUuid = $this->client->pushEntity('scantest.com', 'testuser');
            $this->createdEntities[] = $entityUuid;
            
            // Scan content containing the registered entity
            $content = "User testuser@scantest.com sent a message.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThan(0, count($results));
            
            // Check if we found entities and they contain query results
            $foundRegisteredEntity = false;
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                $queryResult = $namedEntity->getQueryResult();
                
                if ($entityPosition->getType() === NamedEntityType::EMAIL && 
                    $entityPosition->getValue() === 'testuser@scantest.com') {
                    $foundRegisteredEntity = true;
                    
                    // Verify the query result contains our registered entity
                    $this->assertNotNull($queryResult);
                    $entityRecord = $queryResult->getEntityRecord();
                    $this->assertEquals($entityUuid, $entityRecord->getUuid());
                    $this->assertEquals('scantest.com', $entityRecord->getHost());
                    $this->assertEquals('testuser', $entityRecord->getId());
                    break;
                }
            }
            
            $this->assertTrue($foundRegisteredEntity, 'Should have found the registered entity');
        }

        public function testScanContentWithBlacklistedEntity(): void
        {
            // Register an entity
            $entityUuid = $this->client->pushEntity('malicious.com', 'spammer');
            $this->createdEntities[] = $entityUuid;
            
            // Submit evidence
            $evidenceUuid = $this->client->submitEvidence(
                $entityUuid, 
                'Sending spam messages', 
                'Automated detection', 
                'spam'
            );
            $this->createdEvidenceRecords[] = $evidenceUuid;
            
            // Blacklist the entity
            $expires = time() + 3600;
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            
            // Scan content containing the blacklisted entity
            $content = "Received message from spammer@malicious.com offering free crypto.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThan(0, count($results));
            
            // Check if we found the blacklisted entity and it's marked as blacklisted
            $foundBlacklistedEntity = false;
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                $queryResult = $namedEntity->getQueryResult();
                
                if ($entityPosition->getType() === NamedEntityType::EMAIL && 
                    $entityPosition->getValue() === 'spammer@malicious.com') {
                    $foundBlacklistedEntity = true;
                    
                    // Verify the entity is blacklisted
                    $this->assertNotNull($queryResult);
                    $this->assertTrue($queryResult->isBlacklisted());
                    
                    // Verify we have blacklist records
                    $blacklistRecords = $queryResult->getQueriedBlacklistRecords();
                    $this->assertGreaterThan(0, count($blacklistRecords));
                    
                    // Check the first blacklist record
                    $firstRecord = $blacklistRecords[0];
                    $blacklistRecord = $firstRecord->getBlacklistRecord();
                    $this->assertEquals(BlacklistType::SPAM, $blacklistRecord->getType());
                    $this->assertFalse($blacklistRecord->isLifted());
                    break;
                }
            }
            
            $this->assertTrue($foundBlacklistedEntity, 'Should have found the blacklisted entity');
        }

        public function testScanContentWithMultipleDomainEntities(): void
        {
            // Register multiple domain entities
            $domain1Uuid = $this->client->pushEntity('trusted.com');
            $domain2Uuid = $this->client->pushEntity('suspicious.com');
            $this->createdEntities[] = $domain1Uuid;
            $this->createdEntities[] = $domain2Uuid;
            
            // Blacklist one of them
            $evidenceUuid = $this->client->submitEvidence(
                $domain2Uuid, 
                'Hosting malware', 
                'Security analysis', 
                'malware'
            );
            $this->createdEvidenceRecords[] = $evidenceUuid;
            
            $blacklistUuid = $this->client->blacklistEntity(
                $domain2Uuid, 
                $evidenceUuid, 
                BlacklistType::MALWARE, 
                time() + 3600
            );
            $this->createdBlacklistRecords[] = $blacklistUuid;
            
            // Scan content with both domains
            $content = "Visit trusted.com for legitimate services, but avoid suspicious.com as it contains malware.";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThanOrEqual(2, count($results));
            
            $trustedFound = false;
            $suspiciousFound = false;
            
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                $queryResult = $namedEntity->getQueryResult();
                
                if ($entityPosition->getType() === NamedEntityType::DOMAIN) {
                    if ($entityPosition->getValue() === 'trusted.com') {
                        $trustedFound = true;
                        $this->assertFalse($queryResult->isBlacklisted());
                    } elseif ($entityPosition->getValue() === 'suspicious.com') {
                        $suspiciousFound = true;
                        $this->assertTrue($queryResult->isBlacklisted());
                    }
                }
            }
            
            $this->assertTrue($trustedFound, 'Should have found trusted.com');
            $this->assertTrue($suspiciousFound, 'Should have found suspicious.com');
        }

        // POSITION AND EXTRACTION TESTS

        public function testScanContentEntityPositions(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $this->client->pushEntity('example.com', 'john');

            $content = "Email me at john@example.com or visit https://example.com today!";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertCount(3, $results);
            
            foreach ($results as $namedEntity) {
                $entityPosition = $namedEntity->getEntityPosition();
                
                // Verify the position data makes sense
                $offset = $entityPosition->getOffset();
                $length = $entityPosition->getLength();
                $value = $entityPosition->getValue();
                
                $this->assertGreaterThanOrEqual(0, $offset);
                $this->assertGreaterThan(0, $length);
                $this->assertNotEmpty($value);
                
                // Verify the extracted value matches what's in the original content
                $extractedFromContent = substr($content, $offset, $length);
                $this->assertEquals($extractedFromContent, $value);
            }
        }

        public function testScanContentComplexText(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('bank.com');
            $this->createdEntities[] = $this->client->pushEntity('bank.com', 'support');
            $this->createdEntities[] = $this->client->pushEntity('secure.bank.com');
            $this->createdEntities[] = $this->client->pushEntity('fake-bank.malicious.com');
            $this->createdEntities[] = $this->client->pushEntity('192.168.1.100');

            $content = "Dear customer,\n\nPlease visit our website at https://secure.bank.com/login or call us at 555-123-4567.\n" .
                      "You can also email support@bank.com or visit our branch at 192.168.1.100.\n" .
                      "Beware of phishing sites like fake-bank.malicious.com.\n\n" .
                      "Best regards,\nCustomer Service";
            
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThan(0, count($results));
            
            // Should find URLs, emails, domains, and IP addresses
            $entityTypes = [];
            foreach ($results as $namedEntity) {
                $entityTypes[] = $namedEntity->getEntityPosition()->getType();
            }
            
            // We expect to find various types of entities
            $this->assertContains(NamedEntityType::URL, $entityTypes);
            $this->assertContains(NamedEntityType::EMAIL, $entityTypes);
            $this->assertContains(NamedEntityType::DOMAIN, $entityTypes);
            $this->assertContains(NamedEntityType::IPv4, $entityTypes);
        }

        // AUTHORIZATION TESTS

        public function testScanContentWithoutAuthentication(): void
        {
            // Create unauthenticated client
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            
            // Check server configuration first to see if scan content is public
            $serverInfo = $unauthenticatedClient->getServerInformation();
            
            $content = "Test content with example.com and test@example.com";
            
            try {
                $results = $unauthenticatedClient->scanContent($content);
                
                // If we get here, scan content is public
                $this->assertIsArray($results);
                $this->logger->info("Scan content is publicly accessible");
            } catch (RequestException $e) {
                // If scan content requires authentication, we should get 401
                $this->assertEquals(HttpResponseCode::UNAUTHORIZED, $e->getCode());
                $this->logger->info("Scan content requires authentication");
            }
        }

        // ERROR HANDLING TESTS

        public function testScanContentInvalidInput(): void
        {
            // Test extremely long content
            $longContent = str_repeat('a', 1000000); // 1MB of text
            
            try {
                $results = $this->client->scanContent($longContent);
                $this->assertIsArray($results);
                $this->logger->info("Large content was processed successfully");
            } catch (RequestException $e) {
                // Server might have limits on content size
                $this->logger->info("Large content was rejected: " . $e->getMessage());
            }
        }

        public function testScanContentWithSpecialCharacters(): void
        {
            $content = "Unicode test: 测试@example.com and café@résumé.org with emoji 🚀 visit https://тест.com";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            // Should still be able to extract entities even with special characters
        }

        // PERFORMANCE TESTS

        public function testScanContentPerformance(): void
        {
            $content = str_repeat("Visit https://example.com or email test@domain.com. ", 100);
            
            $startTime = microtime(true);
            $results = $this->client->scanContent($content);
            $endTime = microtime(true);
            
            $executionTime = $endTime - $startTime;
            $this->assertIsArray($results);
            $this->assertLessThan(10.0, $executionTime, "Scan should complete within 10 seconds");
            
            $this->logger->info("Scan content performance: {$executionTime} seconds for " . strlen($content) . " characters");
        }

        // EDGE CASES

        public function testScanContentWithDuplicateEntities(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com', 'admin');

            $content = "Contact admin@example.com or admin@example.com for support at admin@example.com";
            $results = $this->client->scanContent($content);
                
            $this->assertIsArray($results);
            
            // Should find all instances, even if they're duplicates
            $emailCount = 0;
            foreach ($results as $namedEntity) {
                if ($namedEntity->getEntityPosition()->getType() === NamedEntityType::EMAIL) {
                    $emailCount++;
                }
            }
            
            $this->assertEquals(3, $emailCount, "Should find all 3 instances of the email");
        }

        public function testScanContentWithNestedEntities(): void
        {
            $this->createdEntities[] = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $this->client->pushEntity('example.com', 'support');

            // URL contains domain, email contains domain - test priority handling
            $content = "Visit https://example.com/contact or email support@example.com";
            $results = $this->client->scanContent($content);
            
            $this->assertIsArray($results);
            $this->assertGreaterThanOrEqual(2, count($results));
            
            // Should prioritize URL over domain and email over domain
            $foundTypes = [];
            foreach ($results as $namedEntity) {
                $foundTypes[] = $namedEntity->getEntityPosition()->getType();
            }
            
            $this->assertContains(NamedEntityType::URL, $foundTypes);
            $this->assertContains(NamedEntityType::EMAIL, $foundTypes);
        }

        public function testScanContentResultConsistency(): void
        {
            $content = "Contact us at support@example.com or visit https://example.com for more information.";
            
            // Run scan multiple times to ensure consistency
            $firstResults = $this->client->scanContent($content);
            $secondResults = $this->client->scanContent($content);
            $thirdResults = $this->client->scanContent($content);
            
            $this->assertEquals(count($firstResults), count($secondResults));
            $this->assertEquals(count($firstResults), count($thirdResults));
            
            // Compare entity positions and values
            for ($i = 0; $i < count($firstResults); $i++) {
                $first = $firstResults[$i]->getEntityPosition();
                $second = $secondResults[$i]->getEntityPosition();
                $third = $thirdResults[$i]->getEntityPosition();
                
                $this->assertEquals($first->getValue(), $second->getValue());
                $this->assertEquals($first->getValue(), $third->getValue());
                $this->assertEquals($first->getType(), $second->getType());
                $this->assertEquals($first->getType(), $third->getType());
                $this->assertEquals($first->getOffset(), $second->getOffset());
                $this->assertEquals($first->getOffset(), $third->getOffset());
                $this->assertEquals($first->getLength(), $second->getLength());
                $this->assertEquals($first->getLength(), $third->getLength());
            }
        }
    }
