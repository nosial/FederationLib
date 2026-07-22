<?php

    namespace FederationLib\Tests\Reports;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TextGenerator;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ReportsSecurityTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdReports = [];
        private array $createdEvidenceRecords = [];
        private array $createdEntities = [];
        private array $createdOperators = [];
        private array $createdBlacklistRecords = [];
        private array $createdAttachments = [];
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

            foreach ($this->createdAttachments as $attachmentUuid)
            {
                try
                {
                    $this->client->deleteAttachment($attachmentUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete attachment $attachmentUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete evidence $evidenceUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdReports = [];
            $this->createdEvidenceRecords = [];
            $this->createdEntities = [];
            $this->createdOperators = [];
            $this->createdBlacklistRecords = [];
            $this->createdAttachments = [];
            $this->tempFiles = [];
        }

        public function testSecurityReportAssignmentAndClosureEdgeCases(): void
        {
            $clientOperator = $this->createLimitedOperator('report_submitter', client: true);
            $firstManager = $this->createLimitedOperator('first_manager', management: true);
            $secondManager = $this->createLimitedOperator('second_manager', management: true);

            $report = $this->createSecurityReport($clientOperator);

            // A management operator who is not assigned cannot close the report.
            $this->expectRequestFailure(
                fn() => $secondManager->closeReport($report['report']),
                [HttpResponseCode::BAD_REQUEST->value],
                'Non-assigned manager should not be able to close a report'
            );

            // Reassign the report and close it.
            $secondManager->assignOperatorToReport($report['report'], $secondManager->getSelf()->getUuid());
            $secondManager->closeReport($report['report']);
            $closedReport = $this->client->getReport($report['report']);
            $this->assertFalse($closedReport->isOpened());

            // Closing an already-closed report must fail.
            $this->expectRequestFailure(
                fn() => $secondManager->closeReport($report['report']),
                [HttpResponseCode::BAD_REQUEST->value],
                'Closing an already-closed report should fail'
            );

            // Assigning a disabled operator must fail.
            $disabledManager = $this->createLimitedOperator('disabled_manager', management: true);
            $disabledManagerUuid = $disabledManager->getSelf()->getUuid();
            $this->client->disableOperator($disabledManagerUuid);

            $newReport = $this->createSecurityReport($clientOperator);
            $this->expectRequestFailure(
                fn() => $firstManager->assignOperatorToReport($newReport['report'], $disabledManagerUuid),
                [HttpResponseCode::BAD_REQUEST->value],
                'Assigning a disabled operator should fail'
            );

            // Assigning an operator without management permissions must fail.
            $plainClient = $this->createLimitedOperator('plain_client', client: true);
            $this->expectRequestFailure(
                fn() => $firstManager->assignOperatorToReport($newReport['report'], $plainClient->getSelf()->getUuid()),
                [HttpResponseCode::FORBIDDEN->value],
                'Assigning an operator without management permissions should fail'
            );
        }

        public function testSecurityAddEvidenceToReportRequiresOperatorPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $report = $this->createSecurityReport();

            $clientOnly = $this->createLimitedOperator('add_evidence_client', client: true);
            $operatorOnly = $this->createLimitedOperator('add_evidence_operator', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->addEvidenceToReport($evidenceUuid, $report['report']),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not link evidence to report'
            );

            $operatorOnly->addEvidenceToReport($evidenceUuid, $report['report']);
            $updatedEvidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($report['report'], $updatedEvidence->getReport());
        }

        public function testSecurityListAssignedOperatorReportsAccess(): void
        {
            $manager = $this->createLimitedOperator('assigned_reports_manager', management: true);
            $report = $this->createSecurityReport();
            $manager->assignOperatorToReport($report['report'], $manager->getSelf()->getUuid());

            $assignedReports = $this->client->listAssignedOperatorReports($manager->getSelf()->getUuid());
            $foundUuids = array_map(fn($r) => $r->getUuid(), $assignedReports);
            $this->assertContains($report['report'], $foundUuids);

            $openedAssigned = $this->client->listAssignedOperatorReports($manager->getSelf()->getUuid(), 1, 100, 'OPENED');
            $openedUuids = array_map(fn($r) => $r->getUuid(), $openedAssigned);
            $this->assertContains($report['report'], $openedUuids);

            $closedAssigned = $this->client->listAssignedOperatorReports($manager->getSelf()->getUuid(), 1, 100, 'CLOSED');
            $closedUuids = array_map(fn($r) => $r->getUuid(), $closedAssigned);
            $this->assertNotContains($report['report'], $closedUuids);
        }

        public function testCloseReportWithoutAssignmentFails(): void
        {
            $manager = $this->createLimitedOperator('close_unassigned_manager', management: true);
            $report = $this->createSecurityReport();

            $this->expectRequestFailure(
                fn() => $manager->closeReport($report['report']),
                [HttpResponseCode::BAD_REQUEST->value],
                'Closing an unassigned report should fail'
            );
        }
    }
