<?php

    namespace FederationLib\Tests\ClientConfiguration;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ClientConfigurationTest extends TestCase
    {
        public function testClientWithValidEndpoint(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $this->assertNotNull($endpoint, 'SERVER_ENDPOINT must be set for tests');

            $client = new FederationClient($endpoint);
            $this->assertInstanceOf(FederationClient::class, $client);

            $serverInfo = $client->getServerInformation();
            $this->assertNotNull($serverInfo);
        }

        public function testClientWithValidEndpointAndAccessToken(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $accessToken = getenv('SERVER_ACCESS_TOKEN');
            $this->assertNotNull($endpoint, 'SERVER_ENDPOINT must be set for tests');
            $this->assertNotNull($accessToken, 'SERVER_ACCESS_TOKEN must be set for tests');

            $client = new FederationClient($endpoint, $accessToken);
            $this->assertInstanceOf(FederationClient::class, $client);

            $selfOperator = $client->getSelf();
            $this->assertNotNull($selfOperator);
            $this->assertNotNull($selfOperator->getUuid());
            $this->assertNotNull($selfOperator->getName());
        }

        public function testClientEndpointNormalization(): void
        {
            $baseEndpoint = rtrim(getenv('SERVER_ENDPOINT'), '/');

            $client1 = new FederationClient($baseEndpoint);
            $client2 = new FederationClient($baseEndpoint . '/');
            $client3 = new FederationClient($baseEndpoint . '//');

            $info1 = $client1->getServerInformation();
            $info2 = $client2->getServerInformation();
            $info3 = $client3->getServerInformation();

            $this->assertEquals($info1->getServerName(), $info2->getServerName());
            $this->assertEquals($info1->getServerName(), $info3->getServerName());
            $this->assertEquals($info1->getApiVersion(), $info2->getApiVersion());
            $this->assertEquals($info1->getApiVersion(), $info3->getApiVersion());
        }

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
            $this->expectException(InvalidArgumentException::class);
            new FederationClient('not-a-url');
        }

        public function testClientWithEmptyAccessToken(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');

            $this->expectException(InvalidArgumentException::class);
            new FederationClient($endpoint, '');
        }

        public function testClientWithWhitespaceOnlyAccessToken(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');

            $this->expectException(InvalidArgumentException::class);
            new FederationClient($endpoint, '   ');
        }

        public function testClientWithNonExistentEndpoint(): void
        {
            $client = new FederationClient('http://this-domain-does-not-exist-12345.com');

            $this->expectException(RequestException::class);
            $client->getServerInformation();
        }

        public function testClientWithWrongPortEndpoint(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $wrongPortEndpoint = preg_replace('/:\d+/', ':9999', $endpoint);

            $client = new FederationClient($wrongPortEndpoint);

            $this->expectException(RequestException::class);
            $client->getServerInformation();
        }

        public function testAccessTokenFormat(): void
        {
            $accessToken = getenv('SERVER_ACCESS_TOKEN');
            $this->assertNotNull($accessToken, 'SERVER_ACCESS_TOKEN must be set for tests');

            $this->assertGreaterThan(10, strlen($accessToken), 'Access Token seems too short');
            $this->assertLessThan(200, strlen($accessToken), 'Access Token seems too long');
            $this->assertStringNotContainsString(' ', $accessToken, 'Access Token should not contain spaces');
            $this->assertTrue(ctype_print($accessToken), 'Access Token should be printable ASCII');
        }

        public function testInvalidAccessTokenAuthentication(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $invalidAccessToken = 'definitely-not-a-valid-access-token-12345';

            $client = new FederationClient($endpoint, $invalidAccessToken);

            try
            {
                $client->getSelf();
                $this->fail('Expected RequestException for invalid access token');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 for invalid access token');
            }
        }

        public function testClientStatelessness(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');
            $accessToken = getenv('SERVER_ACCESS_TOKEN');

            $client1 = new FederationClient($endpoint, $accessToken);
            $client2 = new FederationClient($endpoint, $accessToken);

            $self1 = $client1->getSelf();
            $self2 = $client2->getSelf();

            $this->assertEquals($self1->getUuid(), $self2->getUuid());
            $this->assertEquals($self1->getName(), $self2->getName());
        }

        public function testClientThreadSafety(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');

            $results = [];
            for ($i = 0; $i < 5; $i++)
            {
                $client = new FederationClient($endpoint);
                $serverInfo = $client->getServerInformation();
                $results[$i] = $serverInfo->getServerName();
            }

            $firstResult = reset($results);
            foreach ($results as $index => $result)
            {
                $this->assertEquals($firstResult, $result, "Client $index returned different result");
            }
        }

        public function testClientWithNullAccessToken(): void
        {
            $endpoint = getenv('SERVER_ENDPOINT');

            $client = new FederationClient($endpoint, null);
            $this->assertInstanceOf(FederationClient::class, $client);

            $serverInfo = $client->getServerInformation();
            $this->assertNotNull($serverInfo);

            try
            {
                $client->getSelf();
                $this->fail('Expected RequestException for missing access token');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 for unauthenticated request');
            }
        }
    }
