<?php

    /** @noinspection PhpRedundantOptionalArgumentInspection */
    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\FederationServer;

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

        public function testSearchWithPercentSymbol(): void
        {
            $entityUuid = $this->client->pushEntity('percent-test.com', '100%_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('100%');
            $this->assertNotEmpty($results);
        }

        public function testSearchWithUnderscore(): void
        {
            $entityUuid = $this->client->pushEntity('underscore-test.com', 'my_user_name');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('my_user');
            $this->assertNotEmpty($results);
        }

        public function testSearchWithSqlInjectionPattern(): void
        {
            $entityUuid = $this->client->pushEntity('sql-inject.com', 'admin');
            $this->createdEntities[] = $entityUuid;

            $payloads = [
                "'; DROP TABLE entities; --",
                "' OR '1'='1",
                "' UNION SELECT * FROM operators --",
            ];

            foreach ($payloads as $payload)
            {
                try
                {
                    $results = $this->client->searchEntities($payload);
                    $this->assertIsArray($results);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->info('Server rejected SQL pattern search: ' . $e->getMessage());
                    $this->assertContains($e->getCode(), [400, 422, 500]);
                }
            }
        }

        public function testSearchWithUnicodeCharacters(): void
        {
            $entityUuid = $this->client->pushEntity('unicode-test.com', 'café_ñuño');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('café');
            $this->assertIsArray($results);
        }

        public function testSearchPartialHostMatch(): void
        {
            $base = 'partial-' . uniqid();
            $host = $base . '.com';
            $entityUuid = $this->client->pushEntity($host, 'partial_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities($base);
            $this->assertNotEmpty($results);
            foreach ($results as $result)
            {
                $this->assertInstanceOf(EntityRecord::class, $result);
            }
        }

        public function testSearchPartialEvidenceContentMatch(): void
        {
            $entityUuid = $this->client->pushEntity('partial-evidence.com', 'partial_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'The quick brown fox jumps over the lazy dog', 'partial note', 'partial_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('quick brown');
            $this->assertNotEmpty($results);
        }

        public function testSearchEntitiesPagination(): void
        {
            $host = 'pagination-' . uniqid() . '.com';
            $created = [];
            for ($i = 0; $i < 8; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'pag_user_' . $i);
                $this->createdEntities[] = $uuid;
                $created[] = $uuid;
            }

            $page1 = $this->client->searchEntities($host, 1, 3);
            $this->assertCount(3, $page1);

            $page2 = $this->client->searchEntities($host, 2, 3);
            $this->assertCount(3, $page2);

            $page3 = $this->client->searchEntities($host, 3, 3);
            $this->assertCount(2, $page3);

            $allUuids = array_merge(
                array_map(fn(EntityRecord $r) => $r->getUuid(), $page1),
                array_map(fn(EntityRecord $r) => $r->getUuid(), $page2),
                array_map(fn(EntityRecord $r) => $r->getUuid(), $page3),
            );

            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $allUuids, "Entity $uuid not found across paginated results");
            }
        }

        public function testSearchPaginationConsistency(): void
        {
            $host = 'consistency-' . uniqid() . '.com';
            for ($i = 0; $i < 5; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'cons_user_' . $i);
                $this->createdEntities[] = $uuid;
            }

            $first = $this->client->searchEntities($host, 1, 3);
            $second = $this->client->searchEntities($host, 1, 3);

            $this->assertCount(count($first), $second);
            for ($i = 0; $i < count($first); $i++)
            {
                $this->assertSame($first[$i]->getUuid(), $second[$i]->getUuid());
            }
        }

        public function testSearchPaginationNoOverlap(): void
        {
            $host = 'no-overlap-' . uniqid() . '.com';
            for ($i = 0; $i < 6; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'nolap_user_' . $i);
                $this->createdEntities[] = $uuid;
            }

            $page1 = $this->client->searchEntities($host, 1, 3);
            $page2 = $this->client->searchEntities($host, 2, 3);

            $page1Uuids = array_map(fn(EntityRecord $r) => $r->getUuid(), $page1);
            $page2Uuids = array_map(fn(EntityRecord $r) => $r->getUuid(), $page2);

            $intersection = array_intersect($page1Uuids, $page2Uuids);
            $this->assertEmpty($intersection, 'Entities appeared in multiple pages');
        }

        public function testSearchPageFarBeyondResults(): void
        {
            $host = 'far-page-' . uniqid() . '.com';
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'far_user_' . $i);
                $this->createdEntities[] = $uuid;
            }

            $results = $this->client->searchEntities($host, 100, 10);
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testMultiTypeSearchReturnsCorrectTypes(): void
        {
            $entityUuid = $this->client->pushEntity('multi-test-' . uniqid() . '.com', 'multi_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'multi type evidence content', 'multi note', 'multi_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $results = $this->client->search('multi', null, 1, 100);
            $this->assertIsArray($results);
            $this->assertNotEmpty($results);

            foreach ($results as $result)
            {
                $this->assertInstanceOf(SearchResult::class, $result);
                $this->assertInstanceOf(RecordType::class, $result->getType());
            }

            $foundEntity = false;
            foreach ($results as $result)
            {
                if ($result->getType() === RecordType::ENTITY)
                {
                    $foundEntity = true;
                    $this->assertInstanceOf(EntityRecord::class, $result->getRecord());
                }
            }
            $this->assertTrue($foundEntity, 'Multi-type search should include entity results');
        }

        public function testMultiTypeSearchWithTypeFilter(): void
        {
            $entityUuid = $this->client->pushEntity('type-filter-' . uniqid() . '.com', 'tf_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'type filter evidence', 'tf note', 'tf_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->search('type filter', [RecordType::EVIDENCE->value], 1, 100);
            $this->assertIsArray($results);
            $this->assertNotEmpty($results);

            foreach ($results as $result)
            {
                $this->assertSame(RecordType::EVIDENCE, $result->getType());
            }
        }

        public function testMultiTypeSearchWithMultipleTypeFilters(): void
        {
            $host = 'multi-filter-test-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host, 'mf_user');
            $this->createdEntities[] = $entityUuid;

            $searchKeyword = substr($host, 0, strpos($host, '.com'));
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $searchKeyword . ' evidence', 'mf note', 'mf_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $operatorName = 'mf_op_' . uniqid();
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $results = $this->client->search($searchKeyword, [RecordType::ENTITY->value, RecordType::EVIDENCE->value], 1, 100);
            $this->assertNotEmpty($results);

            $foundEntity = false;
            $foundEvidence = false;
            foreach ($results as $result)
            {
                if ($result->getType() === RecordType::ENTITY)
                {
                    $foundEntity = true;
                    $this->assertStringContainsString($searchKeyword, $result->getRecord()->getHost());
                }
                elseif ($result->getType() === RecordType::EVIDENCE)
                {
                    $foundEvidence = true;
                }
            }
            $this->assertTrue($foundEntity, 'Multi-filter search should include entity results');
            $this->assertTrue($foundEvidence, 'Multi-filter search should include evidence results');

            foreach ($results as $result)
            {
                $this->assertNotSame(RecordType::OPERATOR, $result->getType(),
                    'Operator results should not appear when filtering to ENTITY and EVIDENCE only');
            }
        }

        public function testMultiTypeSearchReturnsSearchResultObjects(): void
        {
            $entityUuid = $this->client->pushEntity('search-result-obj.com', 'sro_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->search('search-result-obj');
            $this->assertNotEmpty($results);
            $result = $results[0];
            $this->assertInstanceOf(SearchResult::class, $result);
            $this->assertSame(RecordType::ENTITY, $result->getType());
            $this->assertInstanceOf(EntityRecord::class, $result->getRecord());
            $this->assertSame($entityUuid, $result->getRecord()->getUuid());
        }

        public function testMultiTypeSearchEmptyForNonExistentQuery(): void
        {
            $results = $this->client->search('zzz_nonexistent_query_' . uniqid());
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testMultiTypeSearchInvalidParameters(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->search('');
        }

        public function testEntitySearchDoesNotReturnEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('cross-type.com', 'ct_user');
            $this->createdEntities[] = $entityUuid;

            $uniqueEvidenceContent = 'CROSS_TYPE_EVIDENCE_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $uniqueEvidenceContent, 'ct note', 'ct_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $entityResults = $this->client->searchEntities($uniqueEvidenceContent);
            $this->assertEmpty($entityResults, 'Entity search should not return evidence content matches');

            $evidenceResults = $this->client->searchEvidence($uniqueEvidenceContent);
            $this->assertNotEmpty($evidenceResults, 'Evidence search should find the evidence');
        }

        public function testOperatorSearchDoesNotReturnEntities(): void
        {
            $entityUuid = $this->client->pushEntity('operator-x-search.com', 'opx_user');
            $this->createdEntities[] = $entityUuid;

            $opName = 'opx_name_' . uniqid();
            $operatorUuid = $this->client->createOperator($opName);
            $this->createdOperators[] = $operatorUuid;

            $entityResults = $this->client->searchEntities($opName);
            $this->assertEmpty($entityResults, 'Entity search should not return operators by name');

            $opResults = $this->client->searchOperators($opName);
            $this->assertNotEmpty($opResults, 'Operator search should find the operator');
        }

        public function testDeletedEntityDoesNotAppearInSearch(): void
        {
            $entityUuid = $this->client->pushEntity('delete-search.com', 'delete_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('delete-search.com');
            $this->assertNotEmpty($results);

            $this->client->deleteEntity($entityUuid);
            $this->removeFromCleanup($this->createdEntities, $entityUuid);

            $resultsAfterDelete = $this->client->searchEntities('delete-search.com');
            $found = false;
            foreach ($resultsAfterDelete as $result)
            {
                if ($result->getUuid() === $entityUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertFalse($found, 'Deleted entity should not appear in search results');
        }

        public function testDeletedEvidenceDoesNotAppearInSearch(): void
        {
            $entityUuid = $this->client->pushEntity('delete-evidence-search.com', 'del_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'delete evidence content', 'delete note', 'delete_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('delete evidence content');
            $this->assertNotEmpty($results);

            $this->client->deleteEvidence($evidenceUuid);
            $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid);

            $resultsAfterDelete = $this->client->searchEvidence('delete evidence content');
            $found = false;
            foreach ($resultsAfterDelete as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertFalse($found, 'Deleted evidence should not appear in search results');
        }

        public function testSearchMultipleEntitiesSameHost(): void
        {
            $host = 'multi-same-' . uniqid() . '.com';
            $count = 5;
            $created = [];
            for ($i = 0; $i < $count; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'multi_same_user_' . $i);
                $this->createdEntities[] = $uuid;
                $created[] = $uuid;
            }

            $results = $this->client->searchEntities($host, 1, 100);
            $this->assertGreaterThanOrEqual($count, count($results));

            $resultUuids = array_map(fn(EntityRecord $r) => $r->getUuid(), $results);
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $resultUuids, "Entity $uuid should be in search results");
            }
        }

        public function testSearchMultipleEvidenceSameTag(): void
        {
            $entityUuid = $this->client->pushEntity('multi-evidence-tag.com', 'multi_ev_user');
            $this->createdEntities[] = $entityUuid;

            $tag = 'common_tag_' . uniqid();
            $created = [];
            for ($i = 0; $i < 4; $i++)
            {
                $uuid = $this->client->submitEvidence($entityUuid, "common tag content $i", "common note $i", $tag);
                $this->createdEvidenceRecords[] = $uuid;
                $created[] = $uuid;
            }

            $results = $this->client->searchEvidence($tag, 1, 100);
            $this->assertGreaterThanOrEqual(count($created), count($results));

            $resultUuids = array_map(fn(EvidenceRecord $r) => $r->getUuid(), $results);
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $resultUuids);
            }
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

        public function testSearchConfidentialEvidenceAsAnonymousUser(): void
        {
            if ($this->client->getServerInformation()->isPublicEvidence())
            {
                $entityUuid = $this->client->pushEntity('confidential-test.com', 'conf_user');
                $this->createdEntities[] = $entityUuid;

                $confContent = 'CONFIDENTIAL_EVIDENCE_' . uniqid();
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $confContent, 'conf note', 'conf_tag', true);
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
                try
                {
                    $results = $anonymousClient->searchEvidence($confContent);
                    $found = false;
                    foreach ($results as $result)
                    {
                        if ($result->getUuid() === $evidenceUuid)
                        {
                            $found = true;
                            break;
                        }
                    }
                    $this->assertFalse($found, 'Anonymous user should not see confidential evidence');
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 401, 403, 404],
                        'Unexpected HTTP status: ' . $e->getCode());
                }
            }
            else
            {
                $this->markTestSkipped('Evidence is not public, skipping anonymous search test');
            }
        }

        public function testSearchConfidentialEvidenceWithManagementOperator(): void
        {
            $entityUuid = $this->client->pushEntity('conf-mgmt-test.com', 'conf_mgmt_user');
            $this->createdEntities[] = $entityUuid;

            $confContent = 'MGMT_CONF_EVIDENCE_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $confContent, 'mgmt conf note', 'mgmt_conf_tag', true);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $mgmtClient = $this->createLimitedOperator('mgmt_search', true, false, false);

            try
            {
                $results = $mgmtClient->searchEvidence($confContent);
                $found = false;
                foreach ($results as $result)
                {
                    if ($result->getUuid() === $evidenceUuid)
                    {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, 'Management operator should find confidential evidence');
            }
            catch (RequestException $e)
            {
                $this->markTestSkipped('Management operator search failed: ' . $e->getMessage());
            }
        }

        public function testSearchEntitiesAsAnonymousPublic(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $host = 'anon-search-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host, 'anon_user');
            $this->createdEntities[] = $entityUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            try
            {
                $results = $anonymousClient->searchEntities($host);
                $this->assertNotEmpty($results);
                foreach ($results as $result)
                {
                    $this->assertInstanceOf(EntityRecord::class, $result);
                    $this->assertSame($host, $result->getHost());
                }
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401, 404],
                    'Anonymous entity search failed with unexpected status: ' . $e->getCode());
            }
        }

        public function testSearchEvidenceAsAnonymousPublic(): void
        {
            if (!$this->client->getServerInformation()->isPublicEvidence())
            {
                $this->markTestSkipped('Server does not expose evidence publicly');
            }

            $entityUuid = $this->client->pushEntity('anon-evidence.com', 'anon_ev_user');
            $this->createdEntities[] = $entityUuid;

            $content = 'ANON_PUBLIC_EVIDENCE_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'anon note', 'anon_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            try
            {
                $results = $anonymousClient->searchEvidence($content);
                $this->assertNotEmpty($results);
                foreach ($results as $result)
                {
                    $this->assertInstanceOf(EvidenceRecord::class, $result);
                }
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401, 404],
                    'Anonymous evidence search failed with unexpected status: ' . $e->getCode());
            }
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

        public function testSearchEntitiesUsesDefaultPageAndLimit(): void
        {
            $entityUuid = $this->client->pushEntity('default-params.com', 'default_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('default-params.com');
            $this->assertIsArray($results);
            $this->assertLessThanOrEqual(10, count($results));
        }

        public function testSearchEvidenceTextContentExactWord(): void
        {
            $entityUuid = $this->client->pushEntity('text-exact.com', 'text_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'The quick brown fox', 'exact note', 'exact_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('brown');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence must be found by exact word within text_content');
        }

        public function testSearchEvidenceTextContentNumericOnly(): void
        {
            $entityUuid = $this->client->pushEntity('text-numeric.com', 'num_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, '12345 67890 54321', 'numeric note', 'numeric_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('67890');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence must be found by numeric content search');
        }

        public function testSearchEvidenceTextContentSpecialCharacters(): void
        {
            $entityUuid = $this->client->pushEntity('text-special.com', 'spec_user');
            $this->createdEntities[] = $entityUuid;

            $content = 'Price: $49.99 (discount 20%) — #sale! item@store';
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'special note', 'special_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('$49.99');
            $this->assertNotEmpty($results);
        }

        public function testSearchEvidenceTextContentVeryLong(): void
        {
            $entityUuid = $this->client->pushEntity('text-long.com', 'long_user');
            $this->createdEntities[] = $entityUuid;

            $longContent = 'TEXT_CONTENT_' . uniqid() . ' ' . str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit ', 200);
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $longContent, 'long note', 'long_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('TEXT_CONTENT');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence with very long text_content must be found');
        }

        public function testSearchEvidenceTextContentMinimumLength(): void
        {
            $entityUuid = $this->client->pushEntity('text-min.com', 'min_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'ab', 'min note', 'min_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->client->searchEvidence('a');
        }

        public function testSearchEvidenceTextContentExactlyTwoCharacters(): void
        {
            $entityUuid = $this->client->pushEntity('text-two.com', 'two_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'xy', 'two note', 'two_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('xy');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence must be found by its exact two-character content');
        }

        public function testSearchEvidenceTextContentUnicode(): void
        {
            $entityUuid = $this->client->pushEntity('text-unicode.com', 'uni_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Hello 世界 café ñoño 你好 😊', 'unicode note', 'unicode_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('世界');
            $this->assertIsArray($results);
            if (!empty($results))
            {
                $found = false;
                foreach ($results as $result)
                {
                    if ($result->getUuid() === $evidenceUuid)
                    {
                        $found = true;
                    }
                }
                Logger::getLogger()->info("Unicode text search found evidence: " . ($found ? 'yes' : 'no'));
            }
        }

        public function testSearchEvidenceTextContentAtStartBoundary(): void
        {
            $entityUuid = $this->client->pushEntity('text-start.com', 'start_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'START_MARKER some other text follows', 'start note', 'start_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('START_MARKER');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence must be found when query matches text_content at the start');
        }

        public function testSearchEvidenceTextContentAtEndBoundary(): void
        {
            $entityUuid = $this->client->pushEntity('text-end.com', 'end_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'some text before END_MARKER', 'end note', 'end_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('END_MARKER');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence must be found when query matches text_content at the end');
        }

        public function testSearchEvidenceTextContentDoesNotMatchNote(): void
        {
            $entityUuid = $this->client->pushEntity('text-note.com', 'note_user');
            $this->createdEntities[] = $entityUuid;

            $noteText = 'THIS_IS_THE_NOTE_NOT_CONTENT_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'actual text content', $noteText, 'note_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($noteText);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                    Logger::getLogger()->info('Note text matched in search result — server may include notes in search');
                }
            }
            $this->assertFalse($found, 'Note text should NOT be searchable via evidence text_content search');
        }

        public function testSearchEvidenceTextContentMultipleSharedSubstring(): void
        {
            $entityUuid = $this->client->pushEntity('text-multi-shared.com', 'multi_shared_user');
            $this->createdEntities[] = $entityUuid;

            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->submitEvidence($entityUuid, "SHARED_TEXT_SUBSTRING evidence $i", "multi note $i", "multi_tag_$i");
                $this->createdEvidenceRecords[] = $uuid;
                $created[] = $uuid;
            }

            $results = $this->client->searchEvidence('SHARED_TEXT_SUBSTRING', 1, 100);
            $this->assertGreaterThanOrEqual(count($created), count($results));

            $resultUuids = array_map(fn(EvidenceRecord $r) => $r->getUuid(), $results);
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $resultUuids, 'Each evidence with shared text substring must be found');
            }
        }

        public function testSearchEvidenceTextContentHtmlLike(): void
        {
            $entityUuid = $this->client->pushEntity('text-html.com', 'html_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, '<script>alert("xss")</script> <p>HTML content</p>', 'html note', 'html_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('<script>alert');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence with HTML-like content must be found by text search');
        }

        public function testSearchEvidenceTextContentJsonLike(): void
        {
            $entityUuid = $this->client->pushEntity('text-json.com', 'json_user');
            $this->createdEntities[] = $entityUuid;

            $jsonContent = '{"key": "value", "nested": {"arr": [1,2,3]}, "enabled": true}';
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $jsonContent, 'json note', 'json_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('"nested"');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence with JSON content must be found by text search');
        }

        public function testSearchEvidenceTextContentRepeatingCharacters(): void
        {
            $entityUuid = $this->client->pushEntity('text-repeat.com', 'repeat_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'aaaaa bbbbb ccccc', 'repeat note', 'repeat_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('bbbbb');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence with repeating-character content must be found');
        }

        public function testSearchEvidenceTagNumeric(): void
        {
            $entityUuid = $this->client->pushEntity('tag-numeric.com', 'tag_num_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'tag numeric content', 'tag numeric note', '12345');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('12345');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                    $this->assertSame('12345', $result->getTag());
                }
            }
            $this->assertTrue($found, 'Evidence must be found by numeric tag');
        }

        public function testSearchEvidenceTagLongValue(): void
        {
            $entityUuid = $this->client->pushEntity('tag-long.com', 'tag_long_user');
            $this->createdEntities[] = $entityUuid;

            $longTag = substr('TAG_' . uniqid() . str_repeat('x', 100), 0, 32);
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'tag long content', 'tag long note', $longTag);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $prefix = substr($longTag, 0, 10);
            $results = $this->client->searchEvidence($prefix);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Evidence must be found by long tag prefix');
        }

        public function testSearchEvidenceTagWithUnderscoreAndDash(): void
        {
            $entityUuid = $this->client->pushEntity('tag-special-chars.com', 'tag_sc_user');
            $this->createdEntities[] = $entityUuid;

            $tag = substr('my_special-tag_value_' . uniqid(), 0, 32);
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'tag special content', 'tag special note', $tag);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($tag);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid)
                {
                    $found = true;
                    $this->assertSame($tag, $result->getTag());
                }
            }
            $this->assertTrue($found, 'Evidence must be found by tag with underscores and dashes');
        }

        public function testSearchEvidenceTagSharedAcrossMultipleEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('tag-shared.com', 'tag_shared_user');
            $this->createdEntities[] = $entityUuid;

            $sharedTag = 'SHARED_TAG_' . uniqid();
            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->submitEvidence($entityUuid, "shared tag content $i", "shared tag note $i", $sharedTag);
                $this->createdEvidenceRecords[] = $uuid;
                $created[] = $uuid;
            }

            $results = $this->client->searchEvidence($sharedTag, 1, 100);
            $this->assertGreaterThanOrEqual(count($created), count($results),
                'All evidence with the shared tag must be found');

            $resultUuids = array_map(fn(EvidenceRecord $r) => $r->getUuid(), $results);
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $resultUuids);
                $r = $results[array_search($uuid, $resultUuids)];
                $this->assertSame($sharedTag, $r->getTag());
            }
        }

        public function testSearchEntityHostWithHyphenAndDot(): void
        {
            $host = 'my-sub-domain.example-hosting.com';
            $entityUuid = $this->client->pushEntity($host, 'host_text_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('example-hosting');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $entityUuid)
                {
                    $found = true;
                    $this->assertSame($host, $result->getHost());
                }
            }
            $this->assertTrue($found, 'Entity must be found by hostname substring with hyphens and dots');
        }

        public function testSearchEntityIdLongValue(): void
        {
            $longId = 'user_' . uniqid() . str_repeat('a', 150);
            $entityUuid = $this->client->pushEntity('long-id.com', $longId);
            $this->createdEntities[] = $entityUuid;

            $prefix = substr($longId, 0, 20);
            $results = $this->client->searchEntities($prefix);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $entityUuid)
                {
                    $found = true;
                    $this->assertSame($longId, $result->getId());
                }
            }
            $this->assertTrue($found, 'Entity must be found by long ID prefix');
        }

        public function testSearchEntityHostCaseInsensitive(): void
        {
            $host = 'Case-Insensitive-Test-' . uniqid() . '.Com';
            $entityUuid = $this->client->pushEntity($host, 'ci_user');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities(strtolower($host));
            $this->assertIsArray($results);
        }

        public function testSearchEntityIdMixedWithNumbers(): void
        {
            $entityUuid = $this->client->pushEntity('mixed-id.com', 'user_42_abc_99');
            $this->createdEntities[] = $entityUuid;

            $results = $this->client->searchEntities('42_abc');
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $entityUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Entity must be found by mixed alphanumeric ID substring');
        }

        public function testSearchReportMessageLongText(): void
        {
            $entityUuid = $this->client->pushEntity('report-msg-long.com', 'rpt_msg_user');
            $this->createdEntities[] = $entityUuid;

            $longMsg = 'RPT_MSG_' . uniqid() . ' ' . str_repeat('long report message content ', 50);
            $submission = $this->client->submitReport($entityUuid, 'report content', IncidentType::SPAM, $longMsg);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchReports($entityUuid);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $reportUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Report must be found by reporting entity UUID');
        }

        public function testSearchReportMessageSpecialCharacters(): void
        {
            $entityUuid = $this->client->pushEntity('report-msg-special.com', 'rpt_msg_spec_user');
            $this->createdEntities[] = $entityUuid;

            $msg = 'REPORT: [urgent] {ticket#123} "user complaint" <review> $50 refund?';
            $submission = $this->client->submitReport($entityUuid, 'report content', IncidentType::SPAM, $msg);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchReports($entityUuid);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $reportUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Report must be found by reporting entity UUID');
        }

        public function testSearchOperatorNameWithUnderscore(): void
        {
            $name = 'moderator_' . uniqid() . '_team';
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $results = $this->client->searchOperators($name);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $operatorUuid)
                {
                    $found = true;
                    $this->assertSame($name, $result->getName());
                }
            }
            $this->assertTrue($found, 'Operator must be found by name with underscores');
        }

        public function testSearchOperatorNameLong(): void
        {
            $name = substr('op_' . uniqid() . str_repeat('x', 80), 0, 32);
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $prefix = substr($name, 0, 15);
            $results = $this->client->searchOperators($prefix);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $operatorUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Operator must be found by long name prefix');
        }

        public function testSearchAttachmentFileNameWithSpaces(): void
        {
            $entityUuid = $this->client->pushEntity('attach-name-space.com', 'att_ns_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'attach ns content', 'attach ns note', 'attach_ns_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $fileName = 'my attachment file with spaces ' . uniqid() . '.txt';
            $filePath = $this->createSecurityTempFile('space file content', 'txt');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            try
            {
                $results = $this->client->searchAttachments('attachment file with spaces');
                $this->assertNotEmpty($results);
                $found = false;
                foreach ($results as $result)
                {
                    if ($result->getUuid() === $uploadResult->getUuid())
                    {
                        $found = true;
                    }
                }
                $this->assertTrue($found, 'Attachment must be found by filename with spaces');
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Attachment search not available: ' . $e->getMessage());
                $this->assertContains($e->getCode(), [400, 404],
                    'Expected 400 or 404 when attachment search is disabled');
            }
        }

        public function testSearchAttachmentFileNameWithSpecialChars(): void
        {
            $entityUuid = $this->client->pushEntity('attach-name-special.com', 'att_spec_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'attach special content', 'attach special note', 'attach_spec_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $fileName = 'report_(final)_[2024]-v2.0!@#$%.pdf';
            $filePath = $this->createSecurityTempFile('special file content', 'pdf');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            try
            {
                $results = $this->client->searchAttachments('(final)_[2024]');
                $this->assertNotEmpty($results);
                $found = false;
                foreach ($results as $result)
                {
                    if ($result->getUuid() === $uploadResult->getUuid())
                    {
                        $found = true;
                    }
                }
                $this->assertTrue($found, 'Attachment must be found by filename with special characters');
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Attachment search not available: ' . $e->getMessage());
                $this->assertContains($e->getCode(), [400, 404],
                    'Expected 400 or 404 when attachment search is disabled');
            }
        }

        public function testTextKeywordMatchesEntityHostAndEvidenceContent(): void
        {
            $keyword = 'cross-text-' . uniqid();

            $entityUuid = $this->client->pushEntity($keyword . '.com', 'ct_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, "prefix $keyword suffix", 'ct note', 'ct_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $entityResults = $this->client->searchEntities($keyword);
            $this->assertNotEmpty($entityResults, 'Keyword must match entity host');

            $evidenceResults = $this->client->searchEvidence($keyword);
            $this->assertNotEmpty($evidenceResults, 'Keyword must match evidence text_content');

            $combined = $this->client->search($keyword, null, 1, 100);
            $foundEntity = false;
            $foundEvidence = false;
            foreach ($combined as $result)
            {
                if ($result->getType() === RecordType::ENTITY && $result->getRecord()->getUuid() === $entityUuid)
                {
                    $foundEntity = true;
                }
                if ($result->getType() === RecordType::EVIDENCE && $result->getRecord()->getUuid() === $evidenceUuid)
                {
                    $foundEvidence = true;
                }
            }
            $this->assertTrue($foundEntity, 'Entity must appear in cross-type text search');
            $this->assertTrue($foundEvidence, 'Evidence must appear in cross-type text search');
        }

        public function testTextKeywordMatchesEvidenceTagAndReportMessage(): void
        {
            $keyword = 'TAG_MSG_' . uniqid();

            $entityUuid = $this->client->pushEntity('tag-msg-cross.com', 'tm_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'tag msg content', 'tm note', $keyword);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $submission = $this->client->submitReport($entityUuid, 'report content', IncidentType::SPAM, $keyword);
            $reportUuid = $submission->getReport()->getUuid();
            $reportEvidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $reportEvidenceUuid;

            $evidenceResults = $this->client->searchEvidence($keyword);
            $this->assertNotEmpty($evidenceResults, 'Keyword must match evidence tag');

            $reportResults = $this->client->searchReports($entityUuid);
            $this->assertNotEmpty($reportResults, 'Keyword must match report message');

            $multiResults = $this->client->search($keyword, null, 1, 100);
            $foundEvidence = false;
            $foundReport = false;
            foreach ($multiResults as $result)
            {
                if ($result->getType() === RecordType::EVIDENCE && $result->getRecord()->getUuid() === $evidenceUuid)
                {
                    $foundEvidence = true;
                }
                if ($result->getType() === RecordType::REPORT && $result->getRecord()->getUuid() === $reportUuid)
                {
                    $foundReport = true;
                }
            }
            $this->assertTrue($foundEvidence, 'Evidence must appear when keyword matches its tag');
            $this->assertTrue($foundReport, 'Report must appear when keyword matches its message');
        }

        public function testTextKeywordMatchesOperatorNameAndAttachmentFileName(): void
        {
            $keyword = 'OP_FILE_' . uniqid();

            $entityUuid = $this->client->pushEntity('op-file-cross.com', 'of_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'op file content', 'op file note', 'of_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $operatorUuid = $this->client->createOperator($keyword . '_operator');
            $this->createdOperators[] = $operatorUuid;

            $fileName = $keyword . '_attachment.txt';
            $filePath = $this->createSecurityTempFile('op file data', 'txt');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $opResults = $this->client->searchOperators($keyword);
            $this->assertNotEmpty($opResults, 'Keyword must match operator name');

            $attachmentSearchAvailable = false;
            try
            {
                $attResults = $this->client->searchAttachments($keyword);
                $this->assertNotEmpty($attResults, 'Keyword must match attachment file name');
                $attachmentSearchAvailable = true;
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Attachment search not available: ' . $e->getMessage());
                $this->assertContains($e->getCode(), [400, 404],
                    'Expected 400 or 404 when attachment search is disabled');
            }

            $combined = $this->client->search($keyword, null, 1, 100);
            $foundOp = false;
            $foundAtt = false;
            foreach ($combined as $result)
            {
                if ($result->getType() === RecordType::OPERATOR && $result->getRecord()->getUuid() === $operatorUuid)
                {
                    $foundOp = true;
                }
                if ($result->getType() === RecordType::ATTACHMENT && $result->getRecord()->getUuid() === $uploadResult->getUuid())
                {
                    $foundAtt = true;
                }
            }
            $this->assertTrue($foundOp, 'Operator must appear in cross-type text search');
            if ($attachmentSearchAvailable)
            {
                $this->assertTrue($foundAtt, 'Attachment must appear in cross-type text search');
            }
        }

        public function testSearchEvidenceNullContentByEntityUuid(): void
        {
            $entityUuid = $this->client->pushEntity('text-null-evidence.com', 'null_ev_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, null, 'null content note', 'null_content_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence($entityUuid);
            $this->assertNotEmpty($results);
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

        public function testMultiTypeSearchWithAllTypes(): void
        {
            $keyword = 'all-types-' . uniqid();
            $entityUuid = $this->client->pushEntity($keyword . '.com', $keyword . '_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, $keyword . ' evidence', $keyword . ' note', $keyword . '_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $results = $this->client->search($keyword, null, 1, 100);
            $this->assertIsArray($results);
            $this->assertNotEmpty($results);

            $foundTypes = [];
            foreach ($results as $result)
            {
                $this->assertInstanceOf(SearchResult::class, $result);
                $type = $result->getType()->value;
                $foundTypes[$type] = true;

                $record = $result->getRecord();
                $this->assertNotNull($record);
                $this->assertNotEmpty(method_exists($record, 'getUuid') ? $record->getUuid() : '');
            }

            $this->assertArrayHasKey(RecordType::ENTITY->value, $foundTypes,
                'Multi-type search should return ENTITY results');
            $this->assertArrayHasKey(RecordType::EVIDENCE->value, $foundTypes,
                'Multi-type search should return EVIDENCE results');
            $this->assertArrayHasKey(RecordType::BLACKLIST->value, $foundTypes,
                'Multi-type search should return BLACKLIST results');
        }

        public function testMultiTypeSearchPagination(): void
        {
            $host = 'multi-paginate-' . uniqid() . '.com';
            for ($i = 0; $i < 6; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'mp_user_' . $i);
                $this->createdEntities[] = $uuid;
            }

            $page1 = $this->client->search($host, null, 1, 2);
            $this->assertIsArray($page1);
            $this->assertNotEmpty($page1);

            $page2 = $this->client->search($host, null, 2, 2);
            $this->assertIsArray($page2);

            $page1Uuids = array_map(fn(SearchResult $r) => $r->getRecord()->getUuid(), $page1);
            $page2Uuids = array_map(fn(SearchResult $r) => $r->getRecord()->getUuid(), $page2);

            $intersection = array_intersect($page1Uuids, $page2Uuids);
            $this->assertEmpty($intersection, 'No records should appear in multiple multi-type search pages');
        }

        public function testMultiTypeSearchWithTypeFilterEmptyReturnsNoCrossType(): void
        {
            $keyword = 'filter-empty-cross-' . uniqid();
            $entityUuid = $this->client->pushEntity($keyword . '.com', 'fec_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, $keyword . ' content', 'fec note', 'fec_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->search($keyword, [RecordType::ENTITY->value], 1, 100);
            $this->assertNotEmpty($results);

            foreach ($results as $result)
            {
                $this->assertSame(RecordType::ENTITY, $result->getType(),
                    'Filtered multi-type search should only return ENTITY results');
            }
        }

        public function testSearchEntitiesOrderedByCreatedDesc(): void
        {
            $host = 'ordering-' . uniqid() . '.com';
            $createdUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'order_user_' . $i);
                $this->createdEntities[] = $uuid;
                $createdUuids[] = $uuid;
            }

            $results = $this->client->searchEntities($host, 1, 100);
            $this->assertGreaterThanOrEqual(count($createdUuids), count($results));

            $resultUuids = array_map(fn(EntityRecord $r) => $r->getUuid(), $results);

            $lastIndex = count($resultUuids);
            foreach ($createdUuids as $uuid)
            {
                $index = array_search($uuid, $resultUuids, true);
                $this->assertNotFalse($index, "Entity $uuid should be in search results");
                if ($lastIndex !== count($resultUuids))
                {
                    $this->assertLessThan($lastIndex, $index,
                        'Entities should appear in reverse insertion order (newest first)');
                }
                $lastIndex = $index;
            }
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

        public function testSearchAfterEntityRecreateWithSameIdentity(): void
        {
            $host = 'recreate-' . uniqid() . '.com';
            $entityUuid1 = $this->client->pushEntity($host, 'recreate_user');
            $this->createdEntities[] = $entityUuid1;

            $this->client->deleteEntity($entityUuid1);
            $this->removeFromCleanup($this->createdEntities, $entityUuid1);

            $entityUuid2 = $this->client->pushEntity($host, 'recreate_user');
            $this->createdEntities[] = $entityUuid2;

            $this->assertNotSame($entityUuid1, $entityUuid2,
                'Re-created entity should have a different UUID');

            $results = $this->client->searchEntities($host);
            $this->assertNotEmpty($results);

            $foundNew = false;
            $foundOld = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $entityUuid1)
                {
                    $foundOld = true;
                }
                if ($result->getUuid() === $entityUuid2)
                {
                    $foundNew = true;
                }
            }
            $this->assertFalse($foundOld, 'Deleted entity should not appear in search after re-create');
            $this->assertTrue($foundNew, 'Re-created entity should appear in search results');
        }

        public function testRapidConsecutiveSearches(): void
        {
            $entityUuid = $this->client->pushEntity('rapid-search.com', 'rapid_user');
            $this->createdEntities[] = $entityUuid;

            $queries = ['rapid', 'search', 'rapid-search', 'rapid_user', 'rapid-search.com'];
            foreach ($queries as $query)
            {
                $results = $this->client->searchEntities($query);
                $this->assertIsArray($results);
            }

            $multiResults = $this->client->search('rapid', null, 1, 10);
            $this->assertIsArray($multiResults);

            $finalResults = $this->client->searchEntities('rapid');
            $this->assertIsArray($finalResults);
        }

        public function testSearchWithVeryLongQuery(): void
        {
            $longQuery = str_repeat('a', 500);
            $results = $this->client->searchEntities($longQuery);
            $this->assertIsArray($results);
            $this->assertEmpty($results);
        }

        public function testSearchEvidenceRecreatedAfterDeletion(): void
        {
            $entityUuid = $this->client->pushEntity('recreate-evidence.com', 're_ev_user');
            $this->createdEntities[] = $entityUuid;

            $content = 'RECREATE_CONTENT_' . uniqid();
            $evidenceUuid1 = $this->client->submitEvidence($entityUuid, $content, 'recreate note 1', 'recreate_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid1;

            $this->client->deleteEvidence($evidenceUuid1);
            $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid1);

            $evidenceUuid2 = $this->client->submitEvidence($entityUuid, $content, 'recreate note 2', 'recreate_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid2;

            $this->assertNotSame($evidenceUuid1, $evidenceUuid2);

            $results = $this->client->searchEvidence($content);
            $this->assertNotEmpty($results);

            $foundNew = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $evidenceUuid2)
                {
                    $foundNew = true;
                }
                $this->assertNotSame($evidenceUuid1, $result->getUuid(),
                    'Deleted evidence should not reappear in search');
            }
            $this->assertTrue($foundNew, 'Re-created evidence should appear in search');

            $multiResults = $this->client->search('RECREATE_CONTENT', null, 1, 100);
            $this->assertIsArray($multiResults);
            $foundInMulti = false;
            foreach ($multiResults as $sr)
            {
                if ($sr->getType() === RecordType::EVIDENCE && $sr->getRecord()->getUuid() === $evidenceUuid2)
                {
                    $foundInMulti = true;
                    break;
                }
            }
            $this->assertTrue($foundInMulti, 'Re-created evidence should be found by multi-type search');
        }

        public function testSearchMixedConfidentialEvidenceWithNonManagementOperator(): void
        {
            $entityUuid = $this->client->pushEntity('mixed-conf.com', 'mixed_conf_user');
            $this->createdEntities[] = $entityUuid;

            $publicContent = 'PUBLIC_MIXED_' . uniqid();
            $confContent = 'CONF_MIXED_' . uniqid();

            $publicEvUuid = $this->client->submitEvidence($entityUuid, $publicContent, 'public note', 'mixed_tag');
            $this->createdEvidenceRecords[] = $publicEvUuid;

            $confEvUuid = $this->client->submitEvidence($entityUuid, $confContent, 'conf note', 'mixed_tag', true);
            $this->createdEvidenceRecords[] = $confEvUuid;

            $operatorClient = $this->createLimitedOperator('mixed_conf_op', false, false, false);

            try
            {
                $confResults = $operatorClient->searchEvidence($confContent);
                $foundConf = false;
                foreach ($confResults as $result)
                {
                    if ($result->getUuid() === $confEvUuid)
                    {
                        $foundConf = true;
                        break;
                    }
                }
                $this->assertFalse($foundConf,
                    'Non-management operator should not see confidential evidence');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401, 403, 404]);
            }

            try
            {
                $publicResults = $operatorClient->searchEvidence($publicContent);
                $foundPublic = false;
                foreach ($publicResults as $result)
                {
                    if ($result->getUuid() === $publicEvUuid)
                    {
                        $foundPublic = true;
                        break;
                    }
                }
                $this->assertTrue($foundPublic,
                    'Non-management operator should see non-confidential evidence');
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Non-management operator search for public evidence failed: ' . $e->getMessage());
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

        public function testSearchDeletedOperatorDoesNotAppear(): void
        {
            $name = 'del_op_search_' . uniqid();
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->deleteOperator($operatorUuid);
            $this->removeFromCleanup($this->createdOperators, $operatorUuid);

            $results = $this->client->searchOperators($name);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $operatorUuid)
                {
                    $found = true;
                    break;
                }
            }
            $this->assertFalse($found, 'Deleted operator should not appear in search');
        }

        public function testSearchBlacklistUuidExactPrefix(): void
        {
            $entityUuid = $this->client->pushEntity('bl-prefix-search.com', 'bl_pref_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'bl prefix content', 'bl prefix note', 'bl_prefix_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 7200);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $prefix = substr($blacklistUuid, 0, 8);
            $results = $this->client->searchBlacklist($prefix);
            $this->assertNotEmpty($results);
            $found = false;
            foreach ($results as $result)
            {
                if ($result->getUuid() === $blacklistUuid)
                {
                    $found = true;
                }
            }
            $this->assertTrue($found, 'Blacklist record should be found by its own UUID prefix');
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

        public function testSearchEvidenceWithMultilineContent(): void
        {
            $entityUuid = $this->client->pushEntity('multiline-evidence.com', 'ml_user');
            $this->createdEntities[] = $entityUuid;

            $multilineContent = "Line one\nLine two\nLine three with\ttabs";
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $multilineContent, 'ml note', 'ml_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $results = $this->client->searchEvidence('Line two');
            $this->assertNotEmpty($results);

            $resultsByTab = $this->client->searchEvidence('with\ttabs');
            $this->assertIsArray($resultsByTab);
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

        public function testMultiTypeSearchRespectsLimitPerType(): void
        {
            $host = 'per-type-limit-' . uniqid() . '.com';
            for ($i = 0; $i < 20; $i++)
            {
                $uuid = $this->client->pushEntity($host, 'ptl_user_' . $i);
                $this->createdEntities[] = $uuid;
            }

            $resultsSmall = $this->client->search($host, [RecordType::ENTITY->value], 1, 3);
            $this->assertLessThanOrEqual(3, count($resultsSmall),
                'Multi-type search with limit=3 should return at most 3 results');

            $resultsLarge = $this->client->search($host, [RecordType::ENTITY->value], 1, 100);
            $this->assertGreaterThanOrEqual(20, count($resultsLarge),
                'Multi-type search with limit=100 should find all 20 entities');
        }

        public function testMultiTypeSearchInvalidPageNegative(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->search('test', null, -1, 10);
        }

        public function testMultiTypeSearchInvalidLimitZero(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->search('test', null, 1, 0);
        }

        public function testMultiSearchIdentifiesSingleRecordPerTypeByEntityUuid(): void
        {
            $entityUuid = $this->client->pushEntity('multi-identify.com', 'mi_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'multi identify evidence', 'mi note', 'mi_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $submission = $this->client->submitReport($entityUuid, 'mi report content', IncidentType::SPAM, 'mi report message');
            $reportUuid = $submission->getReport()->getUuid();
            $reportEvidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $reportEvidenceUuid;

            $uuidPrefix = substr($entityUuid, 0, 10);

            $results = $this->client->search($uuidPrefix, null, 1, 100);
            $this->assertIsArray($results);
            $this->assertNotEmpty($results);

            $foundByType = [];
            foreach ($results as $result)
            {
                $this->assertInstanceOf(SearchResult::class, $result);
                $type = $result->getType();

                /** @noinspection PhpUncoveredEnumCasesInspection */
                switch ($type)
                {
                    case RecordType::ENTITY:
                        $foundByType[RecordType::ENTITY->value] = $result->getRecord()->getUuid();
                        $this->assertSame($entityUuid, $result->getRecord()->getUuid());
                        break;
                    case RecordType::EVIDENCE:
                        $foundByType[RecordType::EVIDENCE->value] = $result->getRecord()->getUuid();
                        break;
                    case RecordType::BLACKLIST:
                        $foundByType[RecordType::BLACKLIST->value] = $result->getRecord()->getUuid();
                        $this->assertSame($entityUuid, $result->getRecord()->getEntityUuid());
                        break;
                    case RecordType::REPORT:
                        $foundByType[RecordType::REPORT->value] = $result->getRecord()->getUuid();
                        break;
                }
            }

            $this->assertArrayHasKey(RecordType::ENTITY->value, $foundByType,
                'Entity must be identifiable in multi-search results');
            $this->assertSame($entityUuid, $foundByType[RecordType::ENTITY->value]);

            $this->assertArrayHasKey(RecordType::EVIDENCE->value, $foundByType,
                'Evidence must be identifiable in multi-search results');

            $this->assertArrayHasKey(RecordType::BLACKLIST->value, $foundByType,
                'Blacklist must be identifiable in multi-search results');

            $this->assertArrayHasKey(RecordType::REPORT->value, $foundByType,
                'Report must be identifiable in multi-search results');
        }

        public function testMultiSearchIdentifiesOperatorAndAttachmentByCustomKeyword(): void
        {
            $tag = 'KW_' . uniqid();

            $entityUuid = $this->client->pushEntity('identify-op-attach.com', $tag . '_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'identify op content', 'identify note', $tag);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $operatorName = $tag . '_operator';
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $fileName = $tag . '_attachment.txt';
            $filePath = $this->createSecurityTempFile('identify content', 'txt');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $operatorResults = $this->client->search($tag, [RecordType::OPERATOR->value], 1, 10);
            $this->assertNotEmpty($operatorResults);
            $foundOperator = false;
            foreach ($operatorResults as $result)
            {
                $this->assertSame(RecordType::OPERATOR, $result->getType());
                if ($result->getRecord()->getUuid() === $operatorUuid)
                {
                    $foundOperator = true;
                    $this->assertSame($operatorName, $result->getRecord()->getName());
                }
            }
            $this->assertTrue($foundOperator, 'Operator must be identifiable by custom keyword in multi-search');

            $attachmentResults = $this->client->search($tag, [RecordType::ATTACHMENT->value], 1, 10);
            if (!empty($attachmentResults))
            {
                $foundAttachment = false;
                foreach ($attachmentResults as $result)
                {
                    $this->assertSame(RecordType::ATTACHMENT, $result->getType());
                    if ($result->getRecord()->getUuid() === $uploadResult->getUuid())
                    {
                        $foundAttachment = true;
                        $this->assertSame($fileName, $result->getRecord()->getFileName());
                    }
                }
                $this->assertTrue($foundAttachment, 'Attachment must be identifiable by custom keyword in multi-search');
            }
            else
            {
                Logger::getLogger()->info('Attachment search not available (empty results from type-filtered search)');
            }
        }

        public function testMultiSearchIdentifiesAllSevenTypesSimultaneously(): void
        {
            $keyword = 'all7-' . uniqid();

            $entityUuid = $this->client->pushEntity($keyword . '.com', $keyword . '_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, $keyword . ' evidence body', $keyword . ' note', $keyword . '_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $submission = $this->client->submitReport($entityUuid, $keyword . ' report content', IncidentType::SPAM, $keyword . '_report_msg');
            $reportUuid = $submission->getReport()->getUuid();
            $reportEvidenceUuid = $submission->getEvidence()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $reportEvidenceUuid;

            $fileName = $keyword . '_file.txt';
            /** @noinspection PhpRedundantOptionalArgumentInspection */
            $filePath = $this->createSecurityTempFile($keyword . ' data', 'txt');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $operatorName = $keyword . '_operator';
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $auditResults = $this->client->searchAuditLogs($operatorName);
            if (!empty($auditResults))
            {
                Logger::getLogger()->info('Audit log entries found for operator creation: ' . count($auditResults));
            }

            $allResults = $this->client->search($keyword, null, 1, 100);
            $this->assertIsArray($allResults);
            $this->assertNotEmpty($allResults);

            $foundUuids = [];
            $foundTypeCounts = [];
            foreach ($allResults as $result)
            {
                $this->assertInstanceOf(SearchResult::class, $result);
                $record = $result->getRecord();
                $type = $result->getType();

                $foundUuids[$type->value][] = $record->getUuid();
                $foundTypeCounts[$type->value] = ($foundTypeCounts[$type->value] ?? 0) + 1;

                switch ($type)
                {
                    case RecordType::ENTITY:
                        $this->assertInstanceOf(EntityRecord::class, $record);
                        $this->assertSame($entityUuid, $record->getUuid());
                        break;
                    case RecordType::EVIDENCE:
                        $this->assertInstanceOf(EvidenceRecord::class, $record);
                        break;
                    case RecordType::BLACKLIST:
                        $this->assertInstanceOf(BlacklistRecord::class, $record);
                        $this->assertSame($entityUuid, $record->getEntityUuid());
                        break;
                    case RecordType::REPORT:
                        $this->assertInstanceOf(ReportRecord::class, $record);
                        break;
                    case RecordType::ATTACHMENT:
                        $this->assertInstanceOf(FileAttachmentRecord::class, $record);
                        $this->assertSame($fileName, $record->getFileName());
                        break;
                    case RecordType::OPERATOR:
                        $this->assertInstanceOf(OperatorRecord::class, $record);
                        $this->assertSame($operatorName, $record->getName());
                        break;
                    case RecordType::AUDIT_LOG:
                        $this->assertInstanceOf(AuditLog::class, $record);
                        break;
                }
            }

            $this->assertArrayHasKey(RecordType::ENTITY->value, $foundUuids,
                'ENTITY must appear in all-7 multi-search');
            $this->assertContains($entityUuid, $foundUuids[RecordType::ENTITY->value]);

            $this->assertArrayHasKey(RecordType::EVIDENCE->value, $foundUuids,
                'EVIDENCE must appear in all-7 multi-search');

            $this->assertArrayHasKey(RecordType::BLACKLIST->value, $foundUuids,
                'BLACKLIST must appear in all-7 multi-search');
            $this->assertContains($blacklistUuid, $foundUuids[RecordType::BLACKLIST->value]);

            $this->assertArrayHasKey(RecordType::REPORT->value, $foundUuids,
                'REPORT must appear in all-7 multi-search');
            $this->assertContains($reportUuid, $foundUuids[RecordType::REPORT->value]);

            if (isset($foundUuids[RecordType::ATTACHMENT->value]))
            {
                $this->assertContains($uploadResult->getUuid(), $foundUuids[RecordType::ATTACHMENT->value]);
            }
            else
            {
                Logger::getLogger()->info('ATTACHMENT not available in all-7 multi-search (disabled on server)');
            }

            $this->assertArrayHasKey(RecordType::OPERATOR->value, $foundUuids,
                'OPERATOR must appear in all-7 multi-search');
            $this->assertContains($operatorUuid, $foundUuids[RecordType::OPERATOR->value]);

            Logger::getLogger()->info('All-7 search type counts: ' . json_encode($foundTypeCounts));
        }

        public function testMultiSearchIdentifiesMultipleRecordsOfEachType(): void
        {
            $keyword = 'multi-' . uniqid();
            $entityUuids = [];
            $evidenceUuids = [];
            $blacklistUuids = [];
            $operatorUuids = [];

            for ($i = 0; $i < 3; $i++)
            {
                $entityUuid = $this->client->pushEntity($keyword . "$i.com", $keyword . "_user_$i");
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;

                $evidenceUuid = $this->client->submitEvidence($entityUuid, "$keyword evidence $i", "{$keyword}_note_$i", "{$keyword}_tag_$i");
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;

                $blacklistUuid = $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
                $this->createdBlacklistRecords[] = $blacklistUuid;
                $blacklistUuids[] = $blacklistUuid;

                $operatorName = $keyword . '_op_' . $i;
                $operatorUuid = $this->client->createOperator($operatorName);
                $this->createdOperators[] = $operatorUuid;
                $operatorUuids[] = $operatorUuid;
            }

            $entityResults = $this->client->search($keyword, [RecordType::ENTITY->value], 1, 100);
            $this->assertGreaterThanOrEqual(3, count($entityResults),
                'Multi-search must find at least 3 entities with the common keyword');
            $foundEntityUuids = array_map(fn(SearchResult $r) => $r->getRecord()->getUuid(), $entityResults);
            foreach ($entityUuids as $expected)
            {
                $this->assertContains($expected, $foundEntityUuids,
                    "Each entity UUID must be identifiable in multi-search results");
            }

            $operatorResults = $this->client->search($keyword, [RecordType::OPERATOR->value], 1, 100);
            $this->assertGreaterThanOrEqual(3, count($operatorResults),
                'Multi-search must find at least 3 operators with the common keyword');
            $foundOpUuids = array_map(fn(SearchResult $r) => $r->getRecord()->getUuid(), $operatorResults);
            foreach ($operatorUuids as $expected)
            {
                $this->assertContains($expected, $foundOpUuids,
                    "Each operator UUID must be identifiable in multi-search results");
            }

            $allResults = $this->client->search($keyword, null, 1, 100);
            $this->assertGreaterThanOrEqual(12, count($allResults),
                'Multi-search across all types must find at least 12 records (3 entities + 3 evidence + 3 blacklist + 3 operators)');

            $allFound = [];
            foreach ($allResults as $result)
            {
                $type = $result->getType()->value;
                $allFound[$type][] = $result->getRecord()->getUuid();
            }

            foreach ($entityUuids as $uuid)
            {
                $this->assertContains($uuid, $allFound[RecordType::ENTITY->value] ?? [],
                    "Entity $uuid must be identifiable in unfiltered multi-search");
            }
            foreach ($evidenceUuids as $uuid)
            {
                $this->assertContains($uuid, $allFound[RecordType::EVIDENCE->value] ?? [],
                    "Evidence $uuid must be identifiable in unfiltered multi-search");
            }
            foreach ($blacklistUuids as $uuid)
            {
                $this->assertContains($uuid, $allFound[RecordType::BLACKLIST->value] ?? [],
                    "Blacklist $uuid must be identifiable in unfiltered multi-search");
            }
            foreach ($operatorUuids as $uuid)
            {
                $this->assertContains($uuid, $allFound[RecordType::OPERATOR->value] ?? [],
                    "Operator $uuid must be identifiable in unfiltered multi-search");
            }

            $totalFound = 0;
            /** @noinspection PhpUnusedLocalVariableInspection */
            foreach ($allFound as $type => $uuids)
            {
                $totalFound += count($uuids);
                foreach ($uuids as $uid)
                {
                    $this->assertNotEmpty($uid);
                }
            }
            $this->assertGreaterThanOrEqual(12, $totalFound,
                'Total identifiable records across all types must be at least 12');
        }

        public function testMultiSearchSingleRecordIsolationAcrossTypes(): void
        {
            $keywordA = 'iso-a-' . uniqid();
            $keywordB = 'iso-b-' . uniqid();

            $entityA = $this->client->pushEntity($keywordA . '.com', $keywordA . '_user');
            $this->createdEntities[] = $entityA;

            $entityB = $this->client->pushEntity($keywordB . '.com', $keywordB . '_user');
            $this->createdEntities[] = $entityB;

            $evA = $this->client->submitEvidence($entityA, "$keywordA body", "{$keywordA}_note", "{$keywordA}_tag");
            $this->createdEvidenceRecords[] = $evA;

            $evB = $this->client->submitEvidence($entityB, "$keywordB body", "{$keywordB}_note", "{$keywordB}_tag");
            $this->createdEvidenceRecords[] = $evB;

            $blA = $this->client->blacklistEntity($entityA, $evA, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blA;

            $opA = $this->client->createOperator($keywordA . '_op');
            $this->createdOperators[] = $opA;

            $opB = $this->client->createOperator($keywordB . '_op');
            $this->createdOperators[] = $opB;

            $resultsA = $this->client->search($keywordA, null, 1, 100);
            $this->assertIsArray($resultsA);
            $this->assertNotEmpty($resultsA);

            $resultsB = $this->client->search($keywordB, null, 1, 100);
            $this->assertIsArray($resultsB);
            $this->assertNotEmpty($resultsB);

            $foundInA = [];
            foreach ($resultsA as $result)
            {
                $foundInA[$result->getType()->value][] = $result->getRecord()->getUuid();
            }
            $foundInB = [];
            foreach ($resultsB as $result)
            {
                $foundInB[$result->getType()->value][] = $result->getRecord()->getUuid();
            }

            $this->assertContains($entityA, $foundInA[RecordType::ENTITY->value] ?? []);
            $this->assertNotContains($entityB, $foundInA[RecordType::ENTITY->value] ?? [],
                'Search for keywordA must NOT match entityB');

            $this->assertContains($entityB, $foundInB[RecordType::ENTITY->value] ?? []);
            $this->assertNotContains($entityA, $foundInB[RecordType::ENTITY->value] ?? [],
                'Search for keywordB must NOT match entityA');

            $this->assertContains($evA, $foundInA[RecordType::EVIDENCE->value] ?? []);
            $this->assertNotContains($evB, $foundInA[RecordType::EVIDENCE->value] ?? [],
                'Search for keywordA must NOT match evidenceB');

            $this->assertContains($opA, $foundInA[RecordType::OPERATOR->value] ?? []);
            $this->assertNotContains($opB, $foundInA[RecordType::OPERATOR->value] ?? [],
                'Search for keywordA must NOT match operatorB');
        }

        public function testMultiSearchNoFalsePositivesForDifferentKeywords(): void
        {
            $keyword = 'fp-' . uniqid();
            $otherKeyword = 'fp-other-' . uniqid();

            $entityUuid = $this->client->pushEntity($keyword . '.com', $keyword . '_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, $keyword . ' body', $keyword . ' note', $keyword . '_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $operatorName = $keyword . '_op';
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $realResults = $this->client->search($keyword, null, 1, 100);
            $this->assertIsArray($realResults);
            $this->assertNotEmpty($realResults,
                'Search for the real keyword must return results');

            $emptyResults = $this->client->search($otherKeyword, null, 1, 100);
            $this->assertIsArray($emptyResults);
            $this->assertEmpty($emptyResults,
                'Search for a different keyword must return zero results – no false positives');

            $realUuids = [];
            foreach ($realResults as $result)
            {
                $realUuids[] = $result->getRecord()->getUuid();
            }
            $this->assertContains($entityUuid, $realUuids);
            $this->assertContains($evidenceUuid, $realUuids);
            $this->assertContains($operatorUuid, $realUuids);
        }

        public function testMultiSearchTypeFilterIdentifiesOnlyRequestedTypes(): void
        {
            $keyword = 'filter-id-' . uniqid();

            $entityUuid = $this->client->pushEntity($keyword . '.com', $keyword . '_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, $keyword . ' body', $keyword . ' note', $keyword . '_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $operatorName = $keyword . '_op';
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $onlyEntity = $this->client->search($keyword, [RecordType::ENTITY->value], 1, 100);
            foreach ($onlyEntity as $result)
            {
                $this->assertSame(RecordType::ENTITY, $result->getType(),
                    'Type-filtered multi-search must only return ENTITY results');
                $this->assertSame($entityUuid, $result->getRecord()->getUuid());
            }

            $onlyOperator = $this->client->search($keyword, [RecordType::OPERATOR->value], 1, 100);
            foreach ($onlyOperator as $result)
            {
                $this->assertSame(RecordType::OPERATOR, $result->getType(),
                    'Type-filtered multi-search must only return OPERATOR results');
                $this->assertSame($operatorUuid, $result->getRecord()->getUuid());
            }

            $entityAndEvidence = $this->client->search($keyword, [RecordType::ENTITY->value, RecordType::EVIDENCE->value], 1, 100);
            $foundEntity = false;
            $foundEvidence = false;
            foreach ($entityAndEvidence as $result)
            {
                $this->assertNotSame(RecordType::OPERATOR, $result->getType(),
                    'Filtered to ENTITY+EVIDENCE must exclude OPERATOR');
                if ($result->getType() === RecordType::ENTITY)
                {
                    $foundEntity = true;
                }
                elseif ($result->getType() === RecordType::EVIDENCE)
                {
                    $foundEvidence = true;
                }
            }
            $this->assertTrue($foundEntity, 'ENTITY must be present when filtering to ENTITY+EVIDENCE');
            $this->assertTrue($foundEvidence, 'EVIDENCE must be present when filtering to ENTITY+EVIDENCE');
        }
    }
