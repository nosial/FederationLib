<?php

    namespace FederationLib;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
        private const string FAKE_OPERATOR_UUID = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
        private FederationClient $client;

        protected function setUp(): void
        {
            // Note, authentication is not required for these tests.
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        // CONSTRUCTOR AND BASIC FUNCTIONALITY TESTS

        public function testConstructorWithValidEndpoint()
        {
            $client = new FederationClient('https://example.com');
            $this->assertEquals('https://example.com', $client->getEndpoint());
            $this->assertNull($client->getApiKey());
        }

        public function testConstructorWithValidEndpointAndApiKey()
        {
            $apiKey = 'test-api-key-123';
            $client = new FederationClient('https://example.com', $apiKey);
            $this->assertEquals('https://example.com', $client->getEndpoint());
            $this->assertEquals($apiKey, $client->getApiKey());
        }

        public function testConstructorTrimsTrailingSlash()
        {
            $client = new FederationClient('https://example.com/');
            $this->assertEquals('https://example.com', $client->getEndpoint());
        }

        public function testConstructorWithInvalidEndpoint()
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Endpoint must be a valid URL');
            new FederationClient('not-a-valid-url');
        }

        public function testConstructorWithEmptyEndpoint()
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Endpoint must be a valid URL');
            new FederationClient('');
        }

        public function testConstructorWithEmptyApiKey()
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Token cannot be an empty string');
            new FederationClient('https://example.com', '');
        }

        public function testConstructorWithNullApiKey()
        {
            // Should not throw exception
            $client = new FederationClient('https://example.com', null);
            $this->assertNull($client->getApiKey());
        }

        public function testGetEndpoint()
        {
            $endpoint = 'https://federation.example.com';
            $client = new FederationClient($endpoint);
            $this->assertEquals($endpoint, $client->getEndpoint());
        }

        public function testGetApiKey()
        {
            $apiKey = 'test-api-key-456';
            $client = new FederationClient('https://example.com', $apiKey);
            $this->assertEquals($apiKey, $client->getApiKey());
        }

        public function testGetApiKeyWhenNull()
        {
            $client = new FederationClient('https://example.com');
            $this->assertNull($client->getApiKey());
        }

        // SERVER INFORMATION TESTS

        public function testGetServerInformation()
        {
            $serverInformation = $this->client->getServerInformation();

            $this->assertNotNull($serverInformation);
            $this->assertNotEmpty($serverInformation->getServerName());
            $this->assertNotEmpty($serverInformation->getApiVersion());
            $this->assertEquals('2025.01', $serverInformation->getApiVersion());
            $this->assertIsBool($serverInformation->isPublicAuditLogs());
            $this->assertIsBool($serverInformation->isPublicEvidence());
            $this->assertIsBool($serverInformation->isPublicBlacklist());
            $this->assertIsBool($serverInformation->isPublicEntities());
            $this->assertIsArray($serverInformation->getPublicAuditLogsVisibility());
            $this->assertNotEmpty($serverInformation->getPublicAuditLogsVisibility());
            $this->assertIsInt($serverInformation->getAuditLogRecords());
            $this->assertIsInt($serverInformation->getBlacklistRecords());
            $this->assertIsInt($serverInformation->getKnownEntities());
            $this->assertIsInt($serverInformation->getEvidenceRecords());
            $this->assertIsInt($serverInformation->getFileAttachmentRecords());
            $this->assertIsInt($serverInformation->getOperators());
        }

        public function testGetServerInformationReturnsSameData()
        {
            // Test that multiple calls return consistent data
            $info1 = $this->client->getServerInformation();
            $info2 = $this->client->getServerInformation();

            $this->assertEquals($info1->getServerName(), $info2->getServerName());
            $this->assertEquals($info1->getApiVersion(), $info2->getApiVersion());
        }

        // UNAUTHORIZED ACCESS TESTS

        public function testUnauthorizedCreateOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->createOperator('testOperator unauthorized');
        }

        public function testUnauthorizedDeleteOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->deleteOperator(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedDisableOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->disableOperator(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedEnableOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->enableOperator(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedGetOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->getOperator(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedGetSelf()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->getSelf();
        }

        public function testUnauthorizedListOperators()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->listOperators();
        }

        public function testUnauthorizedRefreshApiKey()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->refreshApiKey();
        }

        public function testUnauthorizedRefreshOperatorApiKey()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->refreshOperatorApiKey(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedSetManageOperatorsPermission()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->setManageOperatorsPermission(self::FAKE_OPERATOR_UUID, true);
        }

        public function testUnauthorizedSetClientPermission()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->setClientPermission(self::FAKE_OPERATOR_UUID, true);
        }

        public function testUnauthorizedSetManageBlacklistPermission()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->setManageBlacklistPermission(self::FAKE_OPERATOR_UUID, true);
        }

        public function testUnauthorizedListOperatorAuditLogs()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->listOperatorAuditLogs(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedListOperatorEvidence()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->listOperatorEvidence(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedListOperatorBlacklist()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->listOperatorBlacklist(self::FAKE_OPERATOR_UUID);
        }

        // ENTITY UNAUTHORIZED TESTS

        public function testUnauthorizedPushEntity()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->pushEntity('test-unauthorized-entity');
        }

        public function testUnauthorizedDeleteEntity()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->deleteEntity('test-unauthorized-entity');
        }

        public function testUnauthorizedSubmitEvidence()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->submitEvidence(self::FAKE_OPERATOR_UUID, 'Unauthorized evidence');
        }

        public function testUnauthorizedDeleteEvidence()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->deleteEvidence(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedUploadFileAttachment()
        {
            // Create a temporary test file
            $tempFile = tempnam(sys_get_temp_dir(), 'unauthorized_test_');
            file_put_contents($tempFile, 'Test content');

            try {
                $this->expectException(RequestException::class);
                $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
                $this->client->uploadFileAttachment(self::FAKE_OPERATOR_UUID, $tempFile);
            } finally {
                unlink($tempFile);
            }
        }

        public function testUnauthorizedDeleteAttachment()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->deleteAttachment(self::FAKE_OPERATOR_UUID);
        }

        // CLIENT BEHAVIOR TESTS

        public function testClientWithDifferentEndpoints()
        {
            $endpoint1 = 'https://federation1.example.com';
            $endpoint2 = 'https://federation2.example.com';

            $client1 = new FederationClient($endpoint1);
            $client2 = new FederationClient($endpoint2);

            $this->assertEquals($endpoint1, $client1->getEndpoint());
            $this->assertEquals($endpoint2, $client2->getEndpoint());
        }

        public function testClientWithSameEndpointDifferentApiKeys()
        {
            $endpoint = 'https://federation.example.com';
            $apiKey1 = 'api-key-1';
            $apiKey2 = 'api-key-2';

            $client1 = new FederationClient($endpoint, $apiKey1);
            $client2 = new FederationClient($endpoint, $apiKey2);

            $this->assertEquals($apiKey1, $client1->getApiKey());
            $this->assertEquals($apiKey2, $client2->getApiKey());
        }

        // VALIDATION TESTS FOR EDGE CASES

        public function testConstructorWithVariousValidUrls()
        {
            $validUrls = [
                'http://example.com',
                'https://example.com',
                'https://subdomain.example.com',
                'https://example.com:8080',
                'https://example.com/path',
                'https://example.com:8080/path',
                'http://192.168.1.1',
                'https://192.168.1.1:3000'
            ];

            foreach ($validUrls as $url) {
                $client = new FederationClient($url);
                $expectedEndpoint = rtrim($url, '/');
                $this->assertEquals($expectedEndpoint, $client->getEndpoint());
            }
        }

        public function testConstructorWithVariousInvalidUrls()
        {
            $invalidUrls = [
                'not-a-url',
                'ftp://example.com',
                'mailto:test@example.com',
                'javascript:alert(1)',
                '//example.com',
                'example.com',
                'http://',
                'https://',
                ''
            ];

            foreach ($invalidUrls as $url) {
                try {
                    new FederationClient($url);
                    $this->fail("Expected InvalidArgumentException for URL: $url");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContains('Endpoint must be a valid URL', $e->getMessage());
                }
            }
        }

        public function testApiKeyValidation()
        {
            $validApiKeys = [
                'simple-key',
                'key_with_underscores',
                'key-with-dashes',
                'key.with.dots',
                'UPPERCASE_KEY',
                'MixedCaseKey123',
                '1234567890',
                'very-long-api-key-that-contains-many-characters-and-symbols_123.456-789',
                'key with spaces', // Some systems allow spaces
                'key@with#special$chars%'
            ];

            foreach ($validApiKeys as $apiKey) {
                $client = new FederationClient('https://example.com', $apiKey);
                $this->assertEquals($apiKey, $client->getApiKey());
            }
        }

        public function testClientIsolation()
        {
            // Test that multiple client instances don't interfere with each other
            $client1 = new FederationClient('https://example1.com', 'key1');
            $client2 = new FederationClient('https://example2.com', 'key2');
            $client3 = new FederationClient('https://example3.com');

            $this->assertEquals('https://example1.com', $client1->getEndpoint());
            $this->assertEquals('key1', $client1->getApiKey());

            $this->assertEquals('https://example2.com', $client2->getEndpoint());
            $this->assertEquals('key2', $client2->getApiKey());

            $this->assertEquals('https://example3.com', $client3->getEndpoint());
            $this->assertNull($client3->getApiKey());
        }
    }
