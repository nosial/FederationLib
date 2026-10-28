<?php

    namespace FederationLib\Tests\Reports;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ReportRecord;

    class ReportsSecurityTest extends ServerTestCase
    {
        public function testOnlyTheAssignedOperatorClosesAReport(): void
        {
            $clientOperator = $this->createLimitedOperator('report_submitter', client: true);
            $firstManager = $this->createLimitedOperator('first_manager', management: true);
            $secondManager = $this->createLimitedOperator('second_manager', management: true);
            $report = $this->createSecurityReport($clientOperator);

            // A manager that is not assigned the report cannot close it, nor can the submitting client
            $this->expectRequestFailure(fn() => $secondManager->closeReport($report['report']), [HttpResponseCode::BAD_REQUEST->value], 'A manager not assigned the report should not close it');
            $this->expectRequestFailure(fn() => $clientOperator->closeReport($report['report']), [HttpResponseCode::FORBIDDEN->value], 'A client should not close a report');

            $firstManager->assignOperatorToReport($report['report'], $firstManager->getSelf()->getUuid());
            $this->expectRequestFailure(fn() => $secondManager->closeReport($report['report']), [HttpResponseCode::BAD_REQUEST->value], 'A manager no longer assigned the report should not close it');
            $this->assertTrue($this->client->getReport($report['report'])->isOpened());

            $firstManager->closeReport($report['report']);
            $this->assertFalse($this->client->getReport($report['report'])->isOpened());
        }

        public function testReportsAreOnlyAssignedToEnabledManagers(): void
        {
            $manager = $this->createLimitedOperator('assigning_manager', management: true);
            $report = $this->createSecurityReport();

            $disabledManagerUuid = $this->createLimitedOperator('disabled_manager', management: true)->getSelf()->getUuid();
            $this->client->disableOperator($disabledManagerUuid);
            $this->expectRequestFailure(
                fn() => $manager->assignOperatorToReport($report['report'], $disabledManagerUuid),
                [HttpResponseCode::BAD_REQUEST->value],
                'Assigning a disabled operator should fail'
            );

            $plainClientUuid = $this->createLimitedOperator('plain_client', client: true)->getSelf()->getUuid();
            $this->expectRequestFailure(
                fn() => $manager->assignOperatorToReport($report['report'], $plainClientUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Assigning an operator without management permissions should fail'
            );

            $this->assertNull($this->client->getReport($report['report'])->getAssignedOperator());
        }

        public function testLinkingEvidenceToReportRequiresManagementPermissions(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $report = $this->createSecurityReport();
            $link = fn(FederationClient $client) => fn() => $client->addEvidenceToReport($evidenceUuid, $report['report']);

            $this->expectRequestFailure($link(new FederationClient(getenv('SERVER_ENDPOINT'))), [HttpResponseCode::UNAUTHORIZED->value], 'An anonymous client should not link evidence to a report');
            $this->expectRequestFailure($link($this->createLimitedOperator('add_evidence_client', client: true)), [HttpResponseCode::FORBIDDEN->value], 'A client should not link evidence to a report');
            $this->expectRequestFailure($link($this->createLimitedOperator('add_evidence_operator', operator: true)), [HttpResponseCode::FORBIDDEN->value], 'An operator without management permissions should not link evidence to a report');
            $this->assertNull($this->client->getEvidenceRecord($evidenceUuid)->getReport());

            $link($this->createLimitedOperator('add_evidence_manager', management: true))();
            $this->assertEquals($report['report'], $this->client->getEvidenceRecord($evidenceUuid)->getReport());
        }

        public function testListAssignedOperatorReports(): void
        {
            $manager = $this->createLimitedOperator('assigned_reports_manager', management: true);
            $managerUuid = $manager->getSelf()->getUuid();
            $opened = $this->createSecurityReport();
            $closed = $this->createSecurityReport();
            $unassigned = $this->createSecurityReport();

            $manager->assignOperatorToReport($opened['report'], $managerUuid);
            $manager->assignOperatorToReport($closed['report'], $managerUuid);
            $manager->closeReport($closed['report']);

            $uuids = fn(array $reports) => array_map(fn(ReportRecord $report) => $report->getUuid(), $reports);
            $this->assertEqualsCanonicalizing([$opened['report'], $closed['report']], $uuids($this->client->listAssignedOperatorReports($managerUuid)));
            $this->assertSame([$opened['report']], $uuids($this->client->listAssignedOperatorReports($managerUuid, 1, 100, 'OPENED')));
            $this->assertSame([$closed['report']], $uuids($this->client->listAssignedOperatorReports($managerUuid, 1, 100, 'CLOSED')));
            $this->assertNotContains($unassigned['report'], $uuids($this->client->listAssignedOperatorReports($managerUuid)));
        }
    }
