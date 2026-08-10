<?php

    namespace FederationLib\Tests\Reports;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\EvidenceRecord;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ReportsEvidenceTest extends TestCase
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

        public function testGetReportEvidenceRecordsReturnsLinkedEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-list.com', 'rep_ev_list_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence list', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $manager = $this->createLimitedOperator('rep_ev_mgr', management: true);
            $extraEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Extra linked evidence', 'Note', 'rep_ev_extra');
            $this->createdEvidenceRecords[] = $extraEvidenceUuid;
            $manager->addEvidenceToReport($extraEvidenceUuid, $reportUuid);

            $records = $this->client->listReportEvidenceRecords($reportUuid);
            $this->assertNotEmpty($records);

            $foundUuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertContains($submission->getEvidence()->getUuid(), $foundUuids);
            $this->assertContains($extraEvidenceUuid, $foundUuids);

            foreach ($records as $record)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $record);
                $this->assertEquals($reportUuid, $record->getReport());
            }
        }

        public function testGetReportEvidenceRecordsExcludesUnlinkedEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-exclude.com', 'rep_ev_excl_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence exclude', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $unlinkedEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Unlinked evidence', 'Note', 'rep_ev_unlinked');
            $this->createdEvidenceRecords[] = $unlinkedEvidenceUuid;

            $records = $this->client->listReportEvidenceRecords($reportUuid);
            $foundUuids = array_map(fn($r) => $r->getUuid(), $records);
            $this->assertNotContains($unlinkedEvidenceUuid, $foundUuids);
        }

        public function testGetReportEvidenceRecordsReturnsEmptyArray(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-empty.com', 'rep_ev_empty_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence empty', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->deleteEvidence($evidenceUuid);
            $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid);

            $records = $this->client->listReportEvidenceRecords($reportUuid);
            $this->assertIsArray($records);
            $this->assertEmpty($records);
        }

        public function testGetReportEvidenceRecordsPagination(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-page.com', 'rep_ev_pg_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence pagination', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $manager = $this->createLimitedOperator('rep_ev_pg_mgr', management: true);
            $evidenceUuids = [$submission->getEvidence()->getUuid()];
            for ($i = 0; $i < 4; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Report evidence page $i", 'Note', 'rep_ev_page');
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
                $manager->addEvidenceToReport($evidenceUuid, $reportUuid);
            }

            $allReportUuids = [];
            $page = 1;
            do
            {
                $records = $this->client->listReportEvidenceRecords($reportUuid, $page, 2);
                foreach ($records as $record)
                {
                    $this->assertInstanceOf(EvidenceRecord::class, $record);
                    $this->assertNotEmpty($record->getUuid());
                    $allReportUuids[] = $record->getUuid();
                }
                $page++;
            } while (count($records) > 0);

            foreach ($evidenceUuids as $uuid)
            {
                $this->assertContains($uuid, $allReportUuids);
            }
        }

        public function testGetReportEvidenceRecordsInvalidUuid(): void
        {
            $token = $this->client->getAccessToken();
            [$code, $body] = $this->rawRequest('GET', 'reports/1b4e28ba2f1c4d6e8a0f3c5d7e9b1a2c3d4e/evidence', $token);
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code, $body);
        }

        public function testGetReportEvidenceRecordsNonExistentReport(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->listReportEvidenceRecords('00000000-0000-0000-0000-000000000000');
        }

        public function testGetReportEvidenceRecordsInvalidPageAndLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listReportEvidenceRecords('00000000-0000-0000-0000-000000000000', 0, 10);
        }

        public function testGetReportEvidenceRecordsConfidentialRequiresManagementPermission(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-conf.com', 'rep_ev_conf_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence confidential', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $manager = $this->createLimitedOperator('rep_ev_conf_mgr', management: true);
            $confidentialUuid = $this->client->submitEvidence($entityUuid, 'Confidential report evidence', 'Note', 'rep_ev_conf', true);
            $this->createdEvidenceRecords[] = $confidentialUuid;
            $manager->addEvidenceToReport($confidentialUuid, $reportUuid);

            $clientOnly = $this->createLimitedOperator('rep_ev_conf_client', client: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->listReportEvidenceRecords($reportUuid, 1, 100, true),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not include confidential evidence'
            );

            $publicRecords = $clientOnly->listReportEvidenceRecords($reportUuid);
            $publicUuids = array_map(fn($r) => $r->getUuid(), $publicRecords);
            $this->assertNotContains($confidentialUuid, $publicUuids);

            $fullRecords = $manager->listReportEvidenceRecords($reportUuid, 1, 100, true);
            $fullUuids = array_map(fn($r) => $r->getUuid(), $fullRecords);
            $this->assertContains($confidentialUuid, $fullUuids);
        }

        public function testGetReportEvidenceRecordsCategoryFilter(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-cat.com', 'rep_ev_cat_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence category', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $manager = $this->createLimitedOperator('rep_ev_cat_mgr', management: true);
            $confidentialUuid = $this->client->submitEvidence($entityUuid, 'Confidential category evidence', 'Note', 'rep_ev_cat_conf', true);
            $this->createdEvidenceRecords[] = $confidentialUuid;
            $manager->addEvidenceToReport($confidentialUuid, $reportUuid);

            $nonConfidentialRecords = $this->client->listReportEvidenceRecords($reportUuid, 1, 100, false, 'NOT_CONFIDENTIAL');
            $nonConfidentialUuids = array_map(fn($r) => $r->getUuid(), $nonConfidentialRecords);
            $this->assertNotContains($confidentialUuid, $nonConfidentialUuids);
            $this->assertContains($submission->getEvidence()->getUuid(), $nonConfidentialUuids);

            $confidentialRecords = $manager->listReportEvidenceRecords($reportUuid, 1, 100, true, 'CONFIDENTIAL');
            $confidentialUuids = array_map(fn($r) => $r->getUuid(), $confidentialRecords);
            $this->assertContains($confidentialUuid, $confidentialUuids);
            $this->assertNotContains($submission->getEvidence()->getUuid(), $confidentialUuids);
        }

        public function testGetReportEvidenceRecordsSortByCreatedAscending(): void
        {
            $entityUuid = $this->client->pushEntity('rep-evidence-sort.com', 'rep_ev_sort_' . uniqid());
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report evidence sort', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $manager = $this->createLimitedOperator('rep_ev_sort_mgr', management: true);
            $evidenceUuids = [$submission->getEvidence()->getUuid()];
            for ($i = 0; $i < 2; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Report evidence sort $i", 'Note', 'rep_ev_sort');
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
                $manager->addEvidenceToReport($evidenceUuid, $reportUuid);
            }

            $records = $this->client->listReportEvidenceRecords($reportUuid, 1, 100, false, null, 'created', 'ASC');
            $filtered = array_values(array_filter($records, fn($r) => in_array($r->getUuid(), $evidenceUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals($evidenceUuids[0], $filtered[0]->getUuid());
            $this->assertEquals($evidenceUuids[1], $filtered[1]->getUuid());
            $this->assertEquals($evidenceUuids[2], $filtered[2]->getUuid());
        }
    }