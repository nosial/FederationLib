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

    class ReportsLogicTest extends TestCase
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
