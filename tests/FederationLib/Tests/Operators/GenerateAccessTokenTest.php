<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class GenerateAccessTokenTest extends TestCase
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
            foreach ($this->createdReports as $reportUuid)
            {
                try
                {
                    $this->client->deleteReport($reportUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete report $reportUuid: " . $e->getMessage());
                }
            }

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

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testGenerateAccessTokenRefreshesOwnToken(): void
        {
            // An operator with no permissions at all can still refresh its own token.
            $operator = $this->createLimitedOperator('self_refresh');
            $operatorUuid = $operator->getSelf()->getUuid();

            $originalToken = $operator->getAccessToken();
            $this->assertNotEmpty($originalToken);

            $newToken = $operator->generateAccessToken();

            $this->assertNotEmpty($newToken);
            $this->assertNotEquals($originalToken, $newToken);
            $this->assertEquals(32, strlen($newToken));

            // The client instance updates its own token to the freshly generated one.
            $self = $operator->getSelf();
            $this->assertEquals($operatorUuid, $self->getUuid());

            // The old token must no longer authenticate.
            $this->expectRequestFailure(
                fn() => (new FederationClient(getenv('SERVER_ENDPOINT'), $originalToken))->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'Old access token should be revoked after refreshing via /operators/refresh'
            );
        }

        public function testGenerateAccessTokenWithoutUpdateKeepsClientToken(): void
        {
            $operator = $this->createLimitedOperator('self_refresh_no_update');
            $operatorUuid = $operator->getSelf()->getUuid();

            $originalToken = $operator->getAccessToken();
            $newToken = $operator->generateAccessToken(false);

            $this->assertNotEmpty($newToken);
            $this->assertNotEquals($originalToken, $newToken);

            // The client instance keeps the original token when update is disabled.
            $this->assertEquals($originalToken, $operator->getAccessToken());

            // The server has rotated the token, so the stale client token is revoked.
            $this->expectRequestFailure(
                fn() => $operator->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'Original token should be revoked server-side even when the client does not update its token'
            );

            // The new token is valid for authenticating as the same operator.
            $newClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newToken);
            $this->assertEquals($operatorUuid, $newClient->getSelf()->getUuid());
        }

        public function testGenerateAccessTokenWorksForAllPermissionLevels(): void
        {
            $operators = [
                'client_only' => $this->createLimitedOperator('refresh_client', client: true),
                'operator_only' => $this->createLimitedOperator('refresh_operator', operator: true),
                'management_only' => $this->createLimitedOperator('refresh_management', management: true),
                'all_permissions' => $this->createLimitedOperator('refresh_all', client: true, operator: true, management: true),
            ];

            foreach ($operators as $label => $operatorClient)
            {
                $operatorUuid = $operatorClient->getSelf()->getUuid();
                $originalToken = $operatorClient->getAccessToken();
                $newToken = $operatorClient->generateAccessToken(false);

                $this->assertNotEmpty($newToken, "Should generate a token for the $label operator");
                $this->assertNotEquals($originalToken, $newToken, "New token should differ for the $label operator");

                $newClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newToken);
                $this->assertEquals($operatorUuid, $newClient->getSelf()->getUuid());
            }
        }

        public function testGenerateAccessTokenRequiresAuthentication(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);

            $this->expectRequestFailure(
                fn() => $unauthenticatedClient->generateAccessToken(),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'Unauthenticated operator should be rejected when generating an Access Token'
            );
        }

        public function testGenerateAccessTokenRejectedForRootOperator(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->generateAccessToken(false),
                [HttpResponseCode::FORBIDDEN->value],
                'Builtin (root) operator should not be able to generate an Access Token'
            );
        }

        public function testGenerateAccessTokenEndpointRequiresPost(): void
        {
            [$code] = $this->rawRequest('GET', 'operators/refresh', getenv('SERVER_ACCESS_TOKEN'));
            $this->assertNotEquals(200, $code, 'GET /operators/refresh should not be accepted');
        }

        public function testGenerateAccessTokenIsLoggedInAuditTrail(): void
        {
            $operator = $this->createLimitedOperator('audited_refresh', client: true);
            $operatorUuid = $operator->getSelf()->getUuid();

            $newToken = $operator->generateAccessToken(false);
            $this->assertNotEmpty($newToken);

            $auditLogs = $this->client->listAuditLogs(1, 100);
            $matches = array_filter(
                $auditLogs,
                fn($log) => $log->getType() === AuditLogType::OPERATOR_ACCESS_TOKEN_GENERATED
                    && str_contains($log->getMessage(), $operatorUuid)
            );

            $this->assertNotEmpty($matches, 'Expected an audit entry for the generated Access Token');
        }
    }