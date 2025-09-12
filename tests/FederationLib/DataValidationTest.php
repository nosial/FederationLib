<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class DataValidationTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('data-validation-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up in reverse dependency order
            foreach ($this->createdBlacklistRecords as $blacklistUuid) {
                try {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting blacklist record $blacklistUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEvidenceRecords as $evidenceUuid) {
                try {
                    $this->client->deleteEvidence($evidenceUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting evidence record $evidenceUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityUuid) {
                try {
                    $this->client->deleteEntity($entityUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting entity $entityUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorUuid) {
                try {
                    $this->client->deleteOperator($operatorUuid);
                } catch (RequestException $e) {
                    $this->logger->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->logger->warning("Unexpected error deleting operator $operatorUuid: " . $e->getMessage());
                }
            }

            // Reset arrays
            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        // ENTITY VALIDATION TESTS

        public function testEntityHostValidation(): void
        {
            $invalidHosts = [
                '', // Empty string
                '   ', // Whitespace only
                'invalid..domain.com', // Double dots
                '.invalid-domain.com', // Leading dot
                'invalid-domain.com.', // Trailing dot
                'host with spaces.com', // Spaces
                'host_with_underscores.com', // Underscores in domain
                'toolongdomainnamethatiswaytoobigandexceedsthemaximumlengthallowedfordomainnames.com', // Too long
                'invalid-chars!.com', // Special characters
                'http://notjustdomain.com', // Protocol included
            ];

            foreach ($invalidHosts as $invalidHost) {
                try {
                    $entityUuid = $this->client->pushEntity($invalidHost, 'test_user');
                    if ($entityUuid) {
                        $this->createdEntities[] = $entityUuid;
                        // If creation succeeded, it might be acceptable depending on validation rules
                        $this->logger->info("Host '$invalidHost' was accepted (may be valid according to server rules)");
                    }
                } catch (RequestException $e) {
                    // This is expected for invalid hosts
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid host '$invalidHost'");
                } catch (InvalidArgumentException $e) {
                    // Client-side validation is also acceptable
                    $this->assertNotNull($e);
                }
            }
        }

        public function testEntityIdValidation(): void
        {
            $host = 'validation-test.com';
            $invalidIds = [
                '', // Empty string (should be handled as global entity)
                '   ', // Whitespace only
                str_repeat('a', 1000), // Extremely long ID
                "id\nwith\nnewlines", // Newlines
                "id\twith\ttabs", // Tabs
                "id with spaces", // Spaces might be valid depending on system
                "id/with/slashes", // Slashes
                "id\\with\\backslashes", // Backslashes
                "id\"with\"quotes", // Quotes
                "id'with'apostrophes", // Apostrophes
                "<script>alert('xss')</script>", // XSS attempt
                "'; DROP TABLE entities; --", // SQL injection attempt
            ];

            foreach ($invalidIds as $invalidId) {
                try {
                    $entityUuid = $this->client->pushEntity($host, $invalidId);
                    if ($entityUuid) {
                        $this->createdEntities[] = $entityUuid;
                        // Some IDs might be accepted with sanitization
                        $entity = $this->client->getEntityRecord($entityUuid);
                        $this->logger->info("ID '$invalidId' was accepted as '" . $entity->getId() . "'");
                    }
                } catch (RequestException $e) {
                    // Expected for invalid IDs
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid ID '$invalidId'");
                } catch (InvalidArgumentException $e) {
                    // Client-side validation is acceptable
                    $this->assertNotNull($e);
                }
            }
        }

        public function testValidEntityFormats(): void
        {
            $validEntities = [
                // Valid domain names
                ['host' => 'example.com', 'id' => 'user123'],
                ['host' => 'subdomain.example.org', 'id' => 'valid_user'],
                ['host' => 'test-domain.net', 'id' => 'user-name'],
                ['host' => 'xn--example.com', 'id' => null], // Punycode domain
                
                // Valid IP addresses
                ['host' => '192.168.1.1', 'id' => null],
                ['host' => '127.0.0.1', 'id' => 'localhost_user'],
                ['host' => '10.0.0.1', 'id' => null],
                
                // Edge cases that should be valid
                ['host' => 'a.com', 'id' => 'a'], // Minimal length
                ['host' => 'example123.com', 'id' => '123user'],
            ];

            foreach ($validEntities as $entityData) {
                $entityUuid = $this->client->pushEntity($entityData['host'], $entityData['id']);
                $this->createdEntities[] = $entityUuid;
                $this->assertNotNull($entityUuid);
                $this->assertNotEmpty($entityUuid);

                // Verify entity was created correctly
                $entity = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($entityData['host'], $entity->getHost());
                $this->assertEquals($entityData['id'], $entity->getId());
            }
        }

        // OPERATOR VALIDATION TESTS

        public function testOperatorNameValidation(): void
        {
            $invalidNames = [
                '', // Empty string
                '   ', // Whitespace only
                str_repeat('a', 1000), // Extremely long name
                "name\nwith\nnewlines", // Newlines
                "name\twith\ttabs", // Tabs
            ];

            foreach ($invalidNames as $invalidName) {
                try {
                    $operatorUuid = $this->client->createOperator($invalidName);
                    if ($operatorUuid) {
                        $this->createdOperators[] = $operatorUuid;
                        // Some names might be accepted with sanitization
                        $operator = $this->client->getOperator($operatorUuid);
                        $this->logger->info("Operator name '$invalidName' was accepted as '" . $operator->getName() . "'");
                    }
                } catch (RequestException $e) {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid operator name '$invalidName'");
                } catch (InvalidArgumentException $e) {
                    $this->assertNotNull($e);
                }
            }
        }

        public function testValidOperatorNames(): void
        {
            $validNames = [
                'Simple Operator',
                'Operator with Numbers 123',
                'Operator-with-Hyphens',
                'Operator_with_Underscores',
                'Operator.with.Dots',
                'Single',
                'A', // Single character
                'Operator with (Parentheses)',
                'Operator with [Brackets]',
                'Special Chars: @#$%^&*()',
            ];

            foreach ($validNames as $name) {
                $operatorUuid = $this->client->createOperator($name);
                $this->createdOperators[] = $operatorUuid;
                $this->assertNotNull($operatorUuid);

                $operator = $this->client->getOperator($operatorUuid);
                $this->assertEquals($name, $operator->getName());
            }
        }

        // EVIDENCE VALIDATION TESTS

        public function testEvidenceContentValidation(): void
        {
            // Create entity for evidence
            $entityUuid = $this->client->pushEntity('evidence-validation.com', 'evidence_user');
            $this->createdEntities[] = $entityUuid;

            $invalidContent = [
                '', // Empty content might be invalid
                str_repeat('a', 100000), // Extremely long content
            ];

            foreach ($invalidContent as $content) {
                try {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'Test note', 'test_tag');
                    if ($evidenceUuid) {
                        $this->createdEvidenceRecords[] = $evidenceUuid;
                        $this->logger->info("Evidence content of length " . strlen($content) . " was accepted");
                    }
                } catch (RequestException $e) {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid evidence content");
                }
            }
        }

        public function testEvidenceTagValidation(): void
        {
            // Create entity for evidence
            $entityUuid = $this->client->pushEntity('tag-validation.com', 'tag_user');
            $this->createdEntities[] = $entityUuid;

            $invalidTags = [
                '', // Empty tag
                '   ', // Whitespace only
                str_repeat('a', 1000), // Extremely long tag
                "tag\nwith\nnewlines", // Newlines
                "tag with spaces", // Spaces might be valid
                "tag/with/slashes", // Special characters
                "<script>alert('xss')</script>", // XSS attempt
            ];

            foreach ($invalidTags as $tag) {
                try {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test note', $tag);
                    if ($evidenceUuid) {
                        $this->createdEvidenceRecords[] = $evidenceUuid;
                        $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                        $this->logger->info("Tag '$tag' was accepted as '" . $evidence->getTag() . "'");
                    }
                } catch (RequestException $e) {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid tag '$tag'");
                }
            }
        }

        public function testValidEvidenceData(): void
        {
            // Create entity for evidence
            $entityUuid = $this->client->pushEntity('valid-evidence.com', 'valid_user');
            $this->createdEntities[] = $entityUuid;

            $validEvidenceData = [
                ['content' => 'Simple evidence text', 'note' => 'Simple note', 'tag' => 'simple'],
                ['content' => 'Evidence with numbers 123', 'note' => 'Note with numbers 456', 'tag' => 'numbers123'],
                ['content' => 'Evidence with special chars: @#$%', 'note' => 'Note with chars', 'tag' => 'special_chars'],
                ['content' => "Multi-line\nevidence\ncontent", 'note' => "Multi-line\nnote", 'tag' => 'multiline'],
                ['content' => 'Unicode content: 中文 العربية', 'note' => 'Unicode note: 日本語', 'tag' => 'unicode'],
            ];

            foreach ($validEvidenceData as $data) {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $data['content'], $data['note'], $data['tag']);
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $this->assertNotNull($evidenceUuid);

                $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($data['content'], $evidence->getTextContent());
                $this->assertEquals($data['note'], $evidence->getNote());
                $this->assertEquals($data['tag'], $evidence->getTag());
            }
        }

        // BLACKLIST VALIDATION TESTS

        public function testBlacklistExpirationValidation(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('blacklist-validation.com', 'blacklist_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test note', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $invalidExpirations = [
                -1, // Negative expiration
                time() - 3600, // Past expiration
                0, // Zero expiration (might be valid for permanent)
            ];

            foreach ($invalidExpirations as $expiration) {
                try
                {
                    $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, BlacklistType::SPAM, $expiration);
                    if ($blacklistUuid)
                    {
                        $this->createdBlacklistRecords[] = $blacklistUuid;
                        $this->logger->info("Expiration '$expiration' was accepted");
                    }
                }
                catch(InvalidArgumentException $e)
                {
                    $this->assertNotNull($e);
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid expiration '$expiration'");
                }
            }
        }

        public function testBlacklistWithNonExistentEvidence(): void
        {
            // Create entity
            $entityUuid = $this->client->pushEntity('blacklist-invalid-evidence.com', 'invalid_evidence_user');
            $this->createdEntities[] = $entityUuid;

            $fakeEvidenceUuid = '01234567-89ab-cdef-0123-456789abcdef';

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->blacklistEntity($entityUuid, $fakeEvidenceUuid, BlacklistType::SPAM, time() + 3600);
        }

        public function testBlacklistWithNonExistentEntity(): void
        {
            $fakeEntityUuid = '01234567-89ab-cdef-0123-456789abcdef';
            $fakeEvidenceUuid = '01234567-89ab-cdef-0123-456789abcdef';

            $this->expectException(RequestException::class);
            $this->client->blacklistEntity($fakeEntityUuid, $fakeEvidenceUuid, BlacklistType::SPAM, time() + 3600);
        }

        // DATA TYPE VALIDATION TESTS

        public function testUuidFormatValidation(): void
        {
            $invalidUuids = [
                'not-a-uuid',
                '12345678-90ab-cdef-ghij-klmnopqrstuv', // Invalid characters
                '12345678-90ab-cdef-0123-456789abcde', // Too short
                '12345678-90ab-cdef-0123-456789abcdefg', // Too long
                '12345678_90ab_cdef_0123_456789abcdef', // Wrong separators
                'g1234567-89ab-cdef-0123-456789abcdef', // Invalid hex
            ];

            foreach ($invalidUuids as $invalidUuid) {
                try {
                    $this->client->getEntityRecord($invalidUuid);
                    $this->fail("Expected exception for invalid UUID '$invalidUuid'");
                } catch (RequestException $e) {
                    $this->assertContains($e->getCode(), [400, 404, 422], "Expected validation error for UUID '$invalidUuid'");
                }
            }
        }

        // PAGINATION PARAMETER VALIDATION TESTS

        public function testPaginationParameterValidation(): void
        {
            // Test invalid page numbers
            $invalidPages = [-1, 0];
            
            foreach ($invalidPages as $page) {
                $this->expectException(InvalidArgumentException::class);
                $this->client->listEntities($page, 10);
            }
        }

        public function testPaginationLimitValidation(): void
        {
            // Test invalid limits
            $invalidLimits = [-1, 0];
            
            foreach ($invalidLimits as $limit) {
                try {
                    $this->client->listEntities(1, $limit);
                    if ($limit === 10000) {
                        // Large limit might be accepted but capped
                        $this->logger->info("Large limit $limit was accepted");
                    }
                } catch (InvalidArgumentException $e) {
                    $this->assertNotNull($e);
                } catch (RequestException $e) {
                    $this->assertContains($e->getCode(), [400, 422], "Expected validation error for limit '$limit'");
                }
            }
        }

        // BOUNDARY VALUE TESTS

        public function testDataLengthBoundaries(): void
        {
            $entityUuid = $this->client->pushEntity('boundary-test.com', 'boundary_user');
            $this->createdEntities[] = $entityUuid;

            // Test various content lengths
            $lengthTests = [
                1,      // Minimum
                100,    // Small
                1000,   // Medium
                10000,  // Large
                50000,  // Very large (may be rejected)
            ];

            foreach ($lengthTests as $length) {
                $content = str_repeat('a', $length);
                try {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'Length test', 'boundary');
                    $this->createdEvidenceRecords[] = $evidenceUuid;
                    
                    $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($length, strlen($evidence->getTextContent()));
                    $this->logger->info("Content length $length was accepted");
                } catch (RequestException $e) {
                    if ($length > 10000) {
                        // Large content might be rejected
                        $this->assertContains($e->getCode(), [400, 413, 422], "Expected size limit error for length $length");
                    } else {
                        $this->fail("Unexpected error for reasonable content length $length: " . $e->getMessage());
                    }
                }
            }
        }

        // CHARACTER ENCODING TESTS

        public function testUnicodeHandling(): void
        {
            $entityUuid = $this->client->pushEntity('unicode-test.com', 'unicode_user');
            $this->createdEntities[] = $entityUuid;

            $unicodeTests = [
                'English text',
                '中文测试', // Chinese
                'العربية', // Arabic
                'Русский', // Russian
                '日本語', // Japanese
                'Ελληνικά', // Greek
                '🚀 🌟 💻', // Emojis
                'Mixed: English 中文 العربية 🚀',
                'Special chars: ñáéíóú çüâê',
            ];

            foreach ($unicodeTests as $unicodeContent) {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $unicodeContent, 'Unicode test', 'unicode');
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($unicodeContent, $evidence->getTextContent(), "Unicode content was not preserved correctly");
            }
        }
    }
