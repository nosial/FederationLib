<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace FederationLib\Tests\ErrorHandling;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ErrorHandlingTest extends TestCase
    {
        use TestHelpers;
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

        public function testMalformedEntityIdentifiers(): void
        {
            $malformedIdentifiers = [
                ['host' => '', 'id' => 'user'],
                ['host' => 'example.com', 'id' => ''],
                ['host' => 'invalid..domain', 'id' => 'user'],
                ['host' => 'domain.', 'id' => 'user'],
                ['host' => '.domain.com', 'id' => 'user'],
                ['host' => 'domain-.com', 'id' => 'user'],
                ['host' => str_repeat('a', 300) . '.com', 'id' => 'user'],
                ['host' => 'example.com', 'id' => str_repeat('u', 300)],
            ];

            foreach ($malformedIdentifiers as $identifier)
            {
                try
                {
                    $entityUuid = $this->client->pushEntity($identifier['host'], $identifier['id']);
                    if ($entityUuid)
                    {
                        $this->createdEntities[] = $entityUuid;
                        Logger::getLogger()->info("Malformed identifier accepted: {$identifier['host']}/{$identifier['id']}");
                    }
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 422], 'Expected 400/422 for malformed entity identifier');
                }
                catch (InvalidArgumentException $e)
                {
                    Logger::getLogger()->info('Client-side validation caught malformed identifier: ' . $e->getMessage());
                }
            }
        }

        public function testSqlInjectionInEvidenceContent(): void
        {
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

                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($payload, $evidenceRecord->getTextContent(), 'SQL injection payload should be stored as literal text');
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->info('Server rejected SQL injection payload: ' . $e->getMessage());
                    $this->assertContains($e->getCode(), [400, 422], 'Expected 400/422 for rejected SQL injection payload');
                }
            }
        }

        public function testXssPayloadsInContent(): void
        {
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

                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($payload, $evidenceRecord->getTextContent(), 'XSS payload should be stored as literal text');
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->info('Server handled XSS payload: ' . $e->getMessage());
                    $this->assertContains($e->getCode(), [400, 422], 'Expected 400/422 for rejected XSS payload');
                }
            }
        }

        public function testUnicodeAndSpecialCharacterHandling(): void
        {
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
                'Right-to-left: Hello שלום',
            ];

            foreach ($unicodeTestCases as $testContent)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $testContent, 'Unicode test', 'unicode');
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($testContent, $evidenceRecord->getTextContent(), 'Unicode content should be preserved exactly');
            }
        }

        public function testExtremelyLongContent(): void
        {
            $entityUuid = $this->client->pushEntity('long-content-test.com', 'long_content_user');
            $this->createdEntities[] = $entityUuid;

            $contentSizes = [
                1000,
                10000,
                100000,
            ];

            foreach ($contentSizes as $size)
            {
                $longContent = str_repeat('A', $size);

                try
                {
                    $evidenceUuid = $this->client->submitEvidence($entityUuid, $longContent, "Long content test - $size chars", 'long_content');
                    $this->createdEvidenceRecords[] = $evidenceUuid;

                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($size, strlen($evidenceRecord->getTextContent()), 'Content length should be preserved');
                    $this->assertEquals($longContent, $evidenceRecord->getTextContent(), 'Long content should be stored exactly');
                }
                catch (RequestException $e)
                {
                    if ($e->getCode() === 413 || $e->getCode() === 400)
                    {
                        Logger::getLogger()->info("Server rejected content of size $size: " . $e->getMessage());
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
            $entityUuid = $this->client->pushEntity('whitespace-test.com', 'whitespace_user');
            $this->createdEntities[] = $entityUuid;

            $whitespaceTestCases = [
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

                    $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                    $this->assertEquals($testCase['content'], $evidenceRecord->getTextContent(), 'Whitespace content should be preserved exactly: ' . $testCase['description']);
                }
                catch (RequestException $e)
                {
                    $this->fail('Whitespace content should be accepted: ' . $testCase['description'] . '. Error: ' . $e->getMessage());
                }
            }
        }

        public function testConcurrentDeleteOperations(): void
        {
            $entityUuid = $this->client->pushEntity('concurrent-delete-test.com', 'concurrent_delete_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for concurrent delete test', 'Concurrent delete', 'concurrent_delete');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $secondClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));

            $this->client->deleteBlacklistRecord($blacklistUuid);
            array_splice($this->createdBlacklistRecords, array_search($blacklistUuid, $this->createdBlacklistRecords), 1);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404);
            $secondClient->deleteBlacklistRecord($blacklistUuid);
        }

        public function testOperationRollbackOnFailure(): void
        {
            $entityUuid = $this->client->pushEntity('rollback-test.com', 'rollback_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Rollback test evidence', 'Rollback test', 'rollback');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, -1);
        }

        public function testDataConsistencyAfterErrors(): void
        {
            $entityUuid = $this->client->pushEntity('consistency-test.com', 'consistency_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Consistency test', 'Consistency', 'consistency');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            try
            {
                $this->client->getEvidenceRecord('invalid-uuid');
                $this->fail('Expected RequestException for invalid UUID');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 404, 422]);
            }

            try
            {
                $this->client->blacklistEntity('invalid-entity-uuid', $evidenceUuid, IncidentType::SPAM);
                $this->fail('Expected RequestException for invalid entity UUID');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 404, 422]);
            }

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);

            $this->assertNotNull($entityRecord);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals('consistency-test.com', $entityRecord->getHost());
            $this->assertEquals('consistency_user', $entityRecord->getId());
            $this->assertEquals('Consistency test', $evidenceRecord->getTextContent());
        }

        public function testSecurityRawMalformedRequestsAreRejected(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');

            [$malformedJsonCode] = $this->rawRequest('POST', 'reports', $token, '{this is not json', ['Content-Type: application/json']);
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $malformedJsonCode, 'Malformed JSON should be rejected');

            [$missingFieldsCode] = $this->rawRequest('POST', 'reports', $token, '{}', ['Content-Type: application/json']);
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $missingFieldsCode, 'Missing required fields should be rejected');

            [$wrongContentTypeCode] = $this->rawRequest('POST', 'scan', $token, 'content=hello', ['Content-Type: text/plain']);
            $this->assertContains(
                $wrongContentTypeCode,
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                'Wrong content type or missing content should be rejected'
            );
        }

        public function testJsonContentTypeWithCharsetIsParsed(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');
            $entityUuid = $this->client->pushEntity('charset-json.com', 'charset_user');
            $this->createdEntities[] = $entityUuid;

            [$code, $response] = $this->rawRequest(
                'POST',
                'evidence',
                $token,
                json_encode([
                    'entity_identifier' => $entityUuid,
                    'text_content' => 'Charset JSON test',
                    'note' => 'Note',
                    'tag' => 'charset'
                ]),
                ['Content-Type: application/json; charset=utf-8']
            );

            $this->assertEquals(HttpResponseCode::CREATED->value, $code, 'JSON with charset should be accepted: ' . $response);
            $decoded = json_decode($response, true);
            $this->assertNotEmpty($decoded);
        }

        public function testEmptyRequestBodyIsRejected(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');

            [$emptyBodyCode] = $this->rawRequest('POST', 'entities', $token, '', ['Content-Type: application/json']);
            $this->assertContains(
                $emptyBodyCode,
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNPROCESSABLE_CONTENT->value, HttpResponseCode::INTERNAL_SERVER_ERROR->value],
                'Empty JSON body should be rejected'
            );
        }

        public function testBoundaryPaginationValues(): void
        {
            $entityUuid = $this->client->pushEntity('boundary-page.com', 'boundary_user');
            $this->createdEntities[] = $entityUuid;

            // Page size of 1 should return exactly one record if any exist.
            $pageOne = $this->client->listEntities(1, 1);
            $this->assertLessThanOrEqual(1, count($pageOne));

            // A very large page number should return no results without error.
            $largePage = $this->client->listEntities(99999, 10);
            $this->assertIsArray($largePage);
            $this->assertEmpty($largePage);
        }

        public function testInvalidUuidFormatsAreRejected(): void
        {
            $invalidUuids = [
                'not-a-uuid',
                '12345',
                'gggggggg-gggg-gggg-gggg-gggggggggggg',
                '0198f41f-45c7-78eb-a2a7-86de4e99991a-extra',
            ];

            foreach ($invalidUuids as $uuid)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->getEntityRecord($uuid),
                    [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::NOT_FOUND->value],
                    "Invalid UUID '$uuid' should be rejected"
                );
            }
        }

        public function testMethodNotAllowedForInvalidVerb(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');
            [$code] = $this->rawRequest('TRACE', 'info', $token);
            $this->assertContains(
                $code,
                [HttpResponseCode::METHOD_NOT_ALLOWED->value, HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::NOT_FOUND->value],
                'TRACE request should not be allowed'
            );
        }

        public function testLargePageLimitIsCappedByServer(): void
        {
            $requestedLimit = 10000;

            $page = $this->client->listEntities(1, $requestedLimit);
            $this->assertLessThanOrEqual(100, count($page), 'Server should cap list limit to a reasonable maximum');
        }

        public function testDuplicateEntitySubmissionIsIdempotent(): void
        {
            $host = 'idempotent-entity.com';
            $id = 'idempotent_user';

            $firstUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $firstUuid;

            for ($i = 0; $i < 5; $i++)
            {
                $duplicateUuid = $this->client->pushEntity($host, $id);
                $this->assertEquals($firstUuid, $duplicateUuid);
            }

            $allEntities = $this->client->listEntities(1, 1000);
            $matchingUuids = array_filter($allEntities, fn($e) => $e->getUuid() === $firstUuid);
            $this->assertCount(1, $matchingUuids, 'Duplicate pushes should not create multiple entity records');
        }

        public function testInvalidIncidentTypeIsRejected(): void
        {
            $entityUuid = $this->client->pushEntity('invalid-incident.com', 'user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence', 'Note', 'invalid');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $token = getenv('SERVER_ACCESS_TOKEN');
            [$code] = $this->rawRequest(
                'POST',
                'blacklist',
                $token,
                json_encode([
                    'entity_uuid' => $entityUuid,
                    'evidence_uuid' => $evidenceUuid,
                    'incident_type' => 'NOT_VALID',
                    'expires' => time() + 3600,
                ])
            );

            $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value], 'Invalid incident type should be rejected');
        }

        public function testInvalidClassificationFlagIsRejected(): void
        {
            $report = $this->createSecurityReport();
            $manager = $this->createLimitedOperator('invalid_class_manager', management: true);
            $manager->assignOperatorToReport($report['report'], $manager->getSelf()->getUuid());

            $token = $manager->getAccessToken();
            [$code] = $this->rawRequest(
                'PATCH',
                'reports/' . $report['report'] . '/close',
                $token,
                json_encode(['classification_flag' => 'NOT_A_FLAG'])
            );

            $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value], 'Invalid classification flag should be rejected');
        }

        public function testInvalidRelationshipTypeIsRejected(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $token = $this->client->getAccessToken();
            [$code] = $this->rawRequest(
                'PATCH',
                'entities/' . $entityA . '/relationship',
                $token,
                json_encode([
                    'target_entity_uuid' => $entityB,
                    'relationship_type' => 'INVALID_RELATIONSHIP',
                ])
            );

            $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value], 'Invalid relationship type should be rejected');
        }

        public function testTypeConfusionInJsonBodyIsRejected(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');

            // entity_uuid should be a string, not an array.
            [$code] = $this->rawRequest(
                'POST',
                'evidence',
                $token,
                json_encode([
                    'entity_uuid' => ['malformed'],
                    'text_content' => 'Type confusion test',
                    'note' => 'Note',
                    'tag' => 'type_confusion',
                ])
            );

            $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::INTERNAL_SERVER_ERROR->value], 'Type confusion in JSON body should be rejected');
        }

        public function testPathCaseSensitivityAndTrailingSlashHandling(): void
        {
            $infoWithSlash = $this->client->getServerInformation();
            $this->assertNotNull($infoWithSlash);

            $url = rtrim(getenv('SERVER_ENDPOINT'), '/') . '/info/';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            // The server may either redirect or 404; either is acceptable as long as it does not crash.
            $this->assertContains($code, [200, 301, 302, 400, 404], 'Trailing slash should be handled gracefully');
        }

        public function testUnknownPathReturns404(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');
            [$code] = $this->rawRequest('GET', 'this/path/does/not/exist', $token);
            $this->assertContains($code, [HttpResponseCode::NOT_FOUND->value, HttpResponseCode::BAD_REQUEST->value], 'Unknown path should return 404 or 400');
        }

        public function testRapidCreateDeleteStability(): void
        {
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $uuid = $this->client->pushEntity("rapid-$i.com", 'user');
                $entityUuids[] = $uuid;
                $this->client->deleteEntity($uuid);
            }

            foreach ($entityUuids as $uuid)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->getEntityRecord($uuid),
                    [HttpResponseCode::NOT_FOUND->value],
                    'Rapidly deleted entity should remain deleted'
                );
            }
        }
    }
