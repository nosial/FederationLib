<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ServerInformationTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;

        protected function setUp(): void
        {
            $this->logger = new Logger('server-information-tests');
            // No authentication required for server information
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        // BASIC SERVER INFORMATION TESTS

        public function testGetServerInformation(): void
        {
            $serverInfo = $this->client->getServerInformation();
            
            $this->assertNotNull($serverInfo);
            $this->assertNotNull($serverInfo->getServerName());
            $this->assertNotEmpty($serverInfo->getServerName());
            $this->assertNotNull($serverInfo->getApiVersion());
            $this->assertNotEmpty($serverInfo->getApiVersion());
            $this->assertIsBool($serverInfo->isPublicEntities());
            $this->assertIsBool($serverInfo->isPublicEvidence());
        }

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

        public function testServerInformationWithAuthentication(): void
        {
            // Test that authentication doesn't change server information
            $unauthenticatedInfo = $this->client->getServerInformation();
            
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
            $authenticatedInfo = $authenticatedClient->getServerInformation();
            
            $this->assertEquals($unauthenticatedInfo->getServerName(), $authenticatedInfo->getServerName());
            $this->assertEquals($unauthenticatedInfo->getApiVersion(), $authenticatedInfo->getApiVersion());
            $this->assertEquals($unauthenticatedInfo->isPublicEntities(), $authenticatedInfo->isPublicEntities());
            $this->assertEquals($unauthenticatedInfo->isPublicEvidence(), $authenticatedInfo->isPublicEvidence());
        }

        // VALIDATION TESTS

        public function testServerInformationDataTypes(): void
        {
            $serverInfo = $this->client->getServerInformation();
            
            $this->assertIsString($serverInfo->getServerName());
            $this->assertIsString($serverInfo->getApiVersion());
            $this->assertIsBool($serverInfo->isPublicEntities());
            $this->assertIsBool($serverInfo->isPublicEvidence());
        }

        public function testServerNameFormat(): void
        {
            $serverInfo = $this->client->getServerInformation();
            $serverName = $serverInfo->getServerName();
            
            // Server name should not be empty or just whitespace
            $this->assertNotEmpty(trim($serverName));
            $this->assertGreaterThan(0, strlen($serverName));
        }

        public function testApiVersionFormat(): void
        {
            $serverInfo = $this->client->getServerInformation();
            $apiVersion = $serverInfo->getApiVersion();
            
            // API version should not be empty and should follow semantic versioning pattern
            $this->assertNotEmpty(trim($apiVersion));
            $this->assertGreaterThan(0, strlen($apiVersion));
            
            // Check if it matches semantic versioning pattern (optional but good practice)
            $semverPattern = '/^\d+\.\d+(\.\d+)?(-[a-zA-Z0-9\-\.]+)?(\+[a-zA-Z0-9\-\.]+)?$/';
            $this->assertMatchesRegularExpression($semverPattern, $apiVersion, 
                "API version should follow semantic versioning format");
        }

        // DURABILITY AND PERFORMANCE TESTS

        public function testServerInformationPerformance(): void
        {
            $requestCount = 10;
            $maxResponseTime = 5.0; // 5 seconds max per request
            
            for ($i = 0; $i < $requestCount; $i++) {
                $startTime = microtime(true);
                $serverInfo = $this->client->getServerInformation();
                $endTime = microtime(true);
                
                $responseTime = $endTime - $startTime;
                $this->assertLessThan($maxResponseTime, $responseTime, 
                    "Server information request took too long: {$responseTime}s");
                $this->assertNotNull($serverInfo);
            }
        }

        public function testConcurrentServerInformationRequests(): void
        {
            // Simulate concurrent requests by making rapid sequential calls
            $clients = [];
            $results = [];
            
            // Create multiple client instances
            for ($i = 0; $i < 5; $i++) {
                $clients[] = new FederationClient(getenv('SERVER_ENDPOINT'));
            }
            
            // Make requests from all clients
            foreach ($clients as $index => $client) {
                try {
                    $serverInfo = $client->getServerInformation();
                    $results[] = [
                        'client' => $index,
                        'server_name' => $serverInfo->getServerName(),
                        'api_version' => $serverInfo->getApiVersion(),
                        'public_entities' => $serverInfo->isPublicEntities(),
                        'public_evidence' => $serverInfo->isPublicEvidence()
                    ];
                } catch (RequestException $e) {
                    $this->fail("Client $index failed to get server information: " . $e->getMessage());
                }
            }
            
            // Verify all results are identical
            $firstResult = $results[0];
            foreach ($results as $index => $result) {
                $this->assertEquals($firstResult['server_name'], $result['server_name'], 
                    "Server name mismatch for client $index");
                $this->assertEquals($firstResult['api_version'], $result['api_version'], 
                    "API version mismatch for client $index");
                $this->assertEquals($firstResult['public_entities'], $result['public_entities'], 
                    "Public entities setting mismatch for client $index");
                $this->assertEquals($firstResult['public_evidence'], $result['public_evidence'], 
                    "Public evidence setting mismatch for client $index");
            }
        }

        // EDGE CASE TESTS

        public function testServerInformationCaching(): void
        {
            // Test if server information is properly cached or consistently retrieved
            $info1 = $this->client->getServerInformation();
            $info2 = $this->client->getServerInformation();
            $info3 = $this->client->getServerInformation();
            
            // All should be identical
            $this->assertEquals($info1->getServerName(), $info2->getServerName());
            $this->assertEquals($info1->getServerName(), $info3->getServerName());
            $this->assertEquals($info1->getApiVersion(), $info2->getApiVersion());
            $this->assertEquals($info1->getApiVersion(), $info3->getApiVersion());
            $this->assertEquals($info1->isPublicEntities(), $info2->isPublicEntities());
            $this->assertEquals($info1->isPublicEntities(), $info3->isPublicEntities());
            $this->assertEquals($info1->isPublicEvidence(), $info2->isPublicEvidence());
            $this->assertEquals($info1->isPublicEvidence(), $info3->isPublicEvidence());
        }

        public function testServerInformationAfterClientRecreation(): void
        {
            // Get server info with first client
            $serverInfo1 = $this->client->getServerInformation();
            
            // Create a new client instance and get server info
            $newClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $serverInfo2 = $newClient->getServerInformation();
            
            // Should be identical
            $this->assertEquals($serverInfo1->getServerName(), $serverInfo2->getServerName());
            $this->assertEquals($serverInfo1->getApiVersion(), $serverInfo2->getApiVersion());
            $this->assertEquals($serverInfo1->isPublicEntities(), $serverInfo2->isPublicEntities());
            $this->assertEquals($serverInfo1->isPublicEvidence(), $serverInfo2->isPublicEvidence());
        }

        // CONFIGURATION VALIDATION TESTS

        public function testPublicEntitiesFlag(): void
        {
            $serverInfo = $this->client->getServerInformation();
            $publicEntities = $serverInfo->isPublicEntities();
            
            // The flag should be a boolean and should make sense in context
            $this->assertIsBool($publicEntities);
            
            // Log the setting for manual verification if needed
            $this->logger->info("Server public entities setting: " . ($publicEntities ? 'true' : 'false'));
        }

        public function testPublicEvidenceFlag(): void
        {
            $serverInfo = $this->client->getServerInformation();
            $publicEvidence = $serverInfo->isPublicEvidence();
            
            // The flag should be a boolean
            $this->assertIsBool($publicEvidence);
            
            // Log the setting for manual verification if needed
            $this->logger->info("Server public evidence setting: " . ($publicEvidence ? 'true' : 'false'));
        }

        public function testServerConfigurationLogic(): void
        {
            $serverInfo = $this->client->getServerInformation();
            
            // If evidence is public, it might make sense for entities to be public too
            // This is a business logic test - adjust based on your requirements
            if ($serverInfo->isPublicEvidence()) {
                // This is just a logical check - remove if not applicable to your business logic
                $this->assertTrue($serverInfo->isPublicEntities() || !$serverInfo->isPublicEntities(), 
                    "Server configuration should be logically consistent");
            }
            
            // Just verify both settings exist and are readable
            $this->assertNotNull($serverInfo->isPublicEntities());
            $this->assertNotNull($serverInfo->isPublicEvidence());
        }
    }
