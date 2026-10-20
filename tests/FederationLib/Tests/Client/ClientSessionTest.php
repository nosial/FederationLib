<?php

    namespace FederationLib\Tests\Client;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    /**
     * Client to server tests of how a single client authenticates as it changes its endpoint and access token
     */
    class ClientSessionTest extends TestCase
    {
        use TestHelpers;

        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testEndpointIsNormalized(): void
        {
            $endpoint = rtrim(getenv('SERVER_ENDPOINT'), '/');

            $this->assertEquals($endpoint, new FederationClient($endpoint . '/')->getEndpoint());
            $this->assertEquals($endpoint, new FederationClient($endpoint . '///')->getEndpoint());
            $this->assertEquals($endpoint, new FederationClient($endpoint)->getEndpoint());
        }

        public function testInvalidEndpointsAreRejected(): void
        {
            foreach (['', 'not a url', '172.17.0.1:7000', '/relative/path'] as $endpoint)
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

        public function testInvalidAccessTokensAreRejectedClientSide(): void
        {
            foreach (['', 'token with spaces', "token\twith\ttabs", "token\n"] as $token)
            {
                try
                {
                    $this->client->setAccessToken($token);
                    $this->fail('Access token ' . json_encode($token) . ' should be rejected');
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }

            // A rejected token leaves the previous token in place
            $this->assertEquals(getenv('SERVER_ACCESS_TOKEN'), $this->client->getAccessToken());
            $this->assertNotEmpty($this->client->getSelf()->getUuid());
        }

        public function testSwitchingAccessTokenSwitchesIdentity(): void
        {
            $firstOperator = $this->client->createOperator(substr(uniqid('session_a_'), 0, 32));
            $secondOperator = $this->client->createOperator(substr(uniqid('session_b_'), 0, 32));
            $this->createdOperators[] = $firstOperator->getUuid();
            $this->createdOperators[] = $secondOperator->getUuid();

            $session = new FederationClient(getenv('SERVER_ENDPOINT'));

            $session->setAccessToken($firstOperator->getAccessToken());
            $this->assertEquals($firstOperator->getUuid(), $session->getSelf()->getUuid());

            $session->setAccessToken($secondOperator->getAccessToken());
            $this->assertEquals($secondOperator->getUuid(), $session->getSelf()->getUuid());

            $session->setAccessToken(null);
            $this->assertNull($session->getAccessToken());
            $this->expectRequestFailure(
                fn() => $session->getSelf(),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                'A client without an access token should not be authenticated'
            );
        }

        public function testPermissionsFollowTheCurrentAccessToken(): void
        {
            $clientOperator = $this->client->createOperator(substr(uniqid('session_cl_'), 0, 32));
            $this->createdOperators[] = $clientOperator->getUuid();
            $this->client->setClientPermissions($clientOperator->getUuid(), true);

            $plainOperator = $this->client->createOperator(substr(uniqid('session_np_'), 0, 32));
            $this->createdOperators[] = $plainOperator->getUuid();

            $session = new FederationClient(getenv('SERVER_ENDPOINT'), $clientOperator->getAccessToken());
            $entityUuid = $session->pushEntity(uniqid('session-') . '.com', 'session_user');
            $this->createdEntities[] = $entityUuid;

            $session->setAccessToken($plainOperator->getAccessToken());
            $this->expectRequestFailure(
                fn() => $session->pushEntity(uniqid('session-denied-') . '.com', 'session_user'),
                [HttpResponseCode::FORBIDDEN->value],
                'The permissions of the previous token should not carry over'
            );
        }

        public function testPermissionChangesApplyToAnExistingSession(): void
        {
            $createdOperator = $this->client->createOperator(substr(uniqid('session_up_'), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;
            $session = new FederationClient(getenv('SERVER_ENDPOINT'), $createdOperator->getAccessToken());

            $this->expectRequestFailure(
                fn() => $session->pushEntity(uniqid('session-before-') . '.com', 'session_user'),
                [HttpResponseCode::FORBIDDEN->value],
                'An operator without client permissions should not push entities'
            );

            // Granting permissions takes effect without a new token
            $this->client->setClientPermissions($operatorUuid, true);
            $entityUuid = $session->pushEntity(uniqid('session-after-') . '.com', 'session_user');
            $this->createdEntities[] = $entityUuid;
            $this->assertTrue($session->getSelf()->hasClientPermissions());

            // ...and so does revoking them
            $this->client->setClientPermissions($operatorUuid, false);
            $this->expectRequestFailure(
                fn() => $session->pushEntity(uniqid('session-revoked-') . '.com', 'session_user'),
                [HttpResponseCode::FORBIDDEN->value],
                'Revoked client permissions should take effect immediately'
            );
        }

        public function testRefreshedTokenReplacesTheSessionToken(): void
        {
            $createdOperator = $this->client->createOperator(substr(uniqid('session_rf_'), 0, 32));
            $this->createdOperators[] = $createdOperator->getUuid();
            $oldToken = $createdOperator->getAccessToken();

            $session = new FederationClient(getenv('SERVER_ENDPOINT'), $oldToken);
            $newToken = $session->generateAccessToken();
            $this->assertEquals($newToken, $session->getAccessToken());
            $this->assertEquals($createdOperator->getUuid(), $session->getSelf()->getUuid());

            // Switching the session back to the replaced token no longer authenticates the operator
            $session->setAccessToken($oldToken);
            $this->expectRequestFailure(
                fn() => $session->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'A replaced access token should no longer authenticate'
            );
        }

        public function testUnknownTokenOnPublicMethodIsAnonymous(): void
        {
            $session = new FederationClient(getenv('SERVER_ENDPOINT'), str_repeat('z', 32));

            // A public method processes an unknown token as unauthenticated instead of failing
            $this->assertNotEmpty($session->getServerInformation()->getServerName());
            $this->assertIsArray($session->listEvidence());

            $this->expectRequestFailure(
                fn() => $session->listEvidence(includeConfidential: true),
                [HttpResponseCode::UNAUTHORIZED->value],
                'An unknown token should not grant access to confidential evidence'
            );
            $this->expectRequestFailure(
                fn() => $session->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'An unknown token should not authenticate'
            );
        }
    }
