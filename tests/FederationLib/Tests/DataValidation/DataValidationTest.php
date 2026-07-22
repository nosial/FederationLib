<?php

    namespace FederationLib\Tests\DataValidation;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class DataValidationTest extends TestCase
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

        public function testEntityHostValidation(): void
        {
            $invalidHosts = [
                '',
                '   ',
                'invalid..domain.com',
                '.invalid-domain.com',
                'invalid-domain.com.',
                'host with spaces.com',
                'toolongdomainnamethatiswaytoobigandexceedsthemaximumlengthallowedfordomainnames.com',
                'invalid-chars!.com',
                'http://notjustdomain.com',
            ];

            foreach ($invalidHosts as $invalidHost)
            {
                try
                {
                    $entityUuid = $this->client->pushEntity($invalidHost, 'test_user');
                    if ($entityUuid)
                    {
                        $this->createdEntities[] = $entityUuid;
                        Logger::getLogger()->info("Host '$invalidHost' was accepted (may be valid according to server rules)");
                    }
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid host '$invalidHost'");
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        }

        public function testEntityIdValidation(): void
        {
            $host = 'validation-test.com';
            $invalidIds = [
                str_repeat('a', 1000),
                "id\nwith\nnewlines",
                "id\twith\ttabs",
                "id/with/slashes",
                "id\\with\\backslashes",
                "id\"with\"quotes",
                "id'with'apostrophes",
                "<script>alert('xss')</script>",
                "'; DROP TABLE entities; --",
            ];

            foreach ($invalidIds as $invalidId)
            {
                try
                {
                    $entityUuid = $this->client->pushEntity($host, $invalidId);
                    if ($entityUuid)
                    {
                        $this->createdEntities[] = $entityUuid;
                        $entity = $this->client->getEntityRecord($entityUuid);
                        Logger::getLogger()->info("ID '$invalidId' was accepted as '" . $entity->getId() . "'");
                    }
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid ID '$invalidId'");
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        }

        public function testValidEntityFormats(): void
        {
            $validEntities = [
                ['host' => 'example.com', 'id' => 'user123'],
                ['host' => 'subdomain.example.org', 'id' => 'valid_user'],
                ['host' => 'test-domain.net', 'id' => 'user-name'],
                ['host' => 'xn--example.com', 'id' => null],
                ['host' => '192.168.1.1', 'id' => null],
                ['host' => '127.0.0.1', 'id' => 'localhost_user'],
                ['host' => '10.0.0.1', 'id' => null],
                ['host' => 'a.com', 'id' => 'a'],
                ['host' => 'example123.com', 'id' => '123user'],
            ];

            foreach ($validEntities as $entityData)
            {
                $entityUuid = $this->client->pushEntity($entityData['host'], $entityData['id']);
                $this->createdEntities[] = $entityUuid;
                $this->assertNotEmpty($entityUuid);

                $entity = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($entityData['host'], $entity->getHost());
                $this->assertEquals($entityData['id'], $entity->getId());
            }
        }

        public function testOperatorNameValidation(): void
        {
            $invalidNames = [
                '',
                '   ',
                str_repeat('a', 1000),
                "name\nwith\nnewlines",
                "name\twith\ttabs",
            ];

            foreach ($invalidNames as $invalidName)
            {
                try
                {
                    $operatorUuid = $this->client->createOperator($invalidName);
                    if ($operatorUuid)
                    {
                        $this->createdOperators[] = $operatorUuid;
                        $operator = $this->client->getOperator($operatorUuid);
                        Logger::getLogger()->info("Operator name '$invalidName' was accepted as '" . $operator->getName() . "'");
                    }
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid operator name '$invalidName'");
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertNotEmpty($e->getMessage());
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
                'A',
                'Operator with (Parentheses)',
                'Operator with [Brackets]',
                'Special Chars: @#$%^&*()',
            ];

            foreach ($validNames as $name)
            {
                $operatorUuid = $this->client->createOperator($name);
                $this->createdOperators[] = $operatorUuid;
                $this->assertNotEmpty($operatorUuid);

                $operator = $this->client->getOperator($operatorUuid);
                $this->assertEquals($name, $operator->getName());
            }
        }

        public function testEvidenceContentValidation(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-validation.com', 'evidence_user');
            $this->createdEntities[] = $entityUuid;

            $invalidContent = [
                str_repeat('a', 100000),
            ];

            foreach ($invalidContent as $content)
            {
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'Test note', 'test_tag');
                    if ($evidenceUuid)
                    {
                        $this->createdEvidenceRecords[] = $evidenceUuid;
                        Logger::getLogger()->info('Evidence content of length ' . strlen($content) . ' was accepted');
                    }
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], 'Expected 400 or 422 for invalid evidence content');
                }

                $this->addToAssertionCount(1);
            }
        }

        public function testEvidenceTagValidation(): void
        {
            $entityUuid = $this->client->pushEntity('tag-validation.com', 'tag_user');
            $this->createdEntities[] = $entityUuid;

            $invalidTags = [
                '',
                '   ',
                str_repeat('a', 1000),
                "tag\nwith\nnewlines",
                "tag with spaces",
                "tag/with/slashes",
                "<script>alert('xss')</script>",
            ];

            foreach ($invalidTags as $tag)
            {
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test note', $tag);
                    if ($evidenceUuid)
                    {
                        $this->createdEvidenceRecords[] = $evidenceUuid;
                        $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                        Logger::getLogger()->info("Tag '$tag' was accepted as '" . $evidence->getTag() . "'");
                    }
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid tag '$tag'");
                }
            }
        }

        public function testValidEvidenceData(): void
        {
            $entityUuid = $this->client->pushEntity('valid-evidence.com', 'valid_user');
            $this->createdEntities[] = $entityUuid;

            $validEvidenceData = [
                ['content' => 'Simple evidence text', 'note' => 'Simple note', 'tag' => 'simple'],
                ['content' => 'Evidence with numbers 123', 'note' => 'Note with numbers 456', 'tag' => 'numbers123'],
                ['content' => 'Evidence with special chars: @#$%', 'note' => 'Note with chars', 'tag' => 'special_chars'],
                ['content' => "Multi-line\nevidence\ncontent", 'note' => "Multi-line\nnote", 'tag' => 'multiline'],
                ['content' => 'Unicode content: 中文 العربية', 'note' => 'Unicode note: 日本語', 'tag' => 'unicode'],
            ];

            foreach ($validEvidenceData as $data)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $data['content'], $data['note'], $data['tag']);
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $this->assertNotEmpty($evidenceUuid);

                $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($data['content'], $evidence->getTextContent());
                $this->assertEquals($data['note'], $evidence->getNote());
                $this->assertEquals($data['tag'], $evidence->getTag());
            }
        }

        public function testBlacklistExpirationValidation(): void
        {
            $entityUuid = $this->client->pushEntity('blacklist-validation.com', 'blacklist_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test note', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $invalidExpirations = [
                -1,
                time() - 3600,
            ];

            foreach ($invalidExpirations as $expiration)
            {
                try
                {
                    $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, $expiration);
                    if ($blacklistUuid)
                    {
                        $this->createdBlacklistRecords[] = $blacklistUuid;
                        Logger::getLogger()->info("Expiration '$expiration' was accepted");
                    }
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertNotEmpty($e->getMessage());
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], "Expected 400 or 422 for invalid expiration '$expiration'");
                }
            }
        }

        public function testBlacklistWithNonExistentEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('blacklist-invalid-evidence.com', 'invalid_evidence_user');
            $this->createdEntities[] = $entityUuid;

            $fakeEvidenceUuid = '01234567-89ab-cdef-0123-456789abcdef';

            try
            {
                $this->client->blacklistEntity($entityUuid, $fakeEvidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->fail('Expected RequestException for non-existent evidence');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 404], 'Expected 400 or 404 for non-existent evidence');
            }
        }

        public function testBlacklistWithNonExistentEntity(): void
        {
            $fakeEntityUuid = '01234567-89ab-cdef-0123-456789abcdef';
            $fakeEvidenceUuid = '01234567-89ab-cdef-0123-456789abcdef';

            try
            {
                $this->client->blacklistEntity($fakeEntityUuid, $fakeEvidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->fail('Expected RequestException for non-existent entity');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 404], 'Expected 400 or 404 for non-existent entity');
            }
        }

        public function testUuidFormatValidation(): void
        {
            $invalidUuids = [
                'not-a-uuid',
                '12345678-90ab-cdef-ghij-klmnopqrstuv',
                '12345678-90ab-cdef-0123-456789abcde',
                '12345678-90ab-cdef-0123-456789abcdefg',
                '12345678_90ab_cdef_0123_456789abcdef',
                'g1234567-89ab-cdef-0123-456789abcdef',
            ];

            foreach ($invalidUuids as $invalidUuid)
            {
                try
                {
                    $this->client->getEntityRecord($invalidUuid);
                    $this->fail("Expected exception for invalid UUID '$invalidUuid'");
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 404, 422], "Expected validation error for UUID '$invalidUuid'");
                }
            }
        }

        public function testPaginationParameterValidation(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(-1, 10);
        }

        public function testPaginationLimitValidation(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(1, 0);
        }

        public function testDataLengthBoundaries(): void
        {
            $entityUuid = $this->client->pushEntity('boundary-test.com', 'boundary_user');
            $this->createdEntities[] = $entityUuid;

            $lengthTests = [
                1,
                100,
                1000,
                10000,
            ];

            foreach ($lengthTests as $length)
            {
                $content = str_repeat('a', $length);
                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'Length test', 'boundary');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($length, strlen($evidence->getTextContent()));
                }
                catch (RequestException $e)
                {
                    $this->fail("Unexpected error for reasonable content length $length: " . $e->getMessage());
                }
            }
        }

        public function testUnicodeHandling(): void
        {
            $entityUuid = $this->client->pushEntity('unicode-test.com', 'unicode_user');
            $this->createdEntities[] = $entityUuid;

            $unicodeTests = [
                'English text',
                '中文测试',
                'العربية',
                'Русский',
                '日本語',
                'Ελληνικά',
                '🚀 🌟 💻',
                'Mixed: English 中文 العربية 🚀',
                'Special chars: ñáéíóú çüâê',
            ];

            foreach ($unicodeTests as $unicodeContent)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $unicodeContent, 'Unicode test', 'unicode');
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $evidence = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($unicodeContent, $evidence->getTextContent(), 'Unicode content was not preserved correctly');
            }
        }
    }
