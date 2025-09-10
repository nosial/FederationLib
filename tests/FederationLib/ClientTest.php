<?php

    namespace FederationLib;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
        private const string FAKE_OPERATOR_UUID = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
        private FederationClient $client;
        private Logger $logger;

        protected function setUp(): void
        {
            $this->logger = new Logger('tests');
            // Note, authentication is not required for these tests.
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        // DURABILITY TESTS

        public function testServerInformationConsistency(): void
        {
            // Test that server information remains consistent across multiple calls
            $serverInfo1 = $this->client->getServerInformation();
            $this->assertNotNull($serverInfo1);

            // Call multiple times and ensure consistency
            for ($i = 0; $i < 5; $i++) {
                $serverInfo = $this->client->getServerInformation();
                $this->assertEquals($serverInfo1->getServerName(), $serverInfo->getServerName());
                $this->assertEquals($serverInfo1->getApiVersion(), $serverInfo->getApiVersion());
                $this->assertEquals($serverInfo1->isPublicEntities(), $serverInfo->isPublicEntities());
                $this->assertEquals($serverInfo1->isPublicEvidence(), $serverInfo->isPublicEvidence());
            }
        }

        public function testClientConnectionResilience(): void
        {
            // Test that client can handle multiple rapid requests
            $requests = 10;
            $results = [];

            for ($i = 0; $i < $requests; $i++) {
                try {
                    $serverInfo = $this->client->getServerInformation();
                    $results[] = $serverInfo->getServerName();
                } catch (RequestException $e) {
                    $this->logger->warning("Request $i failed: " . $e->getMessage());
                    $results[] = null;
                }
            }

            // Verify that at least most requests succeeded
            $successfulRequests = array_filter($results, fn($result) => $result !== null);
            $this->assertGreaterThan($requests * 0.8, count($successfulRequests), "Less than 80% of requests succeeded");

            // Verify all successful results are consistent
            $uniqueResults = array_unique($successfulRequests);
            $this->assertEquals(1, count($uniqueResults), "Server information was inconsistent across requests");
        }

        public function testUnauthenticatedClientLimitations(): void
        {
            // Test that unauthenticated client properly handles restricted operations
            $serverInfo = $this->client->getServerInformation();
            $this->assertNotNull($serverInfo);

            // These operations should fail for unauthenticated client
            $restrictedOperations = [
                'createOperator' => fn() => $this->client->createOperator('test'),
                'getSelf' => fn() => $this->client->getSelf(),
            ];

            foreach ($restrictedOperations as $operationName => $operation) {
                try {
                    $operation();
                    $this->fail("Expected RequestException for unauthenticated $operationName");
                } catch (RequestException $e) {
                    $this->assertEquals(401, $e->getCode(), "Expected 401 Unauthorized for $operationName");
                }
            }
        }

        public function testClientEndpointHandling(): void
        {
            // Test various endpoint configurations
            $endpoint = getenv('SERVER_ENDPOINT');
            $this->assertNotNull($endpoint, "SERVER_ENDPOINT must be set for tests");

            // Test with trailing slash
            $clientWithSlash = new FederationClient($endpoint . '/', null);
            $serverInfo1 = $clientWithSlash->getServerInformation();
            $this->assertNotNull($serverInfo1);

            // Test without trailing slash
            $endpointNoSlash = rtrim($endpoint, '/');
            $clientNoSlash = new FederationClient($endpointNoSlash, null);
            $serverInfo2 = $clientNoSlash->getServerInformation();
            $this->assertNotNull($serverInfo2);

            // Results should be identical
            $this->assertEquals($serverInfo1->getServerName(), $serverInfo2->getServerName());
        }
    }
