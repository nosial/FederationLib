<?php

    namespace FederationLib\Tests\Reports;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;

    class ReportsTest extends ServerTestCase
    {
        /**
         * Submits a report about the entity and registers it and its evidence for cleanup
         */
        private function submit(string $entityUuid, string $content = 'Reported content', IncidentType $type = IncidentType::SPAM, ?FederationClient $client = null): string
        {
            $submission = ($client ?? $this->client)->submitReport($entityUuid, ['text_content' => $content], $type);
            $this->createdReports[] = $submission->getReport()->getUuid();
            foreach ($submission->getEvidence() as $evidence)
            {
                $this->createdEvidenceRecords[] = $evidence->getUuid();
            }

            return $submission->getReport()->getUuid();
        }

        /**
         * @param ReportRecord[] $reports
         * @return string[]
         */
        private static function uuids(array $reports): array
        {
            return array_map(fn(ReportRecord $report) => $report->getUuid(), $reports);
        }

        public function testSubmitReport(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $submission = $this->client->submitReport($entityUuid, new ContentInput('Report with a tagged evidence', null, 'evidence-tag'), IncidentType::SPAM, 'Report message');
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()[0]->getUuid();

            $report = $this->client->getReport($reportUuid);
            $this->assertEquals($entityUuid, $report->getReportingEntity());
            $this->assertEquals('Report message', $report->getMessage());
            $this->assertSame(IncidentType::SPAM, $report->getIncidentType());
            $this->assertTrue($report->isOpened());
            $this->assertFalse($report->isAutomated());
            $this->assertEquals($this->client->getSelf()->getUuid(), $report->getSubmittingOperator());
            $this->assertGreaterThan(0, $report->getCreated());

            // A single evidence item is returned as a list of one, linked to the report
            $this->assertCount(1, $submission->getEvidence());
            $evidence = $submission->getEvidence()[0];
            $this->assertEquals('Report with a tagged evidence', $evidence->getTextContent());
            $this->assertEquals('evidence-tag', $evidence->getTag());
            $this->assertEquals($reportUuid, $this->client->getEvidenceRecord($evidence->getUuid())->getReport());
        }

        public function testSubmitReportWithMultipleEvidence(): void
        {
            $evidenceItems = [
                new ContentInput('First evidence record', null, 'first'),
                new ContentInput('Second evidence record', null, 'second'),
                new ContentInput('Third evidence record', null, 'third'),
            ];

            $submission = $this->client->submitReport($this->createSecurityEntity(), $evidenceItems, IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();

            $this->assertCount(3, $submission->getEvidence());
            foreach ($submission->getEvidence() as $index => $evidence)
            {
                $this->createdEvidenceRecords[] = $evidence->getUuid();
                $this->assertEquals($evidenceItems[$index]->getTextContent(), $evidence->getTextContent());
                $this->assertEquals($evidenceItems[$index]->getTag(), $evidence->getTag());
            }
        }

        public function testClientValidatesArguments(): void
        {
            $uuid = $this->randomUuid();
            $calls = [
                fn() => $this->client->submitReport($uuid, [], IncidentType::SPAM),
                fn() => $this->client->submitReport('', ['text_content' => 'content'], IncidentType::OTHER),
                fn() => $this->client->submitReport($uuid, ['invalid_field' => 'value'], IncidentType::SPAM),
                fn() => $this->client->getReport(''),
                fn() => $this->client->listReports(1, 0),
                fn() => $this->client->listOperatorReports($uuid, 0, 10),
                fn() => $this->client->listOpenedReports(0, 10),
                fn() => $this->client->listOpenedReports(1, 0),
            ];

            foreach ($calls as $index => $call)
            {
                try
                {
                    $call();
                    $this->fail("Call $index should have been rejected before reaching the server");
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }

        public function testUnknownReportIsNotFound(): void
        {
            $uuid = $this->randomUuid();
            foreach ([
                'get' => fn() => $this->client->getReport($uuid),
                'close' => fn() => $this->client->closeReport($uuid),
                'delete' => fn() => $this->client->deleteReport($uuid),
            ] as $operation => $call)
            {
                $this->expectRequestFailure($call, [HttpResponseCode::NOT_FOUND->value], "The $operation of an unknown report should not be found");
            }
        }

        public function testCloseReport(): void
        {
            $reportUuid = $this->submit($this->createSecurityEntity());
            $this->client->assignOperatorToReport($reportUuid, $this->client->getSelf()->getUuid());

            $this->client->closeReport($reportUuid);

            $this->assertFalse($this->client->getReport($reportUuid)->isOpened());
            $this->assertContains($reportUuid, self::uuids($this->client->listReports(1, 100, 'CLOSED')));
            $this->assertNotContains($reportUuid, self::uuids($this->client->listReports(1, 100, 'OPENED')));
        }

        public function testCloseReportWithClassificationClassifiesItsEvidence(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $submission = $this->client->submitReport($entityUuid, ['text_content' => 'Report closed with a classification'], IncidentType::OTHER);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()[0]->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->assignOperatorToReport($reportUuid, $this->client->getSelf()->getUuid());
            $this->client->closeReport($reportUuid, ClassificationFlag::MALICIOUS);

            $this->assertFalse($this->client->getReport($reportUuid)->isOpened());
            $this->assertSame(ClassificationFlag::MALICIOUS, $this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());
        }

        public function testDeleteReport(): void
        {
            $reportUuid = $this->submit($this->createSecurityEntity());

            $this->client->deleteReport($reportUuid);
            $this->removeFromCleanup($this->createdReports, $reportUuid);

            $this->expectRequestFailure(fn() => $this->client->getReport($reportUuid), [HttpResponseCode::NOT_FOUND->value]);
        }

        public function testListReports(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [$this->submit($entityUuid), $this->submit($entityUuid), $this->submit($entityUuid)];

            $all = self::uuids($this->client->listReports(1, 100));
            $opened = self::uuids($this->client->listReports(1, 100, 'OPENED'));
            $closed = self::uuids($this->client->listReports(1, 100, 'CLOSED'));
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $all);
                $this->assertContains($uuid, $opened);
                $this->assertNotContains($uuid, $closed);
            }
        }

        public function testListOperatorAndEntityReports(): void
        {
            $operator = $this->createLimitedOperator('report_lister', client: true);
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();
            $reportA = $this->submit($entityA, client: $operator);
            $reportB = $this->submit($entityB, type: IncidentType::SCAM);

            $this->assertSame([$reportA], self::uuids($this->client->listEntityReports($entityA)));
            $this->assertSame([$reportB], self::uuids($this->client->listEntityReports($entityB)));
            $this->assertSame([$reportA], self::uuids($this->client->listOperatorReports($operator->getSelf()->getUuid())));

            $this->assertSame([$reportA], self::uuids($this->client->listEntityReports($entityA, 1, 10, 'OPENED')));
            $this->assertSame([], self::uuids($this->client->listEntityReports($entityA, 1, 10, 'CLOSED')));
        }

        public function testListEntityReportsPagination(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            for ($i = 0; $i < 5; $i++)
            {
                $created[] = $this->submit($entityUuid, "Paginated report $i");
            }

            $pages = [];
            for ($page = 1; $page <= 4; $page++)
            {
                $pages[] = self::uuids($this->client->listEntityReports($entityUuid, $page, 2));
            }

            $this->assertSame([2, 2, 1, 0], array_map('count', $pages));
            $this->assertEqualsCanonicalizing($created, array_merge(...$pages));
        }

        public function testListReportsSorting(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            foreach ([IncidentType::MALWARE, IncidentType::PHISHING, IncidentType::SPAM] as $type)
            {
                $created[] = $this->submit($entityUuid, "Report type $type->value", $type);
            }

            $descending = self::uuids($this->client->listReports(1, 100, null, 'created', 'DESC'));
            $this->assertSame(array_reverse($created), array_values(array_intersect($descending, $created)));

            $openedDescending = self::uuids($this->client->listReports(1, 100, 'OPENED', 'created', 'DESC'));
            $this->assertSame(array_reverse($created), array_values(array_intersect($openedDescending, $created)));

            $byType = $this->client->listEntityReports($entityUuid, 1, 100, null, 'incident_type', 'ASC');
            $this->assertSame([IncidentType::SPAM, IncidentType::MALWARE, IncidentType::PHISHING], array_map(fn(ReportRecord $report) => $report->getIncidentType(), $byType));
        }

        public function testListReportsCategoryAssigned(): void
        {
            $manager = $this->createLimitedOperator('rep_cat_asgn_mgr', management: true);
            $assignedUuid = $this->submit($this->createSecurityEntity());
            $unassignedUuid = $this->submit($this->createSecurityEntity());
            $manager->assignOperatorToReport($assignedUuid, $manager->getSelf()->getUuid());

            $assigned = self::uuids($this->client->listReports(1, 100, 'ASSIGNED'));
            $this->assertContains($assignedUuid, $assigned);
            $this->assertNotContains($unassignedUuid, $assigned);
        }

        public function testListReportsCategoryFallbackAndCase(): void
        {
            $this->submit($this->createSecurityEntity());

            $default = self::uuids($this->client->listReports(1, 10));
            $this->assertNotEmpty($default);
            $this->assertSame($default, self::uuids($this->client->listReports(1, 10, 'BOGUS_CATEGORY')));

            $upper = self::uuids($this->client->listReports(1, 10, 'OPENED'));
            $this->assertNotEmpty($upper);
            foreach (['opened', 'Opened'] as $category)
            {
                $this->assertSame($upper, self::uuids($this->client->listReports(1, 10, $category)), $category);
            }
        }

        public function testListOpenedReportsAsUnauthenticated(): void
        {
            [$code] = $this->rawRequest('GET', 'reports/opened');
            $this->assertEquals(HttpResponseCode::UNAUTHORIZED->value, $code);
        }

        public function testListOpenedReportsListsOnlyTheOperatorsOpenedAssignments(): void
        {
            $managerA = $this->createLimitedOperator('list_own_a', management: true);
            $managerB = $this->createLimitedOperator('list_own_b', management: true);
            $selfA = $managerA->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity();

            $opened = $this->submit($entityUuid, client: $managerA);
            $managerA->assignOperatorToReport($opened, $selfA);

            $closed = $this->submit($entityUuid, client: $managerA);
            $managerA->assignOperatorToReport($closed, $selfA);
            $managerA->closeReport($closed);

            $unassigned = $this->submit($entityUuid);

            $ofOther = $this->submit($entityUuid, client: $managerA);
            $managerA->assignOperatorToReport($ofOther, $managerB->getSelf()->getUuid());

            $reportsA = $managerA->listOpenedReports(1, 100);
            $this->assertSame([$opened], self::uuids($reportsA));
            $this->assertTrue($reportsA[0]->isOpened());

            $this->assertSame([$ofOther], self::uuids($managerB->listOpenedReports(1, 100)));
            $this->assertNotContains($unassigned, self::uuids($this->client->listOpenedReports(1, 100)));
        }

        public function testListOpenedReportsPaginationAndSort(): void
        {
            $manager = $this->createLimitedOperator('list_opened_pg', management: true);
            $selfUuid = $manager->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity();

            $created = [];
            for ($i = 0; $i < 5; $i++)
            {
                $reportUuid = $this->submit($entityUuid, "Opened paginated report $i", IncidentType::OTHER, $manager);
                $manager->assignOperatorToReport($reportUuid, $selfUuid);
                $created[] = $reportUuid;
            }

            $pages = [];
            for ($page = 1; $page <= 3; $page++)
            {
                $pages[] = self::uuids($manager->listOpenedReports($page, 2));
            }
            $this->assertSame([2, 2, 1], array_map('count', $pages));
            $this->assertEqualsCanonicalizing($created, array_merge(...$pages));

            $this->assertSame(array_reverse($created), self::uuids($manager->listOpenedReports(1, 100, 'created', 'DESC')));
        }
    }
