<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ErrorHandlingAndEdgeCasesTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('error-handling-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

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

        // MALFORMED DATA AND INJECTION TESTS

        public function testMalformedEntityIdentifiers(): void
        {
            $malformedIdentifiers = [
                ['host' => '', 'id' => 'user'], // Empty host
                ['host' => 'example.com', 'id' => ''], // Empty user ID  
                ['host' => 'invalid..domain', 'id' => 'user'], // Invalid domain
                ['host' => 'domain.', 'id' => 'user'], // Trailing dot
                ['host' => '.domain.com', 'id' => 'user'], // Leading dot
                ['host' => 'domain-.com', 'id' => 'user'], // Invalid hyphen
                ['host' => str_repeat('a', 300) . '.com', 'id' => 'user'], // Extremely long domain
                ['host' => 'example.com', 'id' => str_repeat('u', 300)], // Extremely long user ID
            ];

            foreach ($malformedIdentifiers as $identifier)
            {
                try
                {
                    if ($identifier['id'] === '')
                    {
                        $this->client->pushEntity($identifier['host'], $identifier['id']);
                    }
                    elseif ($identifier['host'] === '')
                    {
                        $this->client->pushEntity($identifier['host'], $identifier['id']);
                    }
                    else
                    {
                        // These should either succeed (if the validation is lenient) or fail with proper error codes
                        $entityUuid = $this->client->pushEntity($identifier['host'], $identifier['id']);
                        if ($entityUuid !== null)
                        {
                            $this->createdEntities[] = $entityUuid;
                            $this->logger->info("Malformed identifier accepted: {$identifier['host']}/{$identifier['id']}");
                        }
                    }
                }
                catch (RequestException $e)
                {
                    // Expected for malformed data
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400/422 for malformed entity identifier");
                }
                catch (InvalidArgumentException $e)
                {
                    // Also acceptable for client-side validation
                    $this->logger->info("Client-side validation caught malformed identifier: " . $e->getMessage());
                }
            }
        }

        public function testSqlInjectionInEvidenceContent(): void
        {
            // Create a valid entity first
            $entityUuid = $this->client->pushEntity('injection-test.com', 'injection_user');
            $this->createdEntities[] = $entityUuid;

            $sqlInjectionPayloads = [
                "'; DROP TABLE evidence; --",
                "' OR '1'='1",
                "'; INSERT INTO evidence (text_content) VALUES ('injected'); --",
                "' UNION SELECT * FROM operators --",
                "admin'--",
                "admin'/*",
                "' OR 1=1#",
                "') OR ('1'='1",
            ];

            foreach ($sqlInjectionPayloads as $payload)
            {
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $payload, 'SQL injection test', 'injection');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    // If the evidence was created, verify it was stored as literal text
                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($payload, $evidenceRecord->getTextContent(), "SQL injection payload should be stored as literal text");
                }
                catch (RequestException $e)
                {
                    // If the server rejects the content, that's also acceptable
                    $this->logger->info("Server rejected SQL injection payload: " . $e->getMessage());
                }
            }
        }

        public function testXssPayloadsInContent(): void
        {
            // Create a valid entity first
            $entityUuid = $this->client->pushEntity('xss-test.com', 'xss_user');
            $this->createdEntities[] = $entityUuid;

            $xssPayloads = [
                '<script>alert("XSS")</script>',
                '<img src="x" onerror="alert(1)">',
                '<iframe src="javascript:alert(1)"></iframe>',
                '"><script>alert(1)</script>',
                "javascript:alert('XSS')",
                '<svg onload="alert(1)">',
                '<body onload=alert(1)>',
                '<div onclick="alert(1)">Click me</div>',
            ];

            foreach ($xssPayloads as $payload)
            {
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $payload, 'XSS test note', 'xss_test');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    // Verify the payload was stored as literal text (not executed)
                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($payload, $evidenceRecord->getTextContent(), "XSS payload should be stored as literal text");
                }
                catch (RequestException $e)
                {
                    // If the server sanitizes/rejects the content, that's acceptable
                    $this->logger->info("Server handled XSS payload: " . $e->getMessage());
                }
            }
        }

        public function testUnicodeAndSpecialCharacterHandling(): void
        {
            // Create a valid entity first
            $entityUuid = $this->client->pushEntity('unicode-test.com', 'unicode_user');
            $this->createdEntities[] = $entityUuid;

            $unicodeTestCases = [
                'Basic Latin: Hello World',
                'Latin Extended: café résumé naïve',
                'Greek: Ελληνικά',
                'Cyrillic: Русский язык',
                'Chinese: 中文测试',
                'Japanese: こんにちは',
                'Arabic: مرحبا بالعالم',
                'Emoji: 🚀 🌟 ⭐ 🎉',
                'Mathematical: ∑ ∞ ≠ ±',
                'Currency: $ € £ ¥ ₹',
                'Mixed: Hello 世界 🌍 café!',
                'Zero-width chars: a‌b‍c', // Contains zero-width non-joiner and joiner
                'Right-to-left: Hello שלום',
            ];

            foreach ($unicodeTestCases as $testContent)
            {
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $testContent, 'Unicode test', 'unicode');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    // Verify the content was stored correctly
                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($testContent, $evidenceRecord->getTextContent(), "Unicode content should be preserved exactly");
                }
                catch (RequestException $e)
                {
                    $this->fail("Unicode content should be supported: {$testContent}. Error: " . $e->getMessage());
                }
            }
        }

        // BOUNDARY VALUE TESTING

        public function testExtremelyLongContent(): void
        {
            // Create a valid entity first
            $entityUuid = $this->client->pushEntity('long-content-test.com', 'long_content_user');
            $this->createdEntities[] = $entityUuid;

            $contentSizes = [
                1000,    // 1KB
                10000,   // 10KB
                100000,  // 100KB
                1000000, // 1MB
            ];

            foreach ($contentSizes as $size)
            {
                $longContent = str_repeat('A', $size);
                
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $longContent, "Long content test - {$size} chars", 'long_content');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    // Verify the content was stored correctly
                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($size, strlen($evidenceRecord->getTextContent()), "Content length should be preserved");
                    $this->assertEquals($longContent, $evidenceRecord->getTextContent(), "Long content should be stored exactly");
                }
                catch (RequestException $e)
                {
                    // If the server has size limits, this is acceptable
                    if ($e->getCode() === 413 || $e->getCode() === 400)
                    {
                        $this->logger->info("Server rejected content of size {$size}: " . $e->getMessage());
                    }
                    else
                    {
                        throw $e;
                    }
                }
            }
        }

        public function testZeroLengthAndWhitespaceContent(): void
        {
            // Create a valid entity first
            $entityUuid = $this->client->pushEntity('whitespace-test.com', 'whitespace_user');
            $this->createdEntities[] = $entityUuid;

            $whitespaceTestCases = [
                ['content' => '', 'description' => 'empty string'],
                ['content' => ' ', 'description' => 'single space'],
                ['content' => "\t", 'description' => 'single tab'],
                ['content' => "\n", 'description' => 'single newline'],
                ['content' => "\r\n", 'description' => 'CRLF'],
                ['content' => '   ', 'description' => 'multiple spaces'],
                ['content' => "\t\t\t", 'description' => 'multiple tabs'],
                ['content' => "\n\n\n", 'description' => 'multiple newlines'],
                ['content' => " \t\n\r ", 'description' => 'mixed whitespace'],
            ];

            foreach ($whitespaceTestCases as $testCase)
            {
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $testCase['content'], 'Whitespace test: ' . $testCase['description'], 'whitespace');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    // Verify the content was stored exactly as provided
                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($testCase['content'], $evidenceRecord->getTextContent(), "Whitespace content should be preserved exactly: " . $testCase['description']);
                }
                catch (RequestException $e)
                {
                    // Some systems might reject empty or whitespace-only content
                    if ($testCase['content'] === '' && ($e->getCode() === 400 || $e->getCode() === 422))
                    {
                        $this->logger->info("Server rejected empty content (acceptable): " . $e->getMessage());
                    }
                    else
                    {
                        $this->fail("Whitespace content should be accepted: " . $testCase['description'] . ". Error: " . $e->getMessage());
                    }
                }
            }
        }

        // CONCURRENT OPERATION EDGE CASES

        public function testConcurrentDeleteOperations(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('concurrent-delete-test.com', 'concurrent_delete_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for concurrent delete test', 'Concurrent delete', 'concurrent_delete');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create blacklist
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // Create second client with same credentials
            $secondClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));

            // Try to delete the blacklist record from both clients simultaneously
            $firstDeleteSuccess = false;
            $secondDeleteSuccess = false;

            try
            {
                $this->client->deleteBlacklistRecord($blacklistUuid);
                $firstDeleteSuccess = true;
            }
            catch (RequestException $e)
            {
                $this->logger->info("First delete failed: " . $e->getMessage());
            }

            try
            {
                $secondClient->deleteBlacklistRecord($blacklistUuid);
                $secondDeleteSuccess = true;
            }
            catch (RequestException $e)
            {
                $this->logger->info("Second delete failed: " . $e->getMessage());
                // This is expected if the first delete succeeded
                if ($firstDeleteSuccess)
                {
                    $this->assertEquals(404, $e->getCode(), "Second delete should fail with 404 if first succeeded");
                }
            }

            // Exactly one delete should succeed
            $this->assertTrue($firstDeleteSuccess || $secondDeleteSuccess, "At least one delete should succeed");
            
            if ($firstDeleteSuccess && $secondDeleteSuccess)
            {
                $this->fail("Both deletes should not succeed simultaneously");
            }

            // Remove from cleanup array since it's already deleted
            $this->createdBlacklistRecords = array_filter($this->createdBlacklistRecords, fn($uuid) => $uuid !== $blacklistUuid);
        }

        public function testConcurrentModificationOperations(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('concurrent-modify-test.com', 'concurrent_modify_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for concurrent modification', 'Concurrent modify', 'concurrent_modify');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create second client
            $secondClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));

            // Try to modify evidence confidentiality from both clients
            try
            {
                $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
                $secondClient->updateEvidenceConfidentiality($evidenceUuid, false);

                // Check final state
                $finalEvidence = $this->client->getEvidenceRecord($evidenceUuid);
                $this->logger->info("Final evidence confidentiality state: " . ($finalEvidence->isConfidential() ? 'true' : 'false'));
                
                // Either state is acceptable, but the evidence should still exist and be valid
                $this->assertNotNull($finalEvidence);
                $this->assertEquals($entityUuid, $finalEvidence->getEntityUuid());
            }
            catch (RequestException $e)
            {
                // If concurrent modifications are handled with locks/conflicts, that's acceptable
                $this->logger->info("Concurrent modification handled: " . $e->getMessage());
            }
        }

        // RESOURCE EXHAUSTION TESTS

        public function testRapidRequestSequence(): void
        {
            $startTime = microtime(true);
            $requestCount = 20;
            $successCount = 0;
            $throttleCount = 0;

            for ($i = 1; $i <= $requestCount; $i++)
            {
                try
                {
                    $serverInfo = $this->client->getServerInformation();
                    $this->assertNotNull($serverInfo);
                    $successCount++;
                }
                catch (RequestException $e)
                {
                    if ($e->getCode() === 429) // Too Many Requests
                    {
                        $throttleCount++;
                        $this->logger->info("Request $i was throttled");
                        // Brief pause to respect rate limiting
                        usleep(100000); // 0.1 second
                    }
                    else
                    {
                        throw $e;
                    }
                }
            }

            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;

            $this->assertGreaterThan(0, $successCount, "At least some requests should succeed");
            $this->logger->info("Rapid request test: {$successCount} successful, {$throttleCount} throttled in {$totalTime} seconds");

            // If rate limiting is in place, we should see some throttled requests
            if ($throttleCount > 0)
            {
                $this->logger->info("Rate limiting is active (good)");
            }
        }

        public function testMemoryIntensiveOperations(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('memory-test.com', 'memory_user');
            $this->createdEntities[] = $entityUuid;

            $initialMemory = memory_get_usage();

            // Create multiple evidence records with varying content sizes
            $evidenceCount = 10;
            for ($i = 1; $i <= $evidenceCount; $i++)
            {
                $contentSize = $i * 1000; // Increasing sizes: 1KB, 2KB, 3KB, etc.
                $content = str_repeat("Data $i ", $contentSize / 7); // Approximate size
                
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, "Memory test $i", "memory_$i");
                    $this->createdEvidenceRecords[] = $evidenceUuid;
                }
                catch (RequestException $e)
                {
                    if ($e->getCode() === 413 || $e->getCode() === 507) // Payload Too Large or Insufficient Storage
                    {
                        $this->logger->info("Server rejected large content (size ~{$contentSize}): " . $e->getMessage());
                        break; // Stop if we hit server limits
                    }
                    else
                    {
                        throw $e;
                    }
                }
            }

            $finalMemory = memory_get_usage();
            $memoryUsed = $finalMemory - $initialMemory;

            $this->logger->info("Memory used during test: " . number_format($memoryUsed) . " bytes");

            // Verify we can still retrieve evidence records
            foreach (array_slice($this->createdEvidenceRecords, -3) as $evidenceUuid)
            {
                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertNotNull($evidenceRecord);
                $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            }
        }

        // NETWORK AND CONNECTIVITY EDGE CASES

        public function testClientResilienceToServerDelay(): void
        {
            // Test operations that might take longer due to server processing
            $startTime = microtime(true);

            try
            {
                // Create entity with complex identifier that might require more processing
                $complexHost = str_repeat('subdomain.', 10) . 'example.com'; // Very long subdomain chain
                $entityUuid = $this->client->pushEntity($complexHost, 'resilience_user');
                $this->createdEntities[] = $entityUuid;

                // Create evidence with large content
                $largeContent = str_repeat('This is a test sentence. ', 1000); // ~26KB
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $largeContent, 'Resilience test', 'resilience');
                $this->createdEvidenceRecords[] = $evidenceUuid;

                // Verify operations completed successfully
                $entityRecord = $this->client->getEntityRecord($entityUuid);
                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);

                $this->assertNotNull($entityRecord);
                $this->assertNotNull($evidenceRecord);
                $this->assertEquals($complexHost, $entityRecord->getHost());
                $this->assertEquals($largeContent, $evidenceRecord->getTextContent());
            }
            catch (RequestException $e)
            {
                // If server has restrictions on complex identifiers or large content, that's acceptable
                $this->logger->info("Server handled resilience test: " . $e->getMessage());
            }

            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;

            $this->logger->info("Resilience test completed in {$totalTime} seconds");
            $this->assertLessThan(30, $totalTime, "Operations should complete within reasonable time even with complex data");
        }

        // CLEANUP AND CONSISTENCY TESTS

        public function testOperationRollbackOnFailure(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('rollback-test.com', 'rollback_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Rollback test evidence', 'Rollback test', 'rollback');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Try to create a blacklist with invalid parameters to test rollback
            try
            {
                // Use negative expiration time (should be invalid)
                $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, -1);
                $this->fail("Expected InvalidArgumentException for negative expiration");
            }
            catch (InvalidArgumentException $e)
            {
                // Expected - verify no partial state was created
                $this->logger->info("Negative expiration correctly rejected: " . $e->getMessage());
            }
            catch (RequestException $e)
            {
                // Also acceptable if server-side validation catches it
                $this->assertEquals(400, $e->getCode(), "Expected 400 for invalid expiration");
            }

            // Verify entity and evidence still exist and are unchanged
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);

            $this->assertNotNull($entityRecord);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals('Rollback test evidence', $evidenceRecord->getTextContent());
        }

        public function testDataConsistencyAfterErrors(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('consistency-test.com', 'consistency_user');
            $this->createdEntities[] = $entityUuid;

            // Create evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Consistency test', 'Consistency', 'consistency');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Try various invalid operations
            try
            {
                $this->client->getEvidenceRecord('invalid-uuid');
            }
            catch (RequestException $e)
            {
                // Expected
            }

            try
            {
                $this->client->blacklistEntity('invalid-entity-uuid', $evidenceUuid, BlacklistType::SPAM);
            }
            catch (RequestException $e)
            {
                // Expected
            }

            // Verify original data is still intact and consistent
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);

            $this->assertNotNull($entityRecord);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals('consistency-test.com', $entityRecord->getHost());
            $this->assertEquals('consistency_user', $entityRecord->getId());
            $this->assertEquals('Consistency test', $evidenceRecord->getTextContent());
        }
    }
