<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\OperatorRecord;

    class OperatorsSecurityTest extends ServerTestCase
    {
        public function testUnauthenticatedOperatorActionsAreRejected(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);
            $uuid = $this->randomUuid();

            $operations = [
                'getSelf' => fn() => $unauthenticatedClient->getSelf(),
                'createOperator' => fn() => $unauthenticatedClient->createOperator('unauthorized'),
                'disableOperator' => fn() => $unauthenticatedClient->disableOperator($uuid),
                'deleteOperator' => fn() => $unauthenticatedClient->deleteOperator($uuid),
                'setOperatorPermissions' => fn() => $unauthenticatedClient->setOperatorPermissions($uuid, true),
                'setManagementPermissions' => fn() => $unauthenticatedClient->setManagementPermissions($uuid, true),
                'setClientPermissions' => fn() => $unauthenticatedClient->setClientPermissions($uuid, true),
                'setAutoAssign' => fn() => $unauthenticatedClient->setAutoAssign($uuid, true),
                'generateOperatorAccessToken' => fn() => $unauthenticatedClient->generateOperatorAccessToken($uuid),
            ];

            foreach ($operations as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::UNAUTHORIZED->value], "Unauthenticated $name should be rejected");
            }
        }

        public function testAccessTokensAreNeverExposed(): void
        {
            $operatorName = substr(uniqid('public_op_'), 0, 32);
            $created = $this->client->createOperator($operatorName);
            $operatorUuid = $created->getUuid();
            $this->createdOperators[] = $operatorUuid;

            // Only the creation returns the access token
            $this->assertSame(32, strlen($created->getAccessToken()));

            $viewers = [
                'anonymous' => new FederationClient(getenv('SERVER_ENDPOINT'), null),
                'client' => $this->createLimitedOperator('token_no_viewer', client: true),
                'operator' => $this->createLimitedOperator('token_viewer_manager', management: true, operator: true),
                'root' => $this->client,
            ];

            foreach ($viewers as $label => $viewer)
            {
                // Operator records are public by default
                $record = $viewer->getOperator($operatorUuid);
                $this->assertEquals($operatorUuid, $record->getUuid(), $label);
                $this->assertEmpty($record->getAccessToken(), "The operator record should not expose the access token to the $label viewer");

                $listed = $viewer->listOperators(1, 1000);
                $this->assertContains($operatorUuid, array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $listed), $label);
                foreach ($listed as $listedOperator)
                {
                    $this->assertEmpty($listedOperator->getAccessToken(), "The operator list should not expose access tokens to the $label viewer");
                }

                $results = $viewer->searchOperators($operatorName);
                $this->assertContains($operatorUuid, array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $results), $label);
                foreach ($results as $result)
                {
                    $this->assertEmpty($result->getAccessToken(), "The operator search should not expose access tokens to the $label viewer");
                }
            }
        }

        public function testAccessTokenValidity(): void
        {
            // OFD "Access Tokens": a token that is not 32 characters is malformed (400), a well-formed token of no
            // operator is not authenticated (401); whitespace around the token is not part of it
            $cases = [
                'short' => HttpResponseCode::BAD_REQUEST,
                'none' => HttpResponseCode::BAD_REQUEST,
                str_repeat('a', 31) => HttpResponseCode::BAD_REQUEST,
                str_repeat('a', 33) => HttpResponseCode::BAD_REQUEST,
                bin2hex(random_bytes(16)) => HttpResponseCode::UNAUTHORIZED,
                str_repeat('a', 32) . ' ' => HttpResponseCode::UNAUTHORIZED,
                ' ' . str_repeat('a', 32) => HttpResponseCode::UNAUTHORIZED,
            ];

            foreach ($cases as $token => $expected)
            {
                // Bypasses the client's validation so the server itself rejects the token
                [$code] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ' . $token]);
                $this->assertSame($expected->value, $code, 'Token ' . json_encode($token));
            }
        }

        public function testAuthorizationHeaderVariations(): void
        {
            // A header that does not carry a bearer token authenticates nobody
            foreach (['Authorization: ' . $this->rootToken(), 'Authorization: Basic ' . base64_encode('user:pass'), 'Authorization: Bearer '] as $header)
            {
                [$code] = $this->rawRequest('GET', 'operators/self', null, null, [$header]);
                $this->assertSame(HttpResponseCode::UNAUTHORIZED->value, $code, $header);
            }

            // The authentication scheme is case-insensitive
            foreach (['Bearer', 'bearer', 'BEARER'] as $scheme)
            {
                [$code] = $this->rawRequest('GET', 'operators/self', null, null, ["Authorization: $scheme " . $this->rootToken()]);
                $this->assertSame(200, $code, $scheme);
            }
        }

        public function testClientOnlyOperatorCannotPerformPrivilegedActions(): void
        {
            $clientOnly = $this->createLimitedOperator('client_only', client: true);
            $entityUuid = $this->createSecurityEntity();
            $reportUuid = $this->createReportForEntity($entityUuid);
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $privilegedOperations = [
                'createOperator' => fn() => $clientOnly->createOperator('child'),
                'blacklistEntity' => fn() => $clientOnly->blacklistEntity($entityUuid, $reportUuid, IncidentType::SPAM),
                'deleteReport' => fn() => $clientOnly->deleteReport($reportUuid),
                'assignOperatorToReport' => fn() => $clientOnly->assignOperatorToReport($reportUuid, $this->client->getSelf()->getUuid()),
                'closeReport' => fn() => $clientOnly->closeReport($reportUuid),
                'deleteEntity' => fn() => $clientOnly->deleteEntity($entityUuid),
                'clearEntityReputation' => fn() => $clientOnly->clearEntityReputation($entityUuid),
                'deleteBlacklistRecord' => fn() => $clientOnly->deleteBlacklistRecord($blacklistUuid),
                'liftBlacklistRecord' => fn() => $clientOnly->liftBlacklistRecord($blacklistUuid),
                'listAttachments' => fn() => $clientOnly->listAttachments(),
                'updateEvidenceTag' => fn() => $clientOnly->updateEvidenceTag($this->createSecurityEvidence($entityUuid), 'client_tag'),
            ];

            foreach ($privilegedOperations as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Client-only operator should not be able to $name");
            }
        }

        public function testOperatorOnlyOperatorCannotPerformClientOrManagementActions(): void
        {
            $operatorOnly = $this->createLimitedOperator('operator_only', operator: true);
            $entityUuid = $this->createSecurityEntity();
            $report = $this->createSecurityReport();

            $actions = [
                'pushEntity' => fn() => $operatorOnly->pushEntity($this->uniqueHost('operator-only'), 'user'),
                'submitEvidence' => fn() => $operatorOnly->submitEvidence($entityUuid, 'text', 'note', 'tag'),
                'submitReport' => fn() => $operatorOnly->submitReport($entityUuid, ['text_content' => 'content'], IncidentType::SPAM),
                'uploadNoteAttachment' => fn() => $operatorOnly->uploadNoteAttachment($report['evidence'], 'note.txt', 'content'),
                'assignOperatorToReport' => fn() => $operatorOnly->assignOperatorToReport($report['report'], $operatorOnly->getSelf()->getUuid()),
            ];

            foreach ($actions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Operator-only account should not be able to $name");
            }
        }

        public function testManagementOnlyOperatorCanManageRecordsButNotOperators(): void
        {
            $managementOnly = $this->createLimitedOperator('management_only', management: true);
            $managementUuid = $managementOnly->getSelf()->getUuid();

            // Management permissions include client permissions (OFD "Permission Groups")
            $this->assertTrue($managementOnly->getSelf()->hasClientPermissions());
            $entityUuid = $this->createSecurityEntity($managementOnly);
            $this->createSecurityEvidence($entityUuid, client: $managementOnly);

            $this->createdBlacklistRecords[] = $managementOnly->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() + 3600);

            $report = $this->createSecurityReport();
            $managementOnly->assignOperatorToReport($report['report'], $managementUuid);
            $managementOnly->closeReport($report['report']);
            $this->assertFalse($this->client->getReport($report['report'])->isOpened());

            foreach ([
                'createOperator' => fn() => $managementOnly->createOperator('child'),
                'updateEvidenceTag' => fn() => $managementOnly->updateEvidenceTag($report['evidence'], 'management_tag'),
                'setClientPermissions' => fn() => $managementOnly->setClientPermissions($managementUuid, true),
            ] as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Management-only operator should not be able to $name");
            }
        }

        public function testBuiltinOperatorsAreProtected(): void
        {
            $root = $this->client->getSelf();
            $attacker = $this->createLimitedOperator('root_attacker', operator: true, management: true, client: true);

            $attacks = [
                'disableOperator' => fn() => $attacker->disableOperator($root->getUuid()),
                'deleteOperator' => fn() => $attacker->deleteOperator($root->getUuid()),
                'setOperatorPermissions' => fn() => $attacker->setOperatorPermissions($root->getUuid(), false),
                'setManagementPermissions' => fn() => $attacker->setManagementPermissions($root->getUuid(), false),
                'setClientPermissions' => fn() => $attacker->setClientPermissions($root->getUuid(), false),
                'setAutoAssign' => fn() => $attacker->setAutoAssign($root->getUuid(), true),
                'generateOperatorAccessToken' => fn() => $attacker->generateOperatorAccessToken($root->getUuid()),
            ];

            foreach ($attacks as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Root operator should be protected from $name");
            }

            foreach (['root', 'system', 'ROOT', 'System'] as $name)
            {
                $this->expectRequestFailure(fn() => $this->client->createOperator($name), [HttpResponseCode::BAD_REQUEST->value], "Reserved operator name '$name' should be rejected");
            }
        }

        public function testDisabledOperatorTokenIsRejected(): void
        {
            $created = $this->client->createOperator(substr(uniqid('disabled_all_'), 0, 32));
            $operatorUuid = $created->getUuid();
            $this->createdOperators[] = $operatorUuid;
            $this->client->setManagementPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $created->getAccessToken());
            $entityUuid = $this->createSecurityEntity($operatorClient);
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $operatorClient);

            $this->client->disableOperator($operatorUuid);

            foreach ([
                'getSelf' => fn() => $operatorClient->getSelf(),
                'pushEntity' => fn() => $operatorClient->pushEntity($this->uniqueHost('disabled-endpoints'), 'user'),
                'listAttachments' => fn() => $operatorClient->listAttachments(),
            ] as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Disabled operator should be rejected for $name");
            }

            // Public methods process the token of a disabled operator as unauthenticated...
            $this->assertContains($operatorUuid, array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $operatorClient->listOperators(1, 1000)));
            $this->assertIsArray($operatorClient->listEvidence());

            // ...and never with the permissions the disabled operator held
            $this->expectRequestFailure(
                fn() => $operatorClient->listEvidence(includeConfidential: true),
                [HttpResponseCode::UNAUTHORIZED->value],
                'A disabled operator should not list confidential evidence through a public method'
            );

            // Disabling an operator preserves its records (OFD "Delete Operator")
            $this->assertEquals($entityUuid, $this->client->getEntityRecord($entityUuid)->getUuid());
            $this->assertEquals($evidenceUuid, $this->client->getEvidenceRecord($evidenceUuid)->getUuid());
        }
    }
