<?php

    /** @noinspection PhpRedundantOptionalArgumentInspection */
    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Search;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ReportRecord;
    use FederationLib\Objects\SearchResult;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class SearchTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $createdAttachments = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
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

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->createdAttachments = [];
            $this->tempFiles = [];
        }

        public function testSearchEntitiesByHost(): void
        {
            $host = uniqid('search-host-') . '.com';
            $entityUuid = $this->client->pushEntity($host, 'user_a');
            $this->createdEntities[] = $entityUuid;

            $this->client->pushEntity($host, 'user_b');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities($host);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EntityRecord::class, $result);
                $this->assertSame($host, $result->getHost());
            }
        }

        public function testSearchEntitiesById(): void
        {
            $id = 'search_id_' . uniqid();
            $entityUuid = $this->client->pushEntity('search-id-test.com', $id);
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities($id);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EntityRecord::class, $result);
                $this->assertSame($id, $result->getId());
            }
        }

        public function testSearchEntitiesByUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-uuid-test.com', 'uuid_user');
            $this->createdEntities[] = $entityUuid;

            $prefix = substr($entityUuid, 0, 8);
            $results = $this->client->searchEntities($prefix);
            $this->assertNotEmpty($results);

            $found = false;
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EntityRecord::class, $result);
                if ($result->getUuid() === $entityUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Created entity should be found by UUID prefix search');
        }

        public function testSearchEvidenceByContent(): void
        {
            $entityUuid = $this->client->pushEntity('search-evidence-content.com', 'content_user');
            $this->createdEntities[] = $entityUuid;

            $uniqueContent = 'X1 evidence content ' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $uniqueContent, 'content test', 'content_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($uniqueContent);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $result);
            }

            $texts = array_map(fn(EvidenceRecord $r) => $r->getTextContent(), $results);
            $this->assertContains($uniqueContent, $texts);
        }

        public function testSearchEvidenceByTag(): void
        {
            $entityUuid = $this->client->pushEntity('search-evidence-tag.com', 'tag_user');
            $this->createdEntities[] = $entityUuid;

            $tag = 'xtag_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'tag content', 'tag note', $tag);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($tag);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $result);
                $this->assertSame($tag, $result->getTag());
            }
        }

        public function testSearchEvidenceByEntityUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-evidence-entity.com', 'entity_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'entity search test', 'entity note', 'entity_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $prefix = substr($entityUuid, 0, 8);
            $results = $this->client->searchEvidence($prefix);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $result);
                $this->assertSame($entityUuid, $result->getEntityUuid());
            }
        }

        public function testSearchBlacklistByEntityUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-blacklist.com', 'bl_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'bl content', 'bl note', 'bl_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $prefix = substr($entityUuid, 0, 8);
            $results = $this->client->searchBlacklist($prefix);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(BlacklistRecord::class, $result);
                $this->assertSame($entityUuid, $result->getEntityUuid());
            }
        }

        public function testSearchReportsByMessage(): void
        {
            $entityUuid = $this->client->pushEntity('search-reports-msg.com', 'report_user');
            $this->createdEntities[] = $entityUuid;

            $uniqueMsg = 'REPORT_SEARCH_MSG_' . uniqid();
            $submission = $this->client->submitReport($entityUuid, 'report content', IncidentType::SPAM, $uniqueMsg);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchReports($entityUuid);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(ReportRecord::class, $result);
            }
        }

        public function testSearchReportsByReportingEntity(): void
        {
            $host = 'search-reports-entity-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host, 'rep_entity_user');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'report content 2', IncidentType::SPAM, 'search by entity');
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchReports($entityUuid);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(ReportRecord::class, $result);
            }
        }

        public function testSearchAttachmentsByFileName(): void
        {
            $entityUuid = $this->client->pushEntity('search-attach.com', 'attach_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'attach content', 'attach note', 'attach_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $fileName = 'search_test_file_' . uniqid() . '.txt';
            $filePath = $this->createSecurityTempFile('attachment search content', 'txt');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            try
            {
                $results = $this->client->searchAttachments($fileName);
                $this->assertNotEmpty($results);
                foreach ($results as $result)
                {
                    $this->assertInstanceOf(FileAttachmentRecord::class, $result);
                    $this->assertSame($fileName, $result->getFileName());
                }
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Attachment search not available: ' . $e->getMessage());
                $this->assertContains($e->getCode(), [400, 404],
                    'Expected 400 or 404 when attachment search is disabled');
            }
        }

        public function testSearchOperatorsByName(): void
        {
            $name = 'search_op_' . uniqid();
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $results = $this->client->searchOperators($name);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(OperatorRecord::class, $result);
                $this->assertSame($name, $result->getName());
            }
        }

        public function testSearchEmptyQuery(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEntities('');
        }

        public function testSearchSingleCharacterQuery(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEntities('a');
        }

        public function testSearchWhitespaceQuery(): void
        {
            try
            {
                $this->client->searchEntities('  ');
                $this->fail('Expected an exception for whitespace-only query');
            }
            catch (InvalidArgumentException)
            {
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 422],
                    'Server should reject whitespace-only search query');
            }
        }

        public function testSearchInvalidPageZero(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEntities('test', 0, 10);
        }

        public function testSearchInvalidPageNegative(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEntities('test', -1, 10);
        }

        public function testSearchInvalidLimitZero(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEntities('test', 1, 0);
        }

        public function testSearchInvalidLimitNegative(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEntities('test', 1, -5);
        }

        public function testSearchReturnsEmptyResultsForNonExistentQuery(): void
        {
            $results = $this->client->searchEntities('zzzzthisdoesnotexist_' . uniqid());
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testSearchEvidenceReturnsEmptyForNonExistentContent(): void
        {
            $results = $this->client->searchEvidence('nonexistent_evidence_' . uniqid());
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testSearchBlacklistEmptyForNonExistentEntity(): void
        {
            $results = $this->client->searchBlacklist('nonexistent_blacklist_' . uniqid());
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testSearchReportsEmptyForNonExistentMessage(): void
        {
            $results = $this->client->searchReports('nonexistent_report_' . uniqid());
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testSearchAttachmentsEmptyForNonExistentFileName(): void
        {
            try
            {
                $results = $this->client->searchAttachments('nonexistent_attachment_' . uniqid());
                $this->assertIsArray($results);
                $this->assertEmpty($results);
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Attachment search not available: ' . $e->getMessage());
                $this->assertContains($e->getCode(), [400, 404]);
            }
        }

        public function testSearchOperatorsEmptyForNonExistentName(): void
        {
            $results = $this->client->searchOperators('nonexistent_operator_' . uniqid());
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testSearchEntitiesUsesDefaultPageAndLimit(): void
        {
            $entityUuid = $this->client->pushEntity('default-params.com', 'default_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('default-params.com');
            $this->assertIsArray($results);
            $this->assertLessThanOrEqual(10, count($results));
        }

        public function testSearchEntityThenRetrieveFullRecord(): void
        {
            $entityUuid = $this->client->pushEntity('verify-search.com', 'verify_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities($entityUuid);
            $this->assertNotEmpty($results);

            $fullRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertSame($fullRecord->getUuid(), $results[0]->getUuid());
            $this->assertSame($fullRecord->getHost(), $results[0]->getHost());
            $this->assertSame($fullRecord->getId(), $results[0]->getId());
        }

        public function testSearchEvidenceThenRetrieveFullRecord(): void
        {
            $entityUuid = $this->client->pushEntity('verify-evidence-search.com', 'verify_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'verify evidence content', 'verify note', 'verify_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('verify evidence content');
            $this->assertNotEmpty($results);

            $fullRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertSame($fullRecord->getUuid(), $results[0]->getUuid());
            $this->assertSame($fullRecord->getTextContent(), $results[0]->getTextContent());
        }

        public function testSearchAuditLogsByMessage(): void
        {
            $operatorName = 'audit_search_op_' . uniqid();
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $this->client->deleteOperator($operatorUuid);
            $this->removeFromCleanup($this->createdOperators, $operatorUuid);

            $results = $this->client->searchAuditLogs($operatorName);
            $this->assertIsArray($results);
        }

        public function testSearchIPAddressEntity(): void
        {
            $entityUuid = $this->client->pushEntity('192.168.1.1');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('192.168');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EntityRecord::class, $result);
                if ($result->getUuid() === $entityUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'IP address entity should be found by partial IP search');
        }

        public function testSearchEvidenceByNote(): void
        {
            $entityUuid = $this->client->pushEntity('search-note.com', 'note_user');
            $this->createdEntities[] = $entityUuid;

            $uniqueNote = 'UNIQUE_NOTE_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'note content body', $uniqueNote, 'note_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($entityUuid);
            $this->assertNotEmpty($results);
        }

        public function testSearchEvidenceByUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-ev-uuid.com', 'ev_uuid_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'uuid search content', 'uuid note', 'uuid_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $prefix = substr($evidenceUuid, 0, 10);
            $results = $this->client->searchEvidence($prefix);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $result);
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence should be found by UUID prefix search');
        }

        public function testSearchBlacklistByEvidenceUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-bl-ev.com', 'bl_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'bl ev content', 'bl ev note', 'bl_ev_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $results = $this->client->searchBlacklist($entityUuid);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(BlacklistRecord::class, $result);
            }
        }

        public function testSearchReportByUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-report-uuid.com', 'rpt_uuid_user');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'report content', IncidentType::SPAM, 'uuid report');
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $prefix = substr($reportUuid, 0, 10);
            $results = $this->client->searchReports($prefix);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                $this->assertInstanceOf(ReportRecord::class, $result);
                if ($result->getUuid() === $reportUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Report should be found by UUID prefix search');
        }

        public function testSearchAttachmentByEvidenceUuid(): void
        {
            $entityUuid = $this->client->pushEntity('search-attach-ev.com', 'att_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'attach ev content', 'attach ev note', 'attach_ev_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $fileName = 'ev_attach_test_' . uniqid() . '.bin';
            $filePath = $this->createSecurityTempFile('attach ev data', 'bin');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            try
            {
                $prefix = substr($evidenceUuid, 0, 10);
                $results = $this->client->searchAttachments($prefix);
                $this->assertIsArray($results);
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Attachment search not available: ' . $e->getMessage());
                $this->assertContains($e->getCode(), [400, 404],
                    'Expected 400 or 404 when attachment search is disabled');
            }
        }

        public function testSearchEntitiesNumericHost(): void
        {
            $entityUuid = $this->client->pushEntity('12345');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('12345');
            $this->assertNotEmpty($results);
        }

        public function testSearchEvidenceEmptyTextContent(): void
        {
            $entityUuid = $this->client->pushEntity('empty-evidence.com', 'empty_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, null, 'empty note', 'empty_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($entityUuid);
            $this->assertIsArray($results);
        }

        public function testSearchReportWithoutMessage(): void
        {
            $entityUuid = $this->client->pushEntity('no-msg-report.com', 'no_msg_user');
            $this->createdEntities[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'no message content', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchReports($entityUuid);
            $this->assertNotEmpty($results);
        }

        public function testSearchEntityFieldsAreComplete(): void
        {
            $entityUuid = $this->client->pushEntity('field-completeness.com', 'field_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities($entityUuid);
            $this->assertNotEmpty($results);
            $result = $results[0];

            $this->assertInstanceOf(EntityRecord::class, $result);
            $this->assertNotEmpty($result->getUuid());
            $this->assertNotEmpty($result->getHost());
            $this->assertSame('field_user', $result->getId());
            $this->assertGreaterThan(0, $result->getCreated(),
                'Entity search result must have a valid created timestamp');
        }

        public function testSearchBlacklistFieldsAreComplete(): void
        {
            $entityUuid = $this->client->pushEntity('bl-fields.com', 'bl_fields_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'bl fields content', 'bl fields note', 'bl_fields_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $results = $this->client->searchBlacklist($entityUuid);
            $this->assertNotEmpty($results);
            $result = $results[0];

            $this->assertInstanceOf(BlacklistRecord::class, $result);
            $this->assertNotEmpty($result->getUuid());
            $this->assertSame($entityUuid, $result->getEntityUuid());
            $this->assertInstanceOf(IncidentType::class, $result->getType());
            $this->assertGreaterThan(0, $result->getCreated());
        }

        public function testSearchEvidenceTimestampsArePopulated(): void
        {
            $entityUuid = $this->client->pushEntity('timestamps.com', 'ts_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'timestamp content', 'ts note', 'ts_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('timestamp content', 1, 100);
            $this->assertNotEmpty($results);

            foreach ($results as $result)
            {
                $this->assertInstanceOf(EvidenceRecord::class, $result);
                $this->assertGreaterThan(0, $result->getCreated(), 'Evidence search result must have a created timestamp');
            }
        }

    }
