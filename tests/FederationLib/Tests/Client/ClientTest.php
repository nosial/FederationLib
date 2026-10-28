<?php

    namespace FederationLib\Tests\Client;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    /**
     * The construction of a client: the endpoint and access token it accepts, and how it reaches the server
     */
    class ClientTest extends TestCase
    {
        public function testEndpointIsNormalized(): void
        {
            $endpoint = rtrim(getenv('SERVER_ENDPOINT'), '/');
            $serverName = new FederationClient($endpoint)->getServerInformation()->getServerName();

            foreach ([$endpoint, $endpoint . '/', $endpoint . '///'] as $variant)
            {
                $client = new FederationClient($variant);
                $this->assertEquals($endpoint, $client->getEndpoint());
                $this->assertEquals($serverName, $client->getServerInformation()->getServerName());
            }
        }

        public function testInvalidEndpointsAreRejected(): void
        {
            foreach (['', '   ', 'not a url', 'not-a-url', '172.17.0.1:7000', '/relative/path'] as $endpoint)
            {
                try
                {
                    new FederationClient($endpoint);
                    $this->fail("Endpoint '$endpoint' should be rejected");
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }

        public function testInvalidAccessTokensAreRejectedOnConstruction(): void
        {
            foreach (['', '   ', 'token with spaces', "token\n"] as $token)
            {
                try
                {
                    new FederationClient(getenv('SERVER_ENDPOINT'), $token);
                    $this->fail('Access token ' . json_encode($token) . ' should be rejected');
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }

        public function testClientWithoutAccessTokenIsAnonymous(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), null);
            $this->assertNull($client->getAccessToken());

            // Public methods work without an access token, methods that require authentication do not
            $this->assertNotEmpty($client->getServerInformation()->getServerName());

            try
            {
                $client->getSelf();
                $this->fail('getSelf() should require an access token');
            }
            catch (RequestException $e)
            {
                $this->assertSame(401, $e->getCode());
            }
        }

        public function testUnreachableEndpointsFailTheRequest(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $unreachable = [
                'http://this-domain-does-not-exist-12345.com',
                preg_replace('/:\d+/', ':9999', $endpoint),
            ];

            foreach ($unreachable as $endpoint)
            {
                try
                {
                    new FederationClient($endpoint)->getServerInformation();
                    $this->fail("A request to $endpoint should fail");
                }
                catch (RequestException)
                {
                    $this->addToAssertionCount(1);
                }
            }
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

            $this->assertNotFalse($response, 'CORS probe request should return a response');
            $headers = substr((string)$response, 0, $headerSize);
            $this->assertStringNotContainsString(
                'Access-Control-Allow-Origin: *',
                $headers,
                'Server should not return a wildcard CORS header for arbitrary origins'
            );
        }
    }
