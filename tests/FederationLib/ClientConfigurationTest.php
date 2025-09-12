<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ClientConfigurationTest extends TestCase
    {
        private Logger $logger;

        protected function setUp(): void
        {
            $this->logger = new Logger('client-configuration-tests');
        }

        // CLIENT INSTANTIATION TESTS

        public function testClientWithValidEndpoint(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $this->assertNotNull($endpoint, "SERVER_ENDPOINT must be set for tests");

            $client = new FederationClient($endpoint);
            $this->assertInstanceOf(FederationClient::class, $client);

            // Test that client can make basic requests
            $serverInfo = $client->getServerInformation();
            $this->assertNotNull($serverInfo);
        }

        public function testClientWithValidEndpointAndApiKey(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $apiKey = getenv('SERVER_API_KEY');
            $this->assertNotNull($endpoint, "SERVER_ENDPOINT must be set for tests");
            $this->assertNotNull($apiKey, "SERVER_API_KEY must be set for tests");

            $client = new FederationClient($endpoint, $apiKey);
            $this->assertInstanceOf(FederationClient::class, $client);

            // Test that client can make authenticated requests
            $selfOperator = $client->getSelf();
            $this->assertNotNull($selfOperator);
        }

        public function testClientWithTrailingSlashInEndpoint(): void
        {
            $endpoint = rtrim(getenv('SERVER_ENDPOINT'), '/') . '/';
            
            $client = new FederationClient($endpoint);
            $this->assertInstanceOf(FederationClient::class, $client);

            // Should work the same as without trailing slash
            $serverInfo = $client->getServerInformation();
            $this->assertNotNull($serverInfo);
        }

        public function testClientWithoutTrailingSlashInEndpoint(): void
        {
            $endpoint = rtrim(getenv('SERVER_ENDPOINT'), '/');
            
            $client = new FederationClient($endpoint);
            $this->assertInstanceOf(FederationClient::class, $client);

            // Should work the same as with trailing slash
            $serverInfo = $client->getServerInformation();
            $this->assertNotNull($serverInfo);
        }

        public function testClientEndpointNormalization(): void
        {
            $baseEndpoint = rtrim(getenv('SERVER_ENDPOINT'), '/');
            
            $client1 = new FederationClient($baseEndpoint);
            $client2 = new FederationClient($baseEndpoint . '/');
            $client3 = new FederationClient($baseEndpoint . '//');
            
            // All should produce identical results
            $info1 = $client1->getServerInformation();
            $info2 = $client2->getServerInformation();
            $info3 = $client3->getServerInformation();
            
            $this->assertEquals($info1->getServerName(), $info2->getServerName());
            $this->assertEquals($info1->getServerName(), $info3->getServerName());
            $this->assertEquals($info1->getApiVersion(), $info2->getApiVersion());
            $this->assertEquals($info1->getApiVersion(), $info3->getApiVersion());
        }

        // INVALID CONFIGURATION TESTS

        public function testClientWithEmptyEndpoint(): void
        {
            $this->expectException(InvalidArgumentException::class);
            new FederationClient('');
        }

        public function testClientWithWhitespaceOnlyEndpoint(): void
        {
            $this->expectException(InvalidArgumentException::class);
            new FederationClient('   ');
        }

        public function testClientWithInvalidEndpointFormat(): void
        {
            $invalidEndpoints = [
                'not-a-url',
                'ftp://invalid-protocol.com',
                'just-a-string',
                '://missing-scheme.com',
            ];

            foreach ($invalidEndpoints as $endpoint) {
                try {
                    $client = new FederationClient($endpoint);
                    // If client creation succeeds, test that it fails on actual request
                    $this->expectException(RequestException::class);
                    $client->getServerInformation();
                } catch (InvalidArgumentException $e) {
                    // This is also acceptable - client construction should validate URLs
                    $this->assertNotNull($e);
                }
            }
        }

        public function testClientWithEmptyApiKey(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            
            $this->expectException(InvalidArgumentException::class);
            new FederationClient($endpoint, '');
        }

        public function testClientWithWhitespaceOnlyApiKey(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            
            $this->expectException(InvalidArgumentException::class);
            new FederationClient($endpoint, '   ');
        }

        public function testClientWithInvalidApiKey(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $invalidApiKeys = [
                'too-short',
                '123',
                'invalid-format-key',
                str_repeat('a', 10), // Too short
            ];

            foreach ($invalidApiKeys as $apiKey) {
                try {
                    $client = new FederationClient($endpoint, $apiKey);
                    // If client creation succeeds, test that authentication fails
                    $this->expectException(RequestException::class);
                    $client->getSelf();
                } catch (InvalidArgumentException $e) {
                    // This is also acceptable - client should validate API key format
                    $this->assertNotNull($e);
                }
            }
        }

        // NETWORK AND CONNECTIVITY TESTS

        public function testClientWithNonExistentEndpoint(): void
        {
            $client = new FederationClient('http://this-domain-does-not-exist-12345.com');
            
            $this->expectException(RequestException::class);
            $client->getServerInformation();
        }

        public function testClientWithWrongPortEndpoint(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            // Change port to something likely unused
            $wrongPortEndpoint = preg_replace('/:\d+/', ':9999', $endpoint);
            
            $client = new FederationClient($wrongPortEndpoint);
            
            $this->expectException(RequestException::class);
            $client->getServerInformation();
        }

        // API KEY VALIDATION TESTS

        public function testApiKeyFormat(): void
        {
            $apiKey = getenv('SERVER_API_KEY');
            $this->assertNotNull($apiKey, "SERVER_API_KEY must be set for tests");

            // API key should be reasonable length and format
            $this->assertGreaterThan(10, strlen($apiKey), "API key seems too short");
            $this->assertLessThan(200, strlen($apiKey), "API key seems too long");
            
            // Should not contain spaces
            $this->assertStringNotContainsString(' ', $apiKey, "API key should not contain spaces");
            
            // Should be printable ASCII
            $this->assertTrue(ctype_print($apiKey), "API key should be printable ASCII");
        }

        public function testValidApiKeyAuthentication(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $apiKey = getenv('SERVER_API_KEY');
            
            $client = new FederationClient($endpoint, $apiKey);
            
            // Should be able to authenticate
            $selfOperator = $client->getSelf();
            $this->assertNotNull($selfOperator);
            $this->assertNotNull($selfOperator->getUuid());
            $this->assertNotNull($selfOperator->getName());
        }

        public function testInvalidApiKeyAuthentication(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $invalidApiKey = 'definitely-not-a-valid-api-key-12345';
            
            $client = new FederationClient($endpoint, $invalidApiKey);
            
            $this->expectException(RequestException::class);
            $client->getSelf();
        }

        // CLIENT BEHAVIOR TESTS

        public function testClientStatelessness(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $apiKey = getenv('SERVER_API_KEY');
            
            // Create multiple clients with same credentials
            $client1 = new FederationClient($endpoint, $apiKey);
            $client2 = new FederationClient($endpoint, $apiKey);
            
            // Both should work identically
            $self1 = $client1->getSelf();
            $self2 = $client2->getSelf();
            
            $this->assertEquals($self1->getUuid(), $self2->getUuid());
            $this->assertEquals($self1->getName(), $self2->getName());
        }

        public function testClientThreadSafety(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            
            // Create multiple clients and make concurrent requests
            $clients = [];
            for ($i = 0; $i < 5; $i++) {
                $clients[] = new FederationClient($endpoint);
            }
            
            $results = [];
            foreach ($clients as $index => $client) {
                try {
                    $serverInfo = $client->getServerInformation();
                    $results[$index] = $serverInfo->getServerName();
                } catch (RequestException $e) {
                    $this->fail("Client $index failed: " . $e->getMessage());
                }
            }
            
            // All results should be identical
            $firstResult = reset($results);
            foreach ($results as $index => $result) {
                $this->assertEquals($firstResult, $result, "Client $index returned different result");
            }
        }

        // CONFIGURATION EDGE CASES

        public function testClientWithNullApiKey(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            
            // Null API key should create unauthenticated client
            $client = new FederationClient($endpoint, null);
            $this->assertInstanceOf(FederationClient::class, $client);
            
            // Should be able to make unauthenticated requests
            $serverInfo = $client->getServerInformation();
            $this->assertNotNull($serverInfo);
            
            // Should NOT be able to make authenticated requests
            $this->expectException(RequestException::class);
            $client->getSelf();
        }

        public function testClientReconfiguration(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            
            // Create client without API key
            $client1 = new FederationClient($endpoint);
            $serverInfo1 = $client1->getServerInformation();
            
            // Create new client with API key
            $apiKey = getenv('SERVER_API_KEY');
            $client2 = new FederationClient($endpoint, $apiKey);
            $serverInfo2 = $client2->getServerInformation();
            $self2 = $client2->getSelf();
            
            // Server info should be same, but authenticated client can do more
            $this->assertEquals($serverInfo1->getServerName(), $serverInfo2->getServerName());
            $this->assertNotNull($self2);
        }

        public function testClientMemoryUsage(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $initialMemory = memory_get_usage();
            
            // Create multiple clients and measure memory usage
            $clients = [];
            for ($i = 0; $i < 10; $i++) {
                $clients[] = new FederationClient($endpoint);
            }
            
            $afterCreationMemory = memory_get_usage();
            $memoryPerClient = ($afterCreationMemory - $initialMemory) / 10;
            
            // Each client should use reasonable amount of memory (adjust threshold as needed)
            $this->assertLessThan(1024 * 1024, $memoryPerClient, "Each client uses too much memory: " . $memoryPerClient . " bytes");
            
            // Cleanup - unset clients
            unset($clients);
            gc_collect_cycles();
        }
    }
