<?php

    namespace FederationLib\Tests\Reports;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\EvidenceRecord;
    use InvalidArgumentException;

    /**
     * The evidence linked to a report (List Report Evidence)
     */
    class ReportsEvidenceTest extends ServerTestCase
    {
        /**
         * @param EvidenceRecord[] $records
         * @return string[]
         */
        private static function uuids(array $records): array
        {
            return array_map(fn(EvidenceRecord $record) => $record->getUuid(), $records);
        }

        /**
         * Submits evidence about the entity and links it to the report
         */
        private function linkEvidence(string $entityUuid, string $reportUuid, bool $confidential = false): string
        {
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, $confidential);
            $this->client->addEvidenceToReport($evidenceUuid, $reportUuid);
            return $evidenceUuid;
        }

        public function testListReportEvidenceReturnsOnlyLinkedEvidence(): void
        {
            $report = $this->createSecurityReport();
            $linkedUuid = $this->linkEvidence($report['entity'], $report['report']);
            $unlinkedUuid = $this->createSecurityEvidence($report['entity']);

            $records = $this->client->listReportEvidenceRecords($report['report']);
            $this->assertEqualsCanonicalizing([$report['evidence'], $linkedUuid], self::uuids($records));
            $this->assertNotContains($unlinkedUuid, self::uuids($records));
            foreach ($records as $record)
            {
                $this->assertEquals($report['report'], $record->getReport());
            }

            // Deleted evidence is no longer listed
            foreach ([$report['evidence'], $linkedUuid] as $evidenceUuid)
            {
                $this->client->deleteEvidence($evidenceUuid);
                $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid);
            }
            $this->assertSame([], $this->client->listReportEvidenceRecords($report['report']));
        }

        public function testListReportEvidencePagination(): void
        {
            $report = $this->createSecurityReport();
            $created = [$report['evidence']];
            for ($i = 0; $i < 4; $i++)
            {
                $created[] = $this->linkEvidence($report['entity'], $report['report']);
            }

            $pages = [];
            for ($page = 1; $page <= 3; $page++)
            {
                $pages[] = self::uuids($this->client->listReportEvidenceRecords($report['report'], $page, 2));
            }

            $this->assertSame([2, 2, 1], array_map('count', $pages));
            $this->assertEqualsCanonicalizing($created, array_merge(...$pages));
        }

        public function testListReportEvidenceSortByCreatedAscending(): void
        {
            $report = $this->createSecurityReport();
            $created = [$report['evidence'], $this->linkEvidence($report['entity'], $report['report']), $this->linkEvidence($report['entity'], $report['report'])];

            $this->assertSame($created, self::uuids($this->client->listReportEvidenceRecords($report['report'], 1, 100, false, null, 'created', 'ASC')));
        }

        public function testConfidentialReportEvidenceRequiresManagementPermissions(): void
        {
            $report = $this->createSecurityReport();
            $confidentialUuid = $this->linkEvidence($report['entity'], $report['report'], true);

            $clientOnly = $this->createLimitedOperator('rep_ev_conf_client', client: true);
            $this->expectRequestFailure(
                fn() => $clientOnly->listReportEvidenceRecords($report['report'], 1, 100, true),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not include confidential evidence'
            );
            $this->assertSame([$report['evidence']], self::uuids($clientOnly->listReportEvidenceRecords($report['report'])));

            $manager = $this->createLimitedOperator('rep_ev_conf_mgr', management: true);
            $this->assertEqualsCanonicalizing([$report['evidence'], $confidentialUuid], self::uuids($manager->listReportEvidenceRecords($report['report'], 1, 100, true)));
        }

        public function testListReportEvidenceByConfidentialityCategory(): void
        {
            $report = $this->createSecurityReport();
            $confidentialUuid = $this->linkEvidence($report['entity'], $report['report'], true);

            $this->assertSame([$report['evidence']], self::uuids($this->client->listReportEvidenceRecords($report['report'], 1, 100, true, 'NOT_CONFIDENTIAL')));
            $this->assertSame([$confidentialUuid], self::uuids($this->client->listReportEvidenceRecords($report['report'], 1, 100, true, 'CONFIDENTIAL')));
        }

        public function testInvalidRequests(): void
        {
            // A path with a malformed UUID matches no method
            [$code, $body] = $this->rawRequest('GET', 'reports/1b4e28ba2f1c4d6e8a0f3c5d7e9b1a2c3d4e/evidence', $this->rootToken());
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code, $body);

            $this->expectRequestFailure(
                fn() => $this->client->listReportEvidenceRecords($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'The evidence of an unknown report should not be found'
            );

            $this->expectException(InvalidArgumentException::class);
            $this->client->listReportEvidenceRecords($this->randomUuid(), 0, 10);
        }
    }
