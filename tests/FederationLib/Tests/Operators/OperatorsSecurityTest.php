<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class OperatorsSecurityTest extends TestCase
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

        public function testSecurityUnauthenticatedRequestsAreRejected(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);
            $fakeUuid = '00000000-0000-0000-0000-000000000000';

            $operations = [
                'getSelf' => fn() => $unauthenticatedClient->getSelf(),
                'createOperator' => fn() => $unauthenticatedClient->createOperator('unauthorized'),
                'disableOperator' => fn() => $unauthenticatedClient->disableOperator($fakeUuid),
                'deleteOperator' => fn() => $unauthenticatedClient->deleteOperator($fakeUuid),
                'setOperatorPermissions' => fn() => $unauthenticatedClient->setOperatorPermissions($fakeUuid, true),
                'setManagementPermissions' => fn() => $unauthenticatedClient->setManagementPermissions($fakeUuid, true),
                'setClientPermissions' => fn() => $unauthenticatedClient->setClientPermissions($fakeUuid, true),
                'generateOperatorAccessToken' => fn() => $unauthenticatedClient->generateOperatorAccessToken($fakeUuid),
                'listOperators' => fn() => $unauthenticatedClient->listOperators(),
            ];

            foreach ($operations as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value], "Unauthenticated $name should be rejected");
            }
        }

        public function testSecurityMalformedAccessTokensAreRejected(): void
        {
            $shortTokenClient = new FederationClient(getenv('SERVER_ENDPOINT'), 'short');
            $this->expectRequestFailure(
                fn() => $shortTokenClient->getSelf(),
                [HttpResponseCode::BAD_REQUEST->value],
                'Too-short access token should be rejected'
            );

            $fake32CharToken = bin2hex(random_bytes(16));
            $nonExistentTokenClient = new FederationClient(getenv('SERVER_ENDPOINT'), $fake32CharToken);
            $this->expectRequestFailure(
                fn() => $nonExistentTokenClient->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value],
                'Non-existent 32-character access token should be rejected'
            );
        }

        public function testSecurityClientOnlyOperatorCannotPerformPrivilegedActions(): void
        {
            $clientOnly = $this->createLimitedOperator('client_only', client: true);
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);
            $report = $this->createSecurityReport();

            $privilegedOperations = [
                'createOperator' => fn() => $clientOnly->createOperator('child'),
                'blacklistEntity' => fn() => $clientOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM),
                'deleteReport' => fn() => $clientOnly->deleteReport($report['report']),
                'assignOperatorToReport' => fn() => $clientOnly->assignOperatorToReport($report['report'], $this->client->getSelf()->getUuid()),
                'closeReport' => fn() => $clientOnly->closeReport($report['report']),
                'deleteEntity' => fn() => $clientOnly->deleteEntity($entityUuid),
                'clearEntityReputation' => fn() => $clientOnly->clearEntityReputation($entityUuid),
                'deleteBlacklistRecord' => fn() => $clientOnly->deleteBlacklistRecord($blacklistUuid),
                'liftBlacklistRecord' => fn() => $clientOnly->liftBlacklistRecord($blacklistUuid),
                'listAttachments' => fn() => $clientOnly->listAttachments(),
            ];

            foreach ($privilegedOperations as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Client-only operator should not be able to $name");
            }
        }

        public function testSecurityOperatorOnlyOperatorCannotPerformClientActions(): void
        {
            $operatorOnly = $this->createLimitedOperator('operator_only', operator: true);
            $entityUuid = $this->createSecurityEntity();

            $clientActions = [
                'pushEntity' => fn() => $operatorOnly->pushEntity('example.com', 'user'),
                'submitEvidence' => fn() => $operatorOnly->submitEvidence($entityUuid, 'text', 'note', 'tag'),
                'submitReport' => fn() => $operatorOnly->submitReport($entityUuid, 'content', IncidentType::SPAM),
                'uploadNoteAttachment' => fn() => $operatorOnly->uploadNoteAttachment('00000000-0000-0000-0000-000000000000', 'note.txt', 'content'),
            ];

            foreach ($clientActions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Operator-only account should not be able to $name");
            }
        }

        public function testSecurityManagementOnlyOperatorCanManageRecordsButNotClientOrOperatorActions(): void
        {
            $managementOnly = $this->createLimitedOperator('management_only', management: true);
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $blacklistUuid = $managementOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            $this->assertNotEmpty($blacklistUuid);

            $report = $this->createSecurityReport();
            $managementOnly->assignOperatorToReport($report['report'], $managementOnly->getSelf()->getUuid());
            $managementOnly->closeReport($report['report']);
            $closedReport = $this->client->getReport($report['report']);
            $this->assertFalse($closedReport->isOpened());

            $forbiddenActions = [
                'createOperator' => fn() => $managementOnly->createOperator('child'),
                'pushEntity' => fn() => $managementOnly->pushEntity('example.com', 'user'),
                'submitEvidence' => fn() => $managementOnly->submitEvidence($entityUuid, 'text', 'note', 'tag'),
            ];

            foreach ($forbiddenActions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Management-only operator should not be able to $name");
            }
        }

        public function testSecurityConfidentialEvidenceRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $confidentialEvidenceUuid = $this->createSecurityEvidence($entityUuid, true);

            $clientOnly = $this->createLimitedOperator('confidential_client', client: true);
            $operatorOnly = $this->createLimitedOperator('confidential_operator', operator: true);
            $managementOnly = $this->createLimitedOperator('confidential_management', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->getEvidenceRecord($confidentialEvidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not view confidential evidence'
            );

            $this->expectRequestFailure(
                fn() => $operatorOnly->getEvidenceRecord($confidentialEvidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not view confidential evidence'
            );

            $evidenceRecord = $managementOnly->getEvidenceRecord($confidentialEvidenceUuid);
            $this->assertEquals($confidentialEvidenceUuid, $evidenceRecord->getUuid());
            $this->assertTrue($evidenceRecord->isConfidential());
        }

        public function testSecurityRootOperatorCannotBeModified(): void
        {
            $root = $this->client->getSelf();
            $attacker = $this->createLimitedOperator('root_attacker', operator: true, management: true, client: true);

            $attacks = [
                'disableOperator' => fn() => $attacker->disableOperator($root->getUuid()),
                'deleteOperator' => fn() => $attacker->deleteOperator($root->getUuid()),
                'setOperatorPermissions' => fn() => $attacker->setOperatorPermissions($root->getUuid(), false),
                'setManagementPermissions' => fn() => $attacker->setManagementPermissions($root->getUuid(), false),
                'setClientPermissions' => fn() => $attacker->setClientPermissions($root->getUuid(), false),
                'generateOperatorAccessToken' => fn() => $attacker->generateOperatorAccessToken($root->getUuid()),
            ];

            foreach ($attacks as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Root operator should be protected from $name");
            }
        }

        public function testSecurityReservedOperatorNamesAreRejected(): void
        {
            $reservedNames = ['root', 'system', 'ROOT', 'System'];

            foreach ($reservedNames as $name)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->createOperator($name),
                    [HttpResponseCode::BAD_REQUEST->value],
                    "Reserved operator name '$name' should be rejected"
                );
            }
        }

        public function testSecurityPermissionRevocationIsEffective(): void
        {
            $clientOperator = $this->createLimitedOperator('revocation_test', client: true);
            $operatorUuid = $clientOperator->getSelf()->getUuid();

            $firstEntityUuid = $clientOperator->pushEntity('revocation-test.com', 'user1');
            $this->createdEntities[] = $firstEntityUuid;
            $this->assertNotEmpty($firstEntityUuid);

            $this->client->setClientPermissions($operatorUuid, false);

            $this->expectRequestFailure(
                fn() => $clientOperator->pushEntity('revocation-test2.com', 'user2'),
                [HttpResponseCode::FORBIDDEN->value],
                'Revoked client permission should prevent pushing entities'
            );

            $this->client->setClientPermissions($operatorUuid, true);

            $secondEntityUuid = $clientOperator->pushEntity('revocation-test3.com', 'user3');
            $this->createdEntities[] = $secondEntityUuid;
            $this->assertNotEmpty($secondEntityUuid);
        }

        public function testSecurityDisabledOperatorCannotAuthenticate(): void
        {
            $operatorUuid = $this->client->createOperator('disabled_auth_test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $this->client->getOperator($operatorUuid)->getAccessToken());
            $this->assertNotEmpty($operatorClient->getSelf()->getUuid());

            $this->client->disableOperator($operatorUuid);

            $this->expectRequestFailure(
                fn() => $operatorClient->getSelf(),
                [HttpResponseCode::FORBIDDEN->value],
                'Disabled operator should not be able to call getSelf'
            );
        }

        public function testAuthorizationHeaderVariationsAreHandled(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');

            // Missing Bearer prefix should be rejected.
            [$noBearerCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: ' . $token]);
            $this->assertContains($noBearerCode, [400, 401], 'Token without Bearer prefix should be rejected');

            // Wrong scheme should be rejected.
            [$basicCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Basic ' . base64_encode('user:pass')]);
            $this->assertContains($basicCode, [400, 401], 'Basic auth should be rejected');

            // Empty Bearer token should be treated as unauthenticated.
            [$emptyCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ']);
            $this->assertContains($emptyCode, [400, 401], 'Empty Bearer token should be rejected');

            // Valid Bearer token should succeed.
            [$validCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ' . $token]);
            $this->assertEquals(200, $validCode, 'Valid Bearer token should succeed');
        }

        public function testInvalidAccessTokenFormatsAreRejected(): void
        {
            $invalidTokens = [
                'short',
                'exactly31characterslong1234567',
                'exactly33characterslong12345678',
                str_repeat('a', 32) . ' ',
                str_repeat('a', 32) . "\n",
                ' ' . str_repeat('a', 32),
            ];

            foreach ($invalidTokens as $token)
            {
                // Bypass client-side validation so the server itself rejects the malformed token.
                [$code] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ' . $token]);
                $this->assertContains(
                    $code,
                    [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                    "Invalid token format '$token' should be rejected by server"
                );
            }
        }

        public function testDisabledOperatorTokenRejectedAcrossAllEndpoints(): void
        {
            $operatorUuid = $this->client->createOperator('disabled_all_endpoints');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $this->client->getOperator($operatorUuid)->getAccessToken());
            $this->assertNotEmpty($operatorClient->getSelf()->getUuid());

            $this->client->disableOperator($operatorUuid);

            $endpoints = [
                'getSelf' => fn() => $operatorClient->getSelf(),
                'pushEntity' => fn() => $operatorClient->pushEntity('disabled-endpoints.com', 'user'),
                'listOperators' => fn() => $operatorClient->listOperators(),
                'listEvidence' => fn() => $operatorClient->listEvidence(),
                'listReports' => fn() => $operatorClient->listReports(),
                'listBlacklist' => fn() => $operatorClient->listBlacklistRecords(),
                'listAttachments' => fn() => $operatorClient->listAttachments(),
            ];

            foreach ($endpoints as $name => $callback)
            {
                $this->expectRequestFailure(
                    $callback,
                    [HttpResponseCode::FORBIDDEN->value],
                    "Disabled operator should be rejected for $name"
                );
            }
        }

        public function testSystemOperatorCannotAuthenticate(): void
        {
            // The system operator has token 'none' and cannot authenticate.
            $systemClient = new FederationClient(getenv('SERVER_ENDPOINT'), 'none');
            $this->expectRequestFailure(
                fn() => $systemClient->getSelf(),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                'System operator token should not authenticate'
            );
        }

        public function testSecurityListOperatorsDoesNotExposeOtherOperatorTokens(): void
        {
            $manager = $this->createLimitedOperator('token_leak_manager', operator: true);
            $this->createLimitedOperator('token_leak_victim', client: true);

            $operators = $manager->listOperators(1, 1000);
            $managerUuid = $manager->getSelf()->getUuid();

            foreach ($operators as $operator)
            {
                if ($operator->getUuid() === $managerUuid)
                {
                    continue;
                }

                $this->assertEmpty(
                    $operator->getAccessToken(),
                    sprintf('Operator list should not expose access tokens for operator %s', $operator->getName())
                );
            }
        }

        public function testSecurityDisabledOperatorDataIsolation(): void
        {
            $operatorUuid = $this->client->createOperator('disabled_isolation');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('disabled-isolation.com', 'di_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Disabled operator evidence', 'Note', 'di');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->disableOperator($operatorUuid);

            $record = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($record, 'Entity created by now-disabled operator should still be readable by management');

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord, 'Evidence created by now-disabled operator should still be readable by management');
        }
    }
