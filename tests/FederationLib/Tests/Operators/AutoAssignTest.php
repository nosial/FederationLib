<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\OperatorRecord;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class AutoAssignTest extends TestCase
    {
        use TestHelpers;
        private const string BENIGN_SAMPLE_TEXT = 'This is a simple, benign message used for scanning tests.';

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

        public function testAutoAssignDefaultsToFalse(): void
        {
            $createdOperator = $this->client->createOperator(substr('auto_assign_default_' . uniqid(), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->isAutoAssigned(), 'New operators should not have auto assign enabled by default');
        }

        public function testAutoAssignIsVisibleOnSelfAndOperatorList(): void
        {
            $createdOperator = $this->client->createOperator(substr('auto_assign_visible_' . uniqid(), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            $this->client->setAutoAssign($operatorUuid, true);

            $selfRecord = (new FederationClient(getenv('SERVER_ENDPOINT'), $createdOperator->getAccessToken()))->getSelf();
            $this->assertTrue($selfRecord->isAutoAssigned(), 'Operator should see its own auto assign flag via getSelf');

            $found = false;
            foreach ($this->client->listOperators(1, 1000) as $listedOperator)
            {
                if ($listedOperator->getUuid() === $operatorUuid)
                {
                    $found = true;
                    $this->assertTrue($listedOperator->isAutoAssigned(), 'Operator list should expose the auto assign flag');
                }
            }
            $this->assertTrue($found, 'Created operator should be present in the operator list');
        }

        public function testAutoAssignCanBeEnabled(): void
        {
            $createdOperator = $this->client->createOperator(substr('auto_assign_enable_' . uniqid(), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            $this->client->setAutoAssign($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->isAutoAssigned(), 'Auto assign should be enabled after enabling it');
        }

        public function testAutoAssignCanBeDisabled(): void
        {
            $createdOperator = $this->client->createOperator(substr('auto_assign_disable_' . uniqid(), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            $this->client->setAutoAssign($operatorUuid, true);
            $this->client->setAutoAssign($operatorUuid, false);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->isAutoAssigned(), 'Auto assign should be disabled after disabling it');
        }

        public function testAutoAssignTogglesPersistAcrossRequests(): void
        {
            $createdOperator = $this->client->createOperator(substr('auto_assign_toggle_' . uniqid(), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            for ($toggle = 1; $toggle <= 2; $toggle++)
            {
                $this->client->setAutoAssign($operatorUuid, true);
                $this->assertTrue($this->client->getOperator($operatorUuid)->isAutoAssigned());

                $this->client->setAutoAssign($operatorUuid, false);
                $this->assertFalse($this->client->getOperator($operatorUuid)->isAutoAssigned());
            }
        }

        public function testAutoAssignRequiresOperatorManagementPermissions(): void
        {
            $targetOperator = $this->client->createOperator(substr('auto_assign_target_' . uniqid(), 0, 32));
            $targetUuid = $targetOperator->getUuid();
            $this->createdOperators[] = $targetUuid;

            $clientOnly = $this->createLimitedOperator('auto_assign_client', client: true);
            $managementOnly = $this->createLimitedOperator('auto_assign_management', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->setAutoAssign($targetUuid, true),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not be able to modify auto assign'
            );

            $this->expectRequestFailure(
                fn() => $managementOnly->setAutoAssign($targetUuid, true),
                [HttpResponseCode::FORBIDDEN->value],
                'Management-only operator should not be able to modify auto assign'
            );
        }

        public function testAutoAssignManagerCanToggleOtherOperators(): void
        {
            $targetOperator = $this->client->createOperator(substr('auto_assign_mgr_target_' . uniqid(), 0, 32));
            $targetUuid = $targetOperator->getUuid();
            $this->createdOperators[] = $targetUuid;

            $manager = $this->createLimitedOperator('auto_assign_manager', operator: true);

            $manager->setAutoAssign($targetUuid, true);
            $this->assertTrue(
                $this->client->getOperator($targetUuid)->isAutoAssigned(),
                'Operator with operator management permissions should be able to enable auto assign'
            );

            $manager->setAutoAssign($targetUuid, false);
            $this->assertFalse(
                $this->client->getOperator($targetUuid)->isAutoAssigned(),
                'Operator with operator management permissions should be able to disable auto assign'
            );
        }

        public function testAutoAssignClientRejectsEmptyUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->setAutoAssign('', true);
        }

        public function testAutoAssignInvalidUuidRejectedByServer(): void
        {
            [$code] = $this->rawRequest(
                'PATCH',
                'operators/not-a-uuid/auto-assign',
                getenv('SERVER_ACCESS_TOKEN'),
                json_encode(['enabled' => true])
            );
            $this->assertContains(
                $code,
                [HttpResponseCode::BAD_REQUEST->value],
                'Invalid operator UUID should be rejected with 400'
            );
        }

        public function testAutoAssignNonexistentOperatorReturns404(): void
        {
            [$code] = $this->rawRequest(
                'PATCH',
                'operators/00000000-0000-0000-0000-000000000000/auto-assign',
                getenv('SERVER_ACCESS_TOKEN'),
                json_encode(['enabled' => true])
            );
            $this->assertEquals(
                HttpResponseCode::NOT_FOUND->value,
                $code,
                'Non-existent operator should return 404'
            );
        }

        public function testAutoAssignMissingEnabledParameterRejected(): void
        {
            $createdOperator = $this->client->createOperator(substr('auto_assign_missing_' . uniqid(), 0, 32));
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            [$code] = $this->rawRequest(
                'PATCH',
                'operators/' . $operatorUuid . '/auto-assign',
                getenv('SERVER_ACCESS_TOKEN'),
                json_encode([])
            );
            $this->assertEquals(
                HttpResponseCode::BAD_REQUEST->value,
                $code,
                'Missing enabled parameter should return 400'
            );
        }

        public function testAutoAssignSystemOperatorCannotBeModified(): void
        {
            $systemOperator = $this->findSystemOperator();

            if ($systemOperator === null)
            {
                $this->markTestSkipped('System operator not present in this environment');
            }

            $attacker = $this->createLimitedOperator('auto_assign_system', operator: true);

            $this->expectRequestFailure(
                fn() => $attacker->setAutoAssign($systemOperator->getUuid(), true),
                [HttpResponseCode::FORBIDDEN->value],
                'System operator should be protected from auto assign modifications'
            );
        }

        public function testSubmitReportAssignsEligibleAutoAssignOperator(): void
        {
            $submitter = $this->createLimitedOperator('auto_assign_submitter', client: true);
            $assignee = $this->createLimitedOperator('auto_assign_assignee', management: true);
            $assigneeUuid = $assignee->getSelf()->getUuid();
            $this->client->setAutoAssign($assigneeUuid, true);

            $entityUuid = $this->createSecurityEntity($submitter);
            $submission = $submitter->submitReport(
                $entityUuid,
                ['text_content' => 'Report submitted by a client operator'],
                IncidentType::SPAM
            );
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()[0]->getUuid();

            $this->assertEquals(
                $assigneeUuid,
                $this->client->getReport($reportUuid)->getAssignedOperator(),
                'Reports submitted by operators must retain the eligible auto-assign operator'
            );
        }

        /**
         * Returns the built-in system operator record, or null if it is not present.
         */
        private function findSystemOperator(): ?OperatorRecord
        {
            foreach ($this->client->listOperators(1, 1000) as $operator)
            {
                if ($operator->getName() === 'system')
                {
                    return $operator;
                }
            }

            return null;
        }

        public function testScanContentAssignsGeneratedReportToAutoAssignOperator(): void
        {
            $systemOperator = $this->findSystemOperator();
            if ($systemOperator === null)
            {
                $this->markTestSkipped('System operator not present in this environment');
            }

            $autoAssignOperator = $this->createLimitedOperator('auto_assign_report', management: true);
            $this->client->setAutoAssign($autoAssignOperator->getSelf()->getUuid(), true);

            $entityUuid = $this->client->pushEntity('auto-assign-report.com', 'auto_assign_report_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence for auto assign report', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $reportsBefore = $this->client->listReports(1, 1000);
            $reportsBeforeUuids = array_map(fn($report) => $report->getUuid(), $reportsBefore);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertGreaterThanOrEqual(80.0, $scanned->getRiskScore(), 'Scanned content should exceed the auto report threshold');

            $generatedReport = null;
            foreach ($this->client->listReports(1, 1000) as $report)
            {
                if (in_array($report->getUuid(), $reportsBeforeUuids, true))
                {
                    continue;
                }

                if ($report->isAutomated() && str_starts_with((string)$report->getMessage(), 'Automated Report'))
                {
                    $generatedReport = $report;
                    break;
                }
            }

            $this->assertNotNull($generatedReport, 'Scanning high risk content should generate an automated report when an auto assign operator exists');
            $this->createdReports[] = $generatedReport->getUuid();

            $this->assertEquals($systemOperator->getUuid(), $generatedReport->getSubmittingOperator(), 'Generated report should be submitted by the system operator');
            $this->assertTrue($generatedReport->isAutomated(), 'Generated report should be marked as automated');
            $this->assertEquals($autoAssignOperator->getSelf()->getUuid(), $generatedReport->getAssignedOperator(), 'Generated report should be assigned to the auto assign operator');

            // Clean up the evidence the system attached to the generated report
            foreach ($this->client->listEvidence(1, 1000) as $evidence)
            {
                if ($evidence->getReport() === $generatedReport->getUuid())
                {
                    $this->createdEvidenceRecords[] = $evidence->getUuid();
                }
            }
        }

        public function testScanContentDoesNotGenerateReportWithoutAutoAssignOperators(): void
        {
            // The mechanism can only be tested if no operator is currently eligible for automatic assignment
            foreach ($this->client->listOperators(1, 1000) as $operator)
            {
                if ($operator->isAutoAssigned() && $operator->hasManagementPermissions())
                {
                    $this->markTestSkipped('An eligible auto assign operator already exists in this environment');
                }
            }

            $entityUuid = $this->client->pushEntity('auto-assign-missing.com', 'auto_assign_missing_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Malware evidence without auto assign', 'Test note', 'malware');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::MALWARE, null);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $reportsBefore = $this->client->listReports(1, 1000);
            $reportsBeforeUuids = array_map(fn($report) => $report->getUuid(), $reportsBefore);

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertGreaterThanOrEqual(80.0, $scanned->getRiskScore(), 'Scanned content should exceed the auto report threshold');

            foreach ($this->client->listReports(1, 1000) as $report)
            {
                if (in_array($report->getUuid(), $reportsBeforeUuids, true))
                {
                    continue;
                }

                $this->assertFalse(
                    $report->isAutomated(),
                    'No automated report should be generated when no auto assign operator exists'
                );
            }
        }
    }