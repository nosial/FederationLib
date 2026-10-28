<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;

    /**
     * An operator refreshing its own access token (Generate Access Token)
     */
    class GenerateAccessTokenTest extends ServerTestCase
    {
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
                [HttpResponseCode::UNAUTHORIZED->value],
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
                [HttpResponseCode::UNAUTHORIZED->value],
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
                [HttpResponseCode::UNAUTHORIZED->value],
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
            // GET /operators/refresh matches no method, a client error (OFD "Error Responses")
            [$code] = $this->rawRequest('GET', 'operators/refresh', $this->rootToken());
            $this->assertSame(HttpResponseCode::BAD_REQUEST->value, $code, 'GET /operators/refresh should not be accepted');
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
                fn($log) => $log->getType() === AuditLogType::OPERATOR_UPDATED
                    && str_contains($log->getMessage(), $operatorUuid)
            );

            $this->assertNotEmpty($matches, 'Expected an audit entry for the generated Access Token');
        }
    }