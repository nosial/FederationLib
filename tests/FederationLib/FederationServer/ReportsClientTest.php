<?php

    namespace FederationLib\FederationServer;

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

    class ReportsClientTest extends TestCase
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

        public function testSubmitReport(): void
        {
            $entityUuid = $this->client->pushEntity('test-report.com', 'test_user');
            $this->createdEntities[] = $entityUuid;

            $content = TextGenerator::generate(ClassificationFlag::NORMAL);
            $reportMessage = "Normal content";
            $submission = $this->client->submitReport($entityUuid, $content, IncidentType::SPAM, $reportMessage);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->assertNotNull($submission->getReport());
            $this->assertNotNull($submission->getEvidence());
            $this->assertEquals($entityUuid, $submission->getReport()->getReportingEntity());
            $this->assertEquals($reportMessage, $submission->getReport()->getMessage());
            $this->assertNotEmpty($submission->getReport()->getUuid());
        }

        public function testSubmitReportInvalidContent(): void
        {
            $entityUuid = $this->client->pushEntity('invalid-content.com', 'invalid_user');
            $this->createdEntities[] = $entityUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Content cannot be empty');
            $this->client->submitReport($entityUuid, '', IncidentType::SPAM);
        }

        public function testSubmitReportInvalidEntity(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->submitReport('', 'content', IncidentType::OTHER);
        }

        public function testSubmitReportWithEvidenceTag(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-tag-report.com', 'tag_user');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report with evidence tag', IncidentType::SPAM, null, 'initial-tag');
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->assertNotNull($submission->getEvidence()->getTag());
            $this->assertEquals('initial-tag', $submission->getEvidence()->getTag());
        }

        public function testListReports(): void
        {
            $entityUuid = $this->client->pushEntity('list-reports.com', 'list_user');
            $this->createdEntities[] = $entityUuid;

            $reportUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $submission = $this->client->submitReport($entityUuid, "List report $i", IncidentType::OTHER);
                $uuid = $submission->getReport()->getUuid();
                $reportUuids[] = $uuid;
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            }

            $reports = $this->client->listReports(1, 10);
            $this->assertGreaterThanOrEqual(3, count($reports));
            foreach ($reports as $report)
            {
                $this->assertInstanceOf(ReportRecord::class, $report);
            }

            $foundUuids = array_map(fn($report) => $report->getUuid(), $reports);
            foreach ($reportUuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }

            $openedReports = $this->client->listReports(1, 10, 'OPENED');
            $openedUuids = array_map(fn($r) => $r->getUuid(), $openedReports);
            foreach ($reportUuids as $uuid)
            {
                $this->assertContains($uuid, $openedUuids);
            }

            $closedReports = $this->client->listReports(1, 10, 'CLOSED');
            $closedUuids = array_map(fn($r) => $r->getUuid(), $closedReports);
            foreach ($reportUuids as $uuid)
            {
                $this->assertNotContains($uuid, $closedUuids);
            }
        }

        public function testListReportsInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listReports(1, 0);
        }

        public function testGetReport(): void
        {
            $entityUuid = $this->client->pushEntity('get-report.com', 'get_user');
            $this->createdEntities[] = $entityUuid;

            $reportMessage = 'Get Report';
            $submission = $this->client->submitReport($entityUuid, 'Report to get', IncidentType::SPAM, $reportMessage);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $report = $this->client->getReport($reportUuid);
            $this->assertNotNull($report);
            $this->assertEquals($reportUuid, $report->getUuid());
            $this->assertEquals($reportMessage, $report->getMessage());
            $this->assertEquals($entityUuid, $report->getReportingEntity());
            $this->assertGreaterThan(0, $report->getCreated());
        }

        public function testGetReportEmptyUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->getReport('');
        }

        public function testCloseReport(): void
        {
            $entityUuid = $this->client->pushEntity('close-report.com', 'close_user');
            $this->createdEntities[] = $entityUuid;

            $content = TextGenerator::generate(ClassificationFlag::NORMAL);
            $submission = $this->client->submitReport($entityUuid, $content, IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->client->closeReport($reportUuid);
            $report = $this->client->getReport($reportUuid);
            $this->assertFalse($report->isOpened());
        }

        public function testCloseReportWithClassification(): void
        {
            $entityUuid = $this->client->pushEntity('close-classify.com', 'close_classify');
            $this->createdEntities[] = $entityUuid;

            $content = TextGenerator::generate(ClassificationFlag::MALICIOUS);
            $submission = $this->client->submitReport($entityUuid, $content, IncidentType::OTHER);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->client->closeReport($reportUuid, ClassificationFlag::MALICIOUS);
            $report = $this->client->getReport($reportUuid);
            $this->assertFalse($report->isOpened());
        }

        public function testCloseNonExistentReport(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->closeReport('00000000-0000-0000-0000-000000000000');
        }

        public function testDeleteReport(): void
        {
            $entityUuid = $this->client->pushEntity('delete-report.com', 'delete_user');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report to delete', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->client->deleteReport($reportUuid);

            try
            {
                $this->client->getReport($reportUuid);
                $this->fail('Expected RequestException for deleted report');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(HttpResponseCode::NOT_FOUND->value, $e->getCode());
            }

            $index = array_search($reportUuid, $this->createdReports);
            if ($index !== false)
            {
                array_splice($this->createdReports, $index, 1);
            }
        }

        public function testDeleteNonExistentReport(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteReport('00000000-0000-0000-0000-000000000000');
        }

        public function testListOperatorReports(): void
        {
            $entityUuid = $this->client->pushEntity('list-op-reports.com', 'list_op_user');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Operator report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $reports = $this->client->listOperatorReports($submission->getReport()->getSubmittingOperator(), 1, 10);
            $this->assertIsArray($reports);

            $foundUuids = array_map(fn($report) => $report->getUuid(), $reports);
            $this->assertContains($reportUuid, $foundUuids);

            $openedReports = $this->client->listOperatorReports($submission->getReport()->getSubmittingOperator(), 1, 10, 'OPENED');
            $openedUuids = array_map(fn($r) => $r->getUuid(), $openedReports);
            $this->assertContains($reportUuid, $openedUuids);

            $closedReports = $this->client->listOperatorReports($submission->getReport()->getSubmittingOperator(), 1, 10, 'CLOSED');
            $closedUuids = array_map(fn($r) => $r->getUuid(), $closedReports);
            $this->assertNotContains($reportUuid, $closedUuids);
        }

        public function testListOperatorReportsInvalidPage(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listOperatorReports('00000000-0000-0000-0000-000000000000', 0, 10);
        }

        public function testListEntityReports(): void
        {
            $entityUuid = $this->client->pushEntity('list-entity-reports.com', 'list_entity');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Entity report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $reports = $this->client->listEntityReports($entityUuid, 1, 10);
            $this->assertIsArray($reports);

            $foundUuids = array_map(fn($report) => $report->getUuid(), $reports);
            $this->assertContains($reportUuid, $foundUuids);

            $openedReports = $this->client->listEntityReports($entityUuid, 1, 10, 'OPENED');
            $openedUuids = array_map(fn($r) => $r->getUuid(), $openedReports);
            $this->assertContains($reportUuid, $openedUuids);

            $closedReports = $this->client->listEntityReports($entityUuid, 1, 10, 'CLOSED');
            $closedUuids = array_map(fn($r) => $r->getUuid(), $closedReports);
            $this->assertNotContains($reportUuid, $closedUuids);
        }

        public function testGetNonExistentReport(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getReport('00000000-0000-0000-0000-000000000000');
        }

        public function testSubmitReportWithAllOptionalParams(): void
        {
            $entityUuid = $this->client->pushEntity('full-params.com', 'full_params');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Full params report', IncidentType::SPAM, 'Report message', 'evidence-tag');
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->assertNotNull($submission->getReport());
            $this->assertNotNull($submission->getEvidence());
            $this->assertEquals('evidence-tag', $submission->getEvidence()->getTag());
        }

        public function testListReportsPageExhaustion(): void
        {
            $entityUuid = $this->client->pushEntity('page-exhaust.com', 'page_exhaust');
            $this->createdEntities[] = $entityUuid;

            $reportUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $submission = $this->client->submitReport($entityUuid, "Page exhaust report $i", IncidentType::OTHER);
                $uuid = $submission->getReport()->getUuid();
                $reportUuids[] = $uuid;
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            }

            $allReportUuids = [];
            $page = 1;
            do
            {
                $reports = $this->client->listReports($page, 2);
                foreach ($reports as $report)
                {
                    $this->assertInstanceOf(ReportRecord::class, $report);
                    $this->assertNotEmpty($report->getUuid());
                    $allReportUuids[] = $report->getUuid();
                }
                $page++;
            } while (count($reports) > 0);

            foreach ($reportUuids as $uuid)
            {
                $this->assertContains($uuid, $allReportUuids);
            }
        }

        public function testListReportsSortByCreatedDescending(): void
        {
            $entityUuid = $this->client->pushEntity('reports-sort-desc.com', 'reports_desc_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $reportUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $submission = $this->client->submitReport($entityUuid, "Report sort DESC $i", IncidentType::OTHER);
                $uuid = $submission->getReport()->getUuid();
                $reportUuids[] = $uuid;
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            }

            $reports = $this->client->listReports(1, 100, null, 'created', 'DESC');
            $filtered = array_values(array_filter($reports, fn($r) => in_array($r->getUuid(), $reportUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals($reportUuids[2], $filtered[0]->getUuid());
            $this->assertEquals($reportUuids[1], $filtered[1]->getUuid());
            $this->assertEquals($reportUuids[0], $filtered[2]->getUuid());
        }

        public function testListReportsSortByIncidentTypeAscending(): void
        {
            $entityUuid = $this->client->pushEntity('reports-sort-type.com', 'reports_type_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $types = [IncidentType::MALWARE, IncidentType::PHISHING, IncidentType::SPAM];
            $expectedOrder = ['SPAM', 'MALWARE', 'PHISHING'];
            $reportUuids = [];

            foreach ($types as $type)
            {
                $submission = $this->client->submitReport($entityUuid, "Report type $type->value", $type);
                $uuid = $submission->getReport()->getUuid();
                $reportUuids[] = $uuid;
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            }

            $allFiltered = [];
            $page = 1;
            do {
                $reports = $this->client->listReports($page, 100, null, 'incident_type', 'ASC');
                if (empty($reports)) break;
                $newFiltered = array_values(array_filter($reports, fn($r) => in_array($r->getUuid(), $reportUuids, true)));
                $allFiltered = array_merge($allFiltered, $newFiltered);
                $page++;
            } while (count($allFiltered) < 3);

            $this->assertCount(3, $allFiltered);
            $this->assertEquals($expectedOrder[0], $allFiltered[0]->getIncidentType()->value);
            $this->assertEquals($expectedOrder[1], $allFiltered[1]->getIncidentType()->value);
            $this->assertEquals($expectedOrder[2], $allFiltered[2]->getIncidentType()->value);
        }

        public function testBulkReportSubmissionConsistency(): void
        {
            $entityUuid = $this->client->pushEntity('bulk-report.com', 'bulk_user');
            $this->createdEntities[] = $entityUuid;

            $submissions = [];
            for ($i = 0; $i < 5; $i++)
            {
                $submission = $this->client->submitReport($entityUuid, "Bulk report $i", IncidentType::OTHER);
                $uuid = $submission->getReport()->getUuid();
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
                $submissions[] = $submission;
            }

            $this->assertCount(5, $submissions);
            foreach ($submissions as $submission)
            {
                $this->assertNotNull($submission->getReport());
                $this->assertNotNull($submission->getEvidence());
            }
        }

        public function testHighVolumeReportOperations(): void
        {
            $entityUuid = $this->client->pushEntity('high-volume.com', 'high_volume');
            $this->createdEntities[] = $entityUuid;

            $reportUuids = [];
            for ($i = 0; $i < 10; $i++)
            {
                $submission = $this->client->submitReport($entityUuid, "High volume report $i", IncidentType::SPAM);
                $uuid = $submission->getReport()->getUuid();
                $reportUuids[] = $uuid;
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            }

            $this->assertCount(10, $reportUuids);
            foreach ($reportUuids as $uuid)
            {
                $report = $this->client->getReport($uuid);
                $this->assertNotNull($report);
                $this->assertEquals($uuid, $report->getUuid());
            }
        }

        public function testReportConsistencyAfterMultipleActions(): void
        {
            $entityUuid = $this->client->pushEntity('consistency.com', 'consistency_user');
            $this->createdEntities[] = $entityUuid;

            $content = TextGenerator::generate(ClassificationFlag::SUSPICIOUS);
            $submission = $this->client->submitReport($entityUuid, $content, IncidentType::OTHER);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->client->closeReport($reportUuid, ClassificationFlag::SUSPICIOUS);

            $report = $this->client->getReport($reportUuid);
            $this->assertFalse($report->isOpened());
        }

        public function testBatchReportClassificationWithGeneratedContent(): void
        {
            $entityUuid = $this->client->pushEntity('batch-classify.com', 'batch_classify_user');
            $this->createdEntities[] = $entityUuid;

            $samples = TextGenerator::generateBatch(perClass: 5, minWords: 6, maxWords: 18);

            foreach ($samples as $sample)
            {
                $submission = $this->client->submitReport($entityUuid, $sample['text'], IncidentType::OTHER);
                $reportUuid = $submission->getReport()->getUuid();
                $this->createdReports[] = $reportUuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

                $this->client->closeReport($reportUuid, $sample['flag']);

                $report = $this->client->getReport($reportUuid);
                $this->assertFalse($report->isOpened(), 'Report should be closed after classification');
            }
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

        public function testReportFullLifecycleWorkflow(): void
        {
            $submitter = $this->createLimitedOperator('lifecycle_submitter', client: true);
            $manager = $this->createLimitedOperator('lifecycle_manager', management: true, operator: true);
            $managerUuid = $manager->getSelf()->getUuid();

            $entityUuid = $this->createSecurityEntity($submitter);
            $submission = $submitter->submitReport($entityUuid, 'Full lifecycle report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $submitterUuid = $submitter->getSelf()->getUuid();
            $report = $this->client->getReport($reportUuid);
            $this->assertTrue($report->isOpened());
            $this->assertEquals($submitterUuid, $report->getAssignedOperator(), 'Report should be auto-assigned to the submitter');

            $manager->assignOperatorToReport($reportUuid, $managerUuid);
            $assignedReport = $this->client->getReport($reportUuid);
            $this->assertEquals($managerUuid, $assignedReport->getAssignedOperator());

            $standaloneEvidenceUuid = $this->createSecurityEvidence($entityUuid, false, $submitter);
            $manager->addEvidenceToReport($standaloneEvidenceUuid, $reportUuid);
            $linkedEvidence = $this->client->getEvidenceRecord($standaloneEvidenceUuid);
            $this->assertEquals($reportUuid, $linkedEvidence->getReport());

            $manager->closeReport($reportUuid, ClassificationFlag::SUSPICIOUS);
            $closedReport = $this->client->getReport($reportUuid);
            $this->assertFalse($closedReport->isOpened());

            $this->expectRequestFailure(
                fn() => $manager->closeReport($reportUuid),
                [HttpResponseCode::BAD_REQUEST->value],
                'Closing an already-closed report should fail'
            );

            $this->client->deleteReport($reportUuid);
            array_splice($this->createdReports, array_search($reportUuid, $this->createdReports), 1);

            $this->expectRequestFailure(
                fn() => $this->client->getReport($reportUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Deleted report should not be retrievable'
            );
        }

        public function testCloseReportWithAutoBlacklist(): void
        {
            $manager = $this->createLimitedOperator('close_blacklist_manager', management: true);
            $report = $this->createSecurityReport();

            $manager->assignOperatorToReport($report['report'], $manager->getSelf()->getUuid());

            $expires = time() + 7200;
            [$code, $response] = $this->rawRequest(
                'PATCH',
                'reports/' . $report['report'] . '/close',
                $manager->getAccessToken(),
                json_encode([
                    'classification_flag' => ClassificationFlag::MALICIOUS->value,
                    'blacklist_incident_type' => IncidentType::SPAM->value,
                    'blacklist_expires' => $expires,
                ])
            );

            $this->assertEquals(HttpResponseCode::OK->value, $code, 'Close with blacklist should succeed: ' . $response);

            $closedReport = $this->client->getReport($report['report']);
            $this->assertFalse($closedReport->isOpened());

            $entityBlacklist = $this->client->listEntityBlacklistRecords($report['entity'], 1, 100, true);
            $this->assertNotEmpty($entityBlacklist);

            $foundBlacklist = null;
            foreach ($entityBlacklist as $blacklist)
            {
                if ($blacklist->getType() === IncidentType::SPAM && !$blacklist->isLifted())
                {
                    $foundBlacklist = $blacklist;
                    break;
                }
            }
            $this->assertNotNull($foundBlacklist, 'Active SPAM blacklist should be created on close');
            $this->assertEquals($expires, $foundBlacklist->getExpires());
            $this->createdBlacklistRecords[] = $foundBlacklist->getUuid();
        }

        public function testCloseReportWithInvalidBlacklistIncidentType(): void
        {
            $manager = $this->createLimitedOperator('close_invalid_bl_manager', management: true);
            $report = $this->createSecurityReport();

            $manager->assignOperatorToReport($report['report'], $manager->getSelf()->getUuid());

            [$code] = $this->rawRequest(
                'PATCH',
                'reports/' . $report['report'] . '/close',
                $manager->getAccessToken(),
                json_encode([
                    'classification_flag' => ClassificationFlag::MALICIOUS->value,
                    'blacklist_incident_type' => 'NOT_A_REAL_TYPE',
                ])
            );

            $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value], 'Invalid blacklist incident type should be rejected');
        }

        public function testCloseReportWithExpiredBlacklistTimestamp(): void
        {
            $manager = $this->createLimitedOperator('close_expired_bl_manager', management: true);
            $report = $this->createSecurityReport();

            $manager->assignOperatorToReport($report['report'], $manager->getSelf()->getUuid());

            [$code] = $this->rawRequest(
                'PATCH',
                'reports/' . $report['report'] . '/close',
                $manager->getAccessToken(),
                json_encode([
                    'classification_flag' => ClassificationFlag::MALICIOUS->value,
                    'blacklist_incident_type' => IncidentType::SPAM->value,
                    'blacklist_expires' => time() - 3600,
                ])
            );

            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code, 'Blacklist expiration in the past should be rejected');
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

        public function testReportSubmitWithAttachment(): void
        {
            $entityUuid = $this->client->pushEntity('report-attachment.com', 'report_attach_user');
            $this->createdEntities[] = $entityUuid;

            $testFilePath = tempnam(sys_get_temp_dir(), 'report_attach_') . '.txt';
            file_put_contents($testFilePath, 'Report attachment content');
            $this->tempFiles[] = $testFilePath;

            $submission = $this->client->submitReport($entityUuid, 'Report with attachment', IncidentType::SPAM, null, 'report_attach');
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $attachments = $this->client->getEvidenceAttachments($evidenceUuid);
            $this->assertCount(1, $attachments);
            $this->assertEquals($uploadResult->getUuid(), $attachments[0]->getUuid());
        }

        public function testReportCloseWithBlacklistAndTraining(): void
        {
            $manager = $this->createLimitedOperator('close_train_manager', management: true);
            $submission = $this->createSecurityReport();

            $manager->assignOperatorToReport($submission['report'], $manager->getSelf()->getUuid());
            $manager->closeReport($submission['report'], ClassificationFlag::MALICIOUS);

            $report = $this->client->getReport($submission['report']);
            $this->assertFalse($report->isOpened());
        }

        public function testReportAssignmentTransferBetweenManagers(): void
        {
            $firstManager = $this->createLimitedOperator('first_transfer_manager', management: true);
            $secondManager = $this->createLimitedOperator('second_transfer_manager', management: true);
            $report = $this->createSecurityReport();

            $firstManager->assignOperatorToReport($report['report'], $firstManager->getSelf()->getUuid());
            $assignedReport = $this->client->getReport($report['report']);
            $this->assertEquals($firstManager->getSelf()->getUuid(), $assignedReport->getAssignedOperator());

            $secondManager->assignOperatorToReport($report['report'], $secondManager->getSelf()->getUuid());
            $reassignedReport = $this->client->getReport($report['report']);
            $this->assertEquals($secondManager->getSelf()->getUuid(), $reassignedReport->getAssignedOperator());
        }

        public function testReportListFiltersByReportingEntity(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();

            $submissionA = $this->client->submitReport($entityA, 'Report for entity A', IncidentType::SPAM);
            $reportAUuid = $submissionA->getReport()->getUuid();
            $this->createdReports[] = $reportAUuid;
            $this->createdEvidenceRecords[] = $submissionA->getEvidence()->getUuid();

            $submissionB = $this->client->submitReport($entityB, 'Report for entity B', IncidentType::SCAM);
            $reportBUuid = $submissionB->getReport()->getUuid();
            $this->createdReports[] = $reportBUuid;
            $this->createdEvidenceRecords[] = $submissionB->getEvidence()->getUuid();

            $entityAReports = $this->client->listEntityReports($entityA);
            $entityAReportUuids = array_map(fn($r) => $r->getUuid(), $entityAReports);
            $this->assertContains($reportAUuid, $entityAReportUuids);
            $this->assertNotContains($reportBUuid, $entityAReportUuids);
        }

        public function testListReportsCategoryOpened(): void
        {
            $entityUuid = $this->client->pushEntity('rep-cat-open.com', 'rep_open_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Opened report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $reports = $this->client->listReports(1, 100, 'OPENED');
            $foundUuids = array_map(fn($r) => $r->getUuid(), $reports);
            $this->assertContains($reportUuid, $foundUuids);
        }

        public function testListReportsCategoryClosed(): void
        {
            $entityUuid = $this->client->pushEntity('rep-cat-closed.com', 'rep_closed_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Closed report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $this->client->closeReport($reportUuid);

            $reports = $this->client->listReports(1, 100, 'CLOSED');
            $foundUuids = array_map(fn($r) => $r->getUuid(), $reports);
            $this->assertContains($reportUuid, $foundUuids);
        }

        public function testListReportsCategoryAssigned(): void
        {
            $manager = $this->createLimitedOperator('rep_cat_asgn_mgr', management: true);
            $entityUuid = $this->client->pushEntity('rep-cat-asgn.com', 'rep_asgn_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Assigned report', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $manager->assignOperatorToReport($reportUuid, $manager->getSelf()->getUuid());

            $reports = $this->client->listReports(1, 100, 'ASSIGNED');
            $foundUuids = array_map(fn($r) => $r->getUuid(), $reports);
            $this->assertContains($reportUuid, $foundUuids);
        }

        public function testListReportsCategoryWithSort(): void
        {
            $entityUuid = $this->client->pushEntity('rep-cat-sort.com', 'rep_cat_sort_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $reportUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $submission = $this->client->submitReport($entityUuid, "Report cat sort $i", IncidentType::OTHER);
                $uuid = $submission->getReport()->getUuid();
                $reportUuids[] = $uuid;
                $this->createdReports[] = $uuid;
                $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();
            }

            $reports = $this->client->listReports(1, 100, 'OPENED', 'created', 'DESC');
            $filtered = array_values(array_filter($reports, fn($r) => in_array($r->getUuid(), $reportUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals($reportUuids[2], $filtered[0]->getUuid());
            $this->assertEquals($reportUuids[1], $filtered[1]->getUuid());
            $this->assertEquals($reportUuids[0], $filtered[2]->getUuid());
        }

        public function testListReportsCategoryInvalidFallsBack(): void
        {
            $entityUuid = $this->client->pushEntity('rep-cat-inv.com', 'rep_cat_inv_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Cat invalid test', IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $resultDefault = $this->client->listReports(1, 10);
            $resultInvalid = $this->client->listReports(1, 10, 'BOGUS_CATEGORY');

            $defaultUuids = array_map(fn($r) => $r->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($r) => $r->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListReportsCategoryCaseInsensitive(): void
        {
            $entityUuid = $this->client->pushEntity('rep-cat-ci.com', 'rep_ci_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'CI category test', IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $resultUpper = $this->client->listReports(1, 10, 'OPENED');
            $resultLower = $this->client->listReports(1, 10, 'opened');
            $resultMixed = $this->client->listReports(1, 10, 'Opened');

            $upperUuids = array_map(fn($r) => $r->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($r) => $r->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($r) => $r->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
        }

        public function testReportDeleteCascadesToLinkedEvidence(): void
        {
            $manager = $this->createLimitedOperator('delete_report_manager', management: true, operator: true);
            $report = $this->createSecurityReport();

            $extraEvidenceUuid = $this->createSecurityEvidence($report['entity']);
            $manager->addEvidenceToReport($extraEvidenceUuid, $report['report']);

            $this->client->deleteReport($report['report']);
            array_splice($this->createdReports, array_search($report['report'], $this->createdReports), 1);

            $this->expectRequestFailure(
                fn() => $this->client->getReport($report['report']),
                [HttpResponseCode::NOT_FOUND->value],
                'Deleted report should not be retrievable'
            );

            // Evidence linked to the report may be cascade-deleted or unlinked depending on FK rules.
            try
            {
                $this->client->getEvidenceRecord($extraEvidenceUuid);
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [HttpResponseCode::NOT_FOUND->value, HttpResponseCode::FORBIDDEN->value]);
            }
        }
    }
