<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\ContentInput;
    use PHPUnit\Framework\TestCase;

    /**
     * The ADVERTISING and TRACKING blacklist types, the type filter of List Blacklist and Search Blacklist, and blacklist
     * records supported by a report about another entity (OFD 2.0)
     */
    class BlacklistTypesReportsTest extends TestCase
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

        private function createEntity(string $prefix): string
        {
            $entityUuid = $this->client->pushEntity($this->uniqueHost($prefix));
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        private function blacklist(string $entityUuid, IncidentType $type, ?string $reportUuid = null): string
        {
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $reportUuid, $type, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            return $blacklistUuid;
        }

        public function testNewIncidentTypesCanBeBlacklisted(): void
        {
            foreach([IncidentType::ADVERTISING, IncidentType::TRACKING] as $type)
            {
                $blacklistUuid = $this->blacklist($this->createEntity('type'), $type);
                $this->assertSame($type, $this->client->getBlacklistRecord($blacklistUuid)->getType());
            }
        }

        public function testInvalidBlacklistTypeIsRejected(): void
        {
            $entityUuid = $this->createEntity('type');
            [$code, $response] = $this->rawRequest('POST', '/blacklist', $this->rootToken(), json_encode(['entity_identifier' => $entityUuid, 'type' => 'BOGUS']));

            $this->assertSame(400, $code, $response);
        }

        public function testListBlacklistFiltersByType(): void
        {
            $advertising = $this->blacklist($this->createEntity('ads'), IncidentType::ADVERTISING);
            $tracking = $this->blacklist($this->createEntity('tracker'), IncidentType::TRACKING);
            $spam = $this->blacklist($this->createEntity('spam'), IncidentType::SPAM);

            $records = $this->client->listBlacklistRecords(1, 100, false, null, null, null, [IncidentType::ADVERTISING]);
            $uuids = array_map(fn(BlacklistRecord $record) => $record->getUuid(), $records);

            $this->assertContains($advertising, $uuids);
            $this->assertNotContains($tracking, $uuids);
            $this->assertNotContains($spam, $uuids);
            foreach($records as $record)
            {
                $this->assertSame(IncidentType::ADVERTISING, $record->getType());
            }

            $records = $this->client->listBlacklistRecords(1, 100, false, null, null, null, [IncidentType::ADVERTISING, IncidentType::TRACKING]);
            $uuids = array_map(fn(BlacklistRecord $record) => $record->getUuid(), $records);

            $this->assertContains($advertising, $uuids);
            $this->assertContains($tracking, $uuids);
            $this->assertNotContains($spam, $uuids);
        }

        public function testListBlacklistTypeIsCaseInsensitive(): void
        {
            $advertising = $this->blacklist($this->createEntity('ads'), IncidentType::ADVERTISING);

            [$code, $body] = $this->jsonRequest('GET', '/blacklist?limit=100&type=advertising,%20Tracking', $this->rootToken());

            $this->assertSame(200, $code, json_encode($body));
            $this->assertContains($advertising, array_column($body, 'uuid'));
            foreach($body as $record)
            {
                $this->assertContains($record['type'], ['ADVERTISING', 'TRACKING']);
            }
        }

        public function testListBlacklistRejectsInvalidType(): void
        {
            foreach(['BOGUS', 'SPAM,BOGUS'] as $type)
            {
                [$code, $response] = $this->rawRequest('GET', '/blacklist?type=' . rawurlencode($type), $this->rootToken());
                $this->assertSame(400, $code, $type . ' ' . $response);
            }
        }

        public function testSearchBlacklistFiltersByType(): void
        {
            $entityUuid = $this->createEntity('search');
            $advertising = $this->blacklist($entityUuid, IncidentType::ADVERTISING);
            $spam = $this->blacklist($entityUuid, IncidentType::SPAM);

            $uuids = array_map(fn(BlacklistRecord $record) => $record->getUuid(), $this->client->searchBlacklist($entityUuid, 1, 50, null, null, null, [IncidentType::ADVERTISING]));
            $this->assertContains($advertising, $uuids);
            $this->assertNotContains($spam, $uuids);

            [$code, $response] = $this->rawRequest('GET', '/blacklist/search?q=' . $entityUuid . '&type=BOGUS', $this->rootToken());
            $this->assertSame(400, $code, $response);
        }

        public function testBlacklistWithoutReport(): void
        {
            $blacklistUuid = $this->blacklist($this->createEntity('noreport'), IncidentType::SPAM);
            $this->assertNull($this->client->getBlacklistRecord($blacklistUuid)->getReportUuid());
        }

        public function testBlacklistReferencingReportAboutAnotherEntity(): void
        {
            // An account advertising a domain is reported, the domain is blacklisted on the strength of that report
            $accountUuid = $this->createEntity('account');
            $domainUuid = $this->createEntity('advertised');
            $reportUuid = $this->createReportForEntity($accountUuid, null, 'Buy now at the advertised domain');

            $blacklistUuid = $this->blacklist($domainUuid, IncidentType::SPAM, $reportUuid);

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertSame($domainUuid, $record->getEntityUuid());
            $this->assertSame($reportUuid, $record->getReportUuid());
            // The report still concerns the account
            $this->assertSame($accountUuid, $this->client->getReport($reportUuid)->getReportingEntity());
        }

        public function testBlacklistReferencingReportAboutSameEntity(): void
        {
            $entityUuid = $this->createEntity('same');
            $reportUuid = $this->createReportForEntity($entityUuid);

            $blacklistUuid = $this->blacklist($entityUuid, IncidentType::SPAM, $reportUuid);
            $this->assertSame($reportUuid, $this->client->getBlacklistRecord($blacklistUuid)->getReportUuid());
        }

        public function testBlacklistReferencingUnknownReport(): void
        {
            $entityUuid = $this->createEntity('unknown');

            [$code, $response] = $this->rawRequest('POST', '/blacklist', $this->rootToken(), json_encode([
                'entity_identifier' => $entityUuid,
                'type' => 'SPAM',
                'report_uuid' => $this->randomUuid(),
                'expires' => time() + 3600,
            ]));
            $this->assertSame(404, $code, $response);

            [$code, $response] = $this->rawRequest('POST', '/blacklist', $this->rootToken(), json_encode([
                'entity_identifier' => $entityUuid,
                'type' => 'SPAM',
                'report_uuid' => 'not-a-uuid',
                'expires' => time() + 3600,
            ]));
            $this->assertSame(400, $code, $response);
        }

        public function testBlacklistHasNoEvidenceReference(): void
        {
            // A blacklist record references a report, the evidence of the report references it in turn
            $entityUuid = $this->createEntity('evidence');
            $submission = $this->client->submitReport($entityUuid, new ContentInput('Reported content'), IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();
            foreach($submission->getEvidence() as $evidence)
            {
                $this->createdEvidenceRecords[] = $evidence->getUuid();
            }

            $blacklistUuid = $this->blacklist($entityUuid, IncidentType::SPAM, $submission->getReport()->getUuid());

            [$code, $body] = $this->jsonRequest('GET', '/blacklist/' . $blacklistUuid, $this->rootToken());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertArrayNotHasKey('evidence_uuid', $body);
            $this->assertArrayNotHasKey('evidence', $body);
            $this->assertSame($submission->getReport()->getUuid(), $body['report_uuid'] ?? $body['report'] ?? null);
        }

        public function testDeletingTheReportKeepsTheBlacklistRecord(): void
        {
            $accountUuid = $this->createEntity('account');
            $domainUuid = $this->createEntity('advertised');
            $reportUuid = $this->createReportForEntity($accountUuid);
            $blacklistUuid = $this->blacklist($domainUuid, IncidentType::SPAM, $reportUuid);

            $this->client->deleteReport($reportUuid);
            $this->removeFromCleanup($this->createdReports, $reportUuid);

            $this->assertNull($this->client->getBlacklistRecord($blacklistUuid)->getReportUuid());
        }
    }
