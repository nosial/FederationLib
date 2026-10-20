<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\ContentInput;
    use PHPUnit\Framework\TestCase;

    /**
     * The permissions and configuration of operators (OFD 2.0): Update Operator Permissions, Update Operator
     * Configuration, the visibility of the configuration, and the automatic assignment of each kind of report
     */
    class OperatorPermissionsConfigurationTest extends TestCase
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
            $this->cleanupRecords();
        }

        /**
         * Creates an operator without any permissions
         *
         * @return array{0: string, 1: string} The operator UUID and its access token
         */
        private function createOperator(string $prefix): array
        {
            $created = $this->client->createOperator(substr(uniqid($prefix . '_'), 0, 32));
            $this->createdOperators[] = $created->getUuid();
            return [$created->getUuid(), $created->getAccessToken()];
        }

        private function patchPermissions(string $operatorUuid, mixed $body, ?string $token = null): array
        {
            return $this->jsonRequest('PATCH', '/operators/' . $operatorUuid . '/permissions', $token ?? $this->rootToken(), is_array($body) ? $body : null);
        }

        private function patchConfiguration(string $operatorUuid, mixed $body, ?string $token = null): array
        {
            return $this->jsonRequest('PATCH', '/operators/' . $operatorUuid . '/configuration', $token ?? $this->rootToken(), is_array($body) ? $body : null);
        }

        private function getRootUuid(): string
        {
            return $this->client->getSelf()->getUuid();
        }

        public function testNewOperatorHasNoPermissionsOrConfiguration(): void
        {
            [$operatorUuid] = $this->createOperator('defaults');

            [$code, $body] = $this->jsonRequest('GET', '/operators/' . $operatorUuid, $this->rootToken());

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['client' => false, 'management' => false, 'operator' => false], $body['permissions']);
            $this->assertSame(['auto_assign_reports' => false, 'auto_assign_automated_reports' => false, 'is_bot' => false], $body['configuration']);
            // The flat members of OFD 1.x are gone
            foreach(['client_permissions', 'management_permissions', 'operator_permissions', 'auto_assign', 'is_bot'] as $member)
            {
                $this->assertArrayNotHasKey($member, $body, $member);
            }
        }

        public function testUpdatePermissionsLeavesUnsuppliedGroupsUnchanged(): void
        {
            [$operatorUuid] = $this->createOperator('perm_partial');

            [$code, $body] = $this->patchPermissions($operatorUuid, ['operator' => true]);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['client' => false, 'management' => false, 'operator' => true], $body);

            [$code, $body] = $this->patchPermissions($operatorUuid, ['client' => true]);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['client' => true, 'management' => false, 'operator' => true], $body);

            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->hasClientPermissions());
            $this->assertTrue($operator->hasOperatorPermissions());
            $this->assertFalse($operator->hasManagementPermissions());
        }

        public function testManagementInheritsClientPermissions(): void
        {
            [$operatorUuid] = $this->createOperator('perm_inherit');

            [$code, $body] = $this->patchPermissions($operatorUuid, ['management' => true]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['client' => true, 'management' => true, 'operator' => false], $body);
            $this->assertTrue($this->client->getOperator($operatorUuid)->hasClientPermissions());
        }

        public function testRevokingClientFromManagerConflicts(): void
        {
            [$operatorUuid] = $this->createOperator('perm_conflict');
            $this->patchPermissions($operatorUuid, ['management' => true]);

            [$code, $body] = $this->patchPermissions($operatorUuid, ['client' => false]);
            $this->assertSame(409, $code, json_encode($body));

            [$code, $body] = $this->patchPermissions($operatorUuid, ['client' => false, 'management' => true]);
            $this->assertSame(409, $code, json_encode($body));

            // The permissions are unchanged
            $this->assertTrue($this->client->getOperator($operatorUuid)->hasManagementPermissions());

            // Revoking both at once is not a conflict
            [$code, $body] = $this->patchPermissions($operatorUuid, ['client' => false, 'management' => false]);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['client' => false, 'management' => false, 'operator' => false], $body);
        }

        public function testUpdatePermissionsRejectsInvalidBodies(): void
        {
            [$operatorUuid] = $this->createOperator('perm_invalid');

            foreach([[], ['bogus' => true], ['client' => 'true'], ['client' => 1], ['client' => null], ['auto_assign_reports' => true]] as $body)
            {
                [$code, $response] = $this->rawRequest('PATCH', '/operators/' . $operatorUuid . '/permissions', $this->rootToken(), json_encode((object)$body));
                $this->assertSame(400, $code, json_encode($body) . ' ' . $response);
            }

            [$code, $response] = $this->rawRequest('PATCH', '/operators/' . $operatorUuid . '/permissions', $this->rootToken(), json_encode([true]));
            $this->assertSame(400, $code, $response);
        }

        public function testUpdatePermissionsRequiresOperatorPermissions(): void
        {
            [$targetUuid] = $this->createOperator('perm_target');

            foreach(['client' => [true, false, false], 'management' => [false, true, false]] as $name => [$client, $management, $operator])
            {
                $actor = $this->createLimitedOperator('perm_' . $name, $management, $operator, $client);
                [$code, $response] = $this->rawRequest('PATCH', '/operators/' . $targetUuid . '/permissions', $actor->getAccessToken(), json_encode(['client' => true]));
                $this->assertSame(403, $code, $name . ' ' . $response);
            }

            // An operator can not grant itself permissions
            [$selfUuid, $selfToken] = $this->createOperator('perm_self');
            [$code] = $this->patchPermissions($selfUuid, ['operator' => true], $selfToken);
            $this->assertSame(403, $code);

            [$code] = $this->rawRequest('PATCH', '/operators/' . $targetUuid . '/permissions', null, json_encode(['client' => true]));
            $this->assertSame(401, $code);

            // An operator with operator permissions can
            $manager = $this->createLimitedOperator('perm_operator', operator: true);
            [$code, $body] = $this->patchPermissions($targetUuid, ['client' => true], $manager->getAccessToken());
            $this->assertSame(200, $code, json_encode($body));
        }

        public function testUpdatePermissionsTargets(): void
        {
            [$code] = $this->patchPermissions($this->randomUuid(), ['client' => true]);
            $this->assertSame(404, $code);

            [$code] = $this->rawRequest('PATCH', '/operators/not-a-uuid/permissions', $this->rootToken(), json_encode(['client' => true]));
            $this->assertContains($code, [400, 404]);

            [$code] = $this->patchPermissions($this->getRootUuid(), ['operator' => false]);
            $this->assertSame(403, $code);
        }

        public function testUpdateConfiguration(): void
        {
            [$operatorUuid] = $this->createOperator('config');

            [$code, $body] = $this->patchConfiguration($operatorUuid, ['auto_assign_automated_reports' => true]);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['auto_assign_reports' => false, 'auto_assign_automated_reports' => true, 'is_bot' => false], $body);

            [$code, $body] = $this->patchConfiguration($operatorUuid, ['is_bot' => true, 'auto_assign_reports' => true]);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['auto_assign_reports' => true, 'auto_assign_automated_reports' => true, 'is_bot' => true], $body);

            $configuration = $this->client->updateOperatorConfiguration($operatorUuid, autoAssignAutomatedReports: false);
            $this->assertTrue($configuration->isAutoAssignReports());
            $this->assertFalse($configuration->isAutoAssignAutomatedReports());
            $this->assertTrue($configuration->isBot());
        }

        public function testUpdateConfigurationRejectsInvalidBodies(): void
        {
            [$operatorUuid] = $this->createOperator('config_invalid');

            foreach([[], ['auto_assign' => true], ['is_bot' => 'yes'], ['is_bot' => 0], ['client' => true]] as $body)
            {
                [$code, $response] = $this->rawRequest('PATCH', '/operators/' . $operatorUuid . '/configuration', $this->rootToken(), json_encode((object)$body));
                $this->assertSame(400, $code, json_encode($body) . ' ' . $response);
            }
        }

        public function testOperatorChangesItsOwnConfiguration(): void
        {
            // No permissions are needed to change one's own configuration
            [$operatorUuid, $token] = $this->createOperator('config_self');

            [$code, $body] = $this->patchConfiguration($operatorUuid, ['is_bot' => true], $token);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($body['is_bot']);

            $self = (new FederationClient(getenv('SERVER_ENDPOINT'), $token))->getSelf();
            $this->assertTrue($self->isBot());
        }

        public function testOperatorCannotChangeAnotherOperatorsConfiguration(): void
        {
            [$targetUuid] = $this->createOperator('config_target');
            $manager = $this->createLimitedOperator('config_manager', management: true);

            [$code] = $this->patchConfiguration($targetUuid, ['is_bot' => true], $manager->getAccessToken());
            $this->assertSame(403, $code);

            [$code] = $this->rawRequest('PATCH', '/operators/' . $targetUuid . '/configuration', null, json_encode(['is_bot' => true]));
            $this->assertSame(401, $code);

            $this->assertFalse($this->client->getOperator($targetUuid)->isBot());

            $operatorManager = $this->createLimitedOperator('config_operator', operator: true);
            [$code, $body] = $this->patchConfiguration($targetUuid, ['is_bot' => true], $operatorManager->getAccessToken());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($this->client->getOperator($targetUuid)->isBot());
        }

        public function testRootConfigurationCannotBeModified(): void
        {
            [$code] = $this->patchConfiguration($this->getRootUuid(), ['is_bot' => true]);
            $this->assertSame(403, $code);
        }

        public function testConfigurationVisibleToItselfAndOperatorPermissions(): void
        {
            [$targetUuid, $targetToken] = $this->createOperator('visible_target');
            $this->patchConfiguration($targetUuid, ['is_bot' => true]);

            // The operator itself
            [$code, $body] = $this->jsonRequest('GET', '/operators/' . $targetUuid, $targetToken);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['auto_assign_reports' => false, 'auto_assign_automated_reports' => false, 'is_bot' => true], $body['configuration']);

            [$code, $body] = $this->jsonRequest('GET', '/operators/self', $targetToken);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($body['configuration']['is_bot']);

            // An operator with operator permissions
            $operatorManager = $this->createLimitedOperator('visible_operator', operator: true);
            [$code, $body] = $this->jsonRequest('GET', '/operators/' . $targetUuid, $operatorManager->getAccessToken());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($body['configuration']['is_bot']);
        }

        public function testConfigurationHiddenFromEveryoneElse(): void
        {
            [$targetUuid] = $this->createOperator('hidden_target');
            $this->patchConfiguration($targetUuid, ['is_bot' => true, 'auto_assign_reports' => true]);

            $viewers = [
                'anonymous' => null,
                'no permissions' => $this->createOperator('hidden_none')[1],
                'client' => $this->createLimitedOperator('hidden_client', client: true)->getAccessToken(),
                'management' => $this->createLimitedOperator('hidden_management', management: true)->getAccessToken(),
            ];

            foreach($viewers as $name => $token)
            {
                // Operator records are public
                [$code, $body] = $this->jsonRequest('GET', '/operators/' . $targetUuid, $token);
                $this->assertSame(200, $code, $name . ' ' . json_encode($body));

                // The member is present and null, the permissions stay visible
                $this->assertArrayHasKey('configuration', $body, $name);
                $this->assertNull($body['configuration'], $name);
                $this->assertSame(['client' => false, 'management' => false, 'operator' => false], $body['permissions'], $name);
            }
        }

        public function testConfigurationVisibilityInListsAndSearch(): void
        {
            [$targetUuid] = $this->createOperator('list_target');
            [$viewerUuid, $viewerToken] = $this->createOperator('list_viewer');

            [$code, $operators] = $this->jsonRequest('GET', '/operators?limit=100', $viewerToken);
            $this->assertSame(200, $code, json_encode($operators));
            foreach($operators as $operator)
            {
                if($operator['uuid'] === $viewerUuid)
                {
                    $this->assertIsArray($operator['configuration']);
                }
                else
                {
                    $this->assertNull($operator['configuration'], $operator['uuid']);
                }
            }

            [$code, $operators] = $this->jsonRequest('GET', '/operators?limit=100', $this->rootToken());
            $this->assertSame(200, $code);
            foreach($operators as $operator)
            {
                $this->assertIsArray($operator['configuration'], $operator['uuid']);
            }

            // Operator search is enabled by default
            [$code, $results] = $this->jsonRequest('GET', '/operators/search?q=list_target', $viewerToken);
            $this->assertSame(200, $code, json_encode($results));
            foreach($results as $operator)
            {
                $this->assertNull($operator['configuration']);
            }
            $this->assertContains($targetUuid, array_column($results, 'uuid'));
        }

        public function testClientSeesHiddenConfigurationAsNull(): void
        {
            [$targetUuid] = $this->createOperator('client_target');
            $viewer = new FederationClient(getenv('SERVER_ENDPOINT'), $this->createOperator('client_viewer')[1]);

            $record = $viewer->getOperator($targetUuid);
            $this->assertNull($record->getConfiguration());
            $this->assertNotNull($this->client->getOperator($targetUuid)->getConfiguration());
        }

        public function testConfigurationChangesAreAudited(): void
        {
            [$operatorUuid] = $this->createOperator('config_audit');
            $this->patchConfiguration($operatorUuid, ['auto_assign_reports' => true]);
            $this->patchPermissions($operatorUuid, ['client' => true]);

            $messages = array_map(fn($log) => $log->getMessage(), $this->client->listOperatorAuditLogs($this->getRootUuid(), 1, 50));
            $this->assertNotEmpty(array_filter($messages, fn(string $message) => str_contains($message, 'auto_assign_reports set to true')));
            $this->assertNotEmpty(array_filter($messages, fn(string $message) => str_contains($message, 'client granted')));
        }

        public function testReportsAreOnlyAssignedToOperatorsOptedInToTheirKind(): void
        {
            // An operator opted in to automated reports only must never receive an ordinary report
            $automatedOnly = $this->createLimitedOperator('kind_automated', management: true);
            $automatedOnlyUuid = $automatedOnly->getSelf()->getUuid();
            $this->client->updateOperatorConfiguration($automatedOnlyUuid, false, true);

            $ordinary = $this->createLimitedOperator('kind_ordinary', management: true);
            $ordinaryUuid = $ordinary->getSelf()->getUuid();
            $this->client->updateOperatorConfiguration($ordinaryUuid, true, false);

            $submitter = $this->createLimitedOperator('kind_submitter', client: true);
            $entityUuid = $this->createSecurityEntity($submitter);

            $assigned = [];
            for($i = 0; $i < 4; $i++)
            {
                $reportUuid = $this->createReportForEntity($entityUuid, $submitter, 'Ordinary report ' . $i);
                $report = $this->client->getReport($reportUuid);
                $this->assertFalse($report->isAutomated());

                $assignedUuid = $report->getAssignedOperator();
                $this->assertNotNull($assignedUuid, 'An eligible operator exists, the report must be assigned');
                $this->assertNotSame($automatedOnlyUuid, $assignedUuid);
                $this->assertTrue($this->client->getOperator($assignedUuid)->isAutoAssignReports());
                $assigned[] = $assignedUuid;
            }

            $this->assertContains($ordinaryUuid, $assigned);
        }

        public function testBotReportsAreAssignedToOperatorsOptedInToAutomatedReports(): void
        {
            $ordinaryOnly = $this->createLimitedOperator('bot_kind_ordinary', management: true);
            $ordinaryOnlyUuid = $ordinaryOnly->getSelf()->getUuid();
            $this->client->updateOperatorConfiguration($ordinaryOnlyUuid, true, false);

            $automated = $this->createLimitedOperator('bot_kind_automated', management: true);
            $automatedUuid = $automated->getSelf()->getUuid();
            $this->client->updateOperatorConfiguration($automatedUuid, false, true);

            $bot = $this->createLimitedOperator('bot_kind_bot', client: true);
            $this->client->setBot($bot->getSelf()->getUuid(), true);
            $entityUuid = $this->createSecurityEntity($bot);

            $assigned = [];
            for($i = 0; $i < 4; $i++)
            {
                $reportUuid = $this->createReportForEntity($entityUuid, $bot, 'Bot report ' . $i);
                $report = $this->client->getReport($reportUuid);
                $this->assertTrue($report->isAutomated(), 'A report submitted by a bot is automated');

                $assignedUuid = $report->getAssignedOperator();
                $this->assertNotNull($assignedUuid);
                $this->assertNotSame($ordinaryOnlyUuid, $assignedUuid);
                $this->assertTrue($this->client->getOperator($assignedUuid)->isAutoAssignAutomatedReports());
                $assigned[] = $assignedUuid;
            }

            $this->assertContains($automatedUuid, $assigned);
        }

        public function testDisabledOperatorsAreNotAssigned(): void
        {
            $disabled = $this->createLimitedOperator('assign_disabled', management: true);
            $disabledUuid = $disabled->getSelf()->getUuid();
            $this->client->setAutoAssign($disabledUuid, true);
            $this->client->disableOperator($disabledUuid);

            $submitter = $this->createLimitedOperator('assign_submitter', client: true);
            $entityUuid = $this->createSecurityEntity($submitter);

            for($i = 0; $i < 4; $i++)
            {
                $reportUuid = $this->createReportForEntity($entityUuid, $submitter, 'Report ' . $i);
                $this->assertNotSame($disabledUuid, $this->client->getReport($reportUuid)->getAssignedOperator());
            }
        }

        public function testOperatorsWithoutManagementAreNotAssigned(): void
        {
            [$clientUuid] = $this->createOperator('assign_client');
            $this->client->updateOperatorPermissions($clientUuid, client: true);
            $this->client->setAutoAssign($clientUuid, true);

            $submitter = $this->createLimitedOperator('assign_submitter', client: true);
            $entityUuid = $this->createSecurityEntity($submitter);

            for($i = 0; $i < 4; $i++)
            {
                $submission = $submitter->submitReport($entityUuid, new ContentInput('Report ' . $i), IncidentType::SPAM);
                $this->createdReports[] = $submission->getReport()->getUuid();
                foreach($submission->getEvidence() as $evidence)
                {
                    $this->createdEvidenceRecords[] = $evidence->getUuid();
                }

                $this->assertNotSame($clientUuid, $this->client->getReport($submission->getReport()->getUuid())->getAssignedOperator());
            }
        }
    }
