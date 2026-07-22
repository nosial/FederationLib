<?php

    namespace FederationLib\Tests\Client;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
        private FederationClient $client;

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        public function testServerInformationConsistency(): void
        {
            $serverInfo1 = $this->client->getServerInformation();
            $this->assertNotNull($serverInfo1);

            for ($i = 0; $i < 5; $i++)
            {
                $serverInfo = $this->client->getServerInformation();
                $this->assertEquals($serverInfo1->getServerName(), $serverInfo->getServerName());
                $this->assertEquals($serverInfo1->getApiVersion(), $serverInfo->getApiVersion());
                $this->assertEquals($serverInfo1->isPublicEntities(), $serverInfo->isPublicEntities());
                $this->assertEquals($serverInfo1->isPublicEvidence(), $serverInfo->isPublicEvidence());
            }
        }

        public function testUnauthenticatedClientLimitations(): void
        {
            $serverInfo = $this->client->getServerInformation();
            $this->assertNotNull($serverInfo);

            try
            {
                $this->client->createOperator('test');
                $this->fail('Expected RequestException for unauthenticated createOperator');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 for unauthenticated request');
            }
        }

        public function testUnauthenticatedGetSelfFails(): void
        {
            try
            {
                $this->client->getSelf();
                $this->fail('Expected RequestException for unauthenticated getSelf');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 for unauthenticated request');
            }
        }

        public function testClientEndpointHandling(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $this->assertNotNull($endpoint, 'SERVER_ENDPOINT must be set for tests');

            $clientWithSlash = new FederationClient($endpoint . '/', null);
            $serverInfo1 = $clientWithSlash->getServerInformation();
            $this->assertNotNull($serverInfo1);

            $clientNoSlash = new FederationClient(rtrim($endpoint, '/'), null);
            $serverInfo2 = $clientNoSlash->getServerInformation();
            $this->assertNotNull($serverInfo2);

            $this->assertEquals($serverInfo1->getServerName(), $serverInfo2->getServerName());
            $this->assertEquals($serverInfo1->getApiVersion(), $serverInfo2->getApiVersion());
        }

        public function testSecurityCorsDoesNotAllowWildcardOrigin(): void
        {
            $url = rtrim(getenv('SERVER_ENDPOINT'), '/') . '/info';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => ['Origin: https://example.evil'],
            ]);
            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $this->assertNotFalse($response, 'CORS probe request should return a response');
            $headers = substr((string)$response, 0, $headerSize);
            $this->assertStringNotContainsString(
                'Access-Control-Allow-Origin: *',
                $headers,
                'Server should not return a wildcard CORS header for arbitrary origins'
            );
        }

    }
