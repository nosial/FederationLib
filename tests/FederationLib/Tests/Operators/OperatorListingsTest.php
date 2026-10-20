<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    /**
     * Client to server tests of the listings scoped to an operator, each operator acting through its own client
     */
    class OperatorListingsTest extends TestCase
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
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testListOperatorEvidenceOnlyReturnsOwnEvidence(): void
        {
            $firstClient = $this->createLimitedOperator('evidence_owner_a', client: true);
            $secondClient = $this->createLimitedOperator('evidence_owner_b', client: true);
            $firstUuid = $firstClient->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity();

            $firstEvidence = $this->createSecurityEvidence($entityUuid, client: $firstClient);
            $secondEvidence = $this->createSecurityEvidence($entityUuid, client: $secondClient);

            $evidenceRecords = $this->client->listOperatorEvidence($firstUuid);
            $evidenceUuids = array_map(fn($record) => $record->getUuid(), $evidenceRecords);

            $this->assertContains($firstEvidence, $evidenceUuids);
            $this->assertNotContains($secondEvidence, $evidenceUuids);
            foreach ($evidenceRecords as $record)
            {
                $this->assertEquals($firstUuid, $record->getOperatorUuid());
            }
        }

        public function testListOperatorEvidenceExcludesConfidentialByDefault(): void
        {
            $submitter = $this->createLimitedOperator('conf_submitter', client: true);
            $submitterUuid = $submitter->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity();

            $publicEvidence = $this->createSecurityEvidence($entityUuid, false, $submitter);
            $confidentialEvidence = $this->createSecurityEvidence($entityUuid, true, $submitter);

            $defaultUuids = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorEvidence($submitterUuid));
            $this->assertContains($publicEvidence, $defaultUuids);
            $this->assertNotContains($confidentialEvidence, $defaultUuids, 'Confidential evidence must not be listed unless requested');

            $includedRecords = $this->client->listOperatorEvidence($submitterUuid, includeConfidential: true);
            $includedUuids = array_map(fn($record) => $record->getUuid(), $includedRecords);
            $this->assertContains($publicEvidence, $includedUuids);
            $this->assertContains($confidentialEvidence, $includedUuids);

            foreach ($includedRecords as $record)
            {
                if ($record->getUuid() === $confidentialEvidence)
                {
                    $this->assertTrue($record->isConfidential());
                }
            }
        }

        public function testListOperatorEvidenceConfidentialRequiresManagement(): void
        {
            $submitter = $this->createLimitedOperator('confidential_owner', client: true);
            $submitterUuid = $submitter->getSelf()->getUuid();
            $this->createSecurityEvidence($this->createSecurityEntity(), true, $submitter);

            // Not even the submitter itself may list confidential evidence without management permissions
            $this->expectRequestFailure(
                fn() => $submitter->listOperatorEvidence($submitterUuid, includeConfidential: true),
                [HttpResponseCode::FORBIDDEN->value],
                'A client-only operator should not list confidential evidence'
            );

            $operatorOnly = $this->createLimitedOperator('conf_operator', operator: true);
            $this->expectRequestFailure(
                fn() => $operatorOnly->listOperatorEvidence($submitterUuid, includeConfidential: true),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator permissions do not grant access to confidential evidence'
            );

            $anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->expectRequestFailure(
                fn() => $anonymous->listOperatorEvidence($submitterUuid, includeConfidential: true),
                [HttpResponseCode::UNAUTHORIZED->value],
                'An anonymous client should not list confidential evidence'
            );

            $manager = $this->createLimitedOperator('conf_manager', management: true);
            $this->assertNotEmpty($manager->listOperatorEvidence($submitterUuid, includeConfidential: true));
        }

        public function testListOperatorEvidencePagesDoNotOverlap(): void
        {
            $submitter = $this->createLimitedOperator('evidence_pages', client: true);
            $submitterUuid = $submitter->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity();

            $submitted = [];
            for ($i = 0; $i < 5; $i++)
            {
                $submitted[] = $this->createSecurityEvidence($entityUuid, client: $submitter);
            }

            $firstPage = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorEvidence($submitterUuid, 1, 2));
            $secondPage = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorEvidence($submitterUuid, 2, 2));
            $thirdPage = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorEvidence($submitterUuid, 3, 2));
            $fourthPage = $this->client->listOperatorEvidence($submitterUuid, 4, 2);

            $this->assertCount(2, $firstPage);
            $this->assertCount(2, $secondPage);
            $this->assertCount(1, $thirdPage);
            $this->assertEmpty($fourthPage);

            $allPages = array_merge($firstPage, $secondPage, $thirdPage);
            $this->assertCount(5, array_unique($allPages), 'Pages must not repeat records');
            $this->assertEqualsCanonicalizing($submitted, $allPages);
        }

        public function testListOperatorBlacklistHidesLiftedUnlessRequested(): void
        {
            $manager = $this->createLimitedOperator('blacklist_lifted', management: true);
            $managerUuid = $manager->getSelf()->getUuid();

            $activeBlacklist = $this->createSecurityBlacklist($this->createSecurityEntity(), $manager);
            $liftedBlacklist = $this->createSecurityBlacklist($this->createSecurityEntity(), $manager);
            $manager->liftBlacklistRecord($liftedBlacklist);

            $defaultUuids = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorBlacklist($managerUuid));
            $this->assertContains($activeBlacklist, $defaultUuids);
            $this->assertNotContains($liftedBlacklist, $defaultUuids, 'Lifted blacklist records must not be listed unless requested');

            $includedRecords = $this->client->listOperatorBlacklist($managerUuid, includeLifted: true);
            $includedUuids = array_map(fn($record) => $record->getUuid(), $includedRecords);
            $this->assertContains($activeBlacklist, $includedUuids);
            $this->assertContains($liftedBlacklist, $includedUuids);

            foreach ($includedRecords as $record)
            {
                $this->assertEquals($managerUuid, $record->getOperatorUuid());
                $this->assertSame($record->getUuid() === $liftedBlacklist, $record->isLifted());
            }
        }

        public function testListOperatorBlacklistOnlyReturnsOwnRecords(): void
        {
            $firstManager = $this->createLimitedOperator('blacklist_owner_a', management: true);
            $secondManager = $this->createLimitedOperator('blacklist_owner_b', management: true);

            $firstBlacklist = $this->createSecurityBlacklist($this->createSecurityEntity(), $firstManager);
            $secondBlacklist = $this->createSecurityBlacklist($this->createSecurityEntity(), $secondManager);

            $firstUuids = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorBlacklist($firstManager->getSelf()->getUuid()));
            $this->assertContains($firstBlacklist, $firstUuids);
            $this->assertNotContains($secondBlacklist, $firstUuids);

            $secondUuids = array_map(fn($record) => $record->getUuid(), $this->client->listOperatorBlacklist($secondManager->getSelf()->getUuid()));
            $this->assertContains($secondBlacklist, $secondUuids);
            $this->assertNotContains($firstBlacklist, $secondUuids);
        }

        public function testListOperatorReportsFollowsReportLifecycle(): void
        {
            $reporter = $this->createLimitedOperator('report_lifecycle', client: true);
            $reporterUuid = $reporter->getSelf()->getUuid();
            $manager = $this->createLimitedOperator('lifecycle_mgr', management: true);
            $reportUuid = $this->createReportForEntity($this->createSecurityEntity(), $reporter);

            $submittedReports = $this->client->listOperatorReports($reporterUuid);
            $this->assertEquals([$reportUuid], array_map(fn($report) => $report->getUuid(), $submittedReports));
            $this->assertEquals($reporterUuid, $submittedReports[0]->getSubmittingOperator());
            $this->assertContains($reportUuid, array_map(fn($report) => $report->getUuid(), $this->client->listOperatorReports($reporterUuid, category: 'OPENED')));
            $this->assertEmpty($this->client->listOperatorReports($reporterUuid, category: 'CLOSED'));

            $manager->assignOperatorToReport($reportUuid, $manager->getSelf()->getUuid());
            $manager->closeReport($reportUuid);

            $this->assertEmpty($this->client->listOperatorReports($reporterUuid, category: 'OPENED'));
            $closedReports = $this->client->listOperatorReports($reporterUuid, category: 'CLOSED');
            $this->assertEquals([$reportUuid], array_map(fn($report) => $report->getUuid(), $closedReports));
            $this->assertFalse($closedReports[0]->isOpened());

            // Closing the report does not change who submitted it, the manager's own submissions stay empty
            $this->assertEmpty($this->client->listOperatorReports($manager->getSelf()->getUuid()));
        }

        public function testListAssignedOperatorReportsFollowsReassignment(): void
        {
            $firstManager = $this->createLimitedOperator('assigned_first', management: true);
            $secondManager = $this->createLimitedOperator('assigned_second', management: true);
            $firstUuid = $firstManager->getSelf()->getUuid();
            $secondUuid = $secondManager->getSelf()->getUuid();
            $reportUuid = $this->createReportForEntity($this->createSecurityEntity());

            $this->assertEmpty($this->client->listAssignedOperatorReports($firstUuid));

            $this->client->assignOperatorToReport($reportUuid, $firstUuid);
            $firstAssigned = $this->client->listAssignedOperatorReports($firstUuid);
            $this->assertEquals([$reportUuid], array_map(fn($report) => $report->getUuid(), $firstAssigned));
            $this->assertEquals($firstUuid, $firstAssigned[0]->getAssignedOperator());

            $this->client->assignOperatorToReport($reportUuid, $secondUuid);
            $this->assertEmpty($this->client->listAssignedOperatorReports($firstUuid), 'A reassigned report must leave the previous assignee');
            $this->assertEquals([$reportUuid], array_map(fn($report) => $report->getUuid(), $this->client->listAssignedOperatorReports($secondUuid)));

            // The assignee sees its own assignment through its own client
            $this->assertEquals([$reportUuid], array_map(fn($report) => $report->getUuid(), $secondManager->listAssignedOperatorReports($secondUuid)));
        }

        public function testOperatorListingsArePublicByDefault(): void
        {
            $manager = $this->createLimitedOperator('public_listings', management: true);
            $managerUuid = $manager->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $manager);
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid, $manager);
            $reportUuid = $this->createReportForEntity($entityUuid, $manager);
            $manager->assignOperatorToReport($reportUuid, $managerUuid);

            $anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->assertContains($evidenceUuid, array_map(fn($record) => $record->getUuid(), $anonymous->listOperatorEvidence($managerUuid)));
            $this->assertContains($blacklistUuid, array_map(fn($record) => $record->getUuid(), $anonymous->listOperatorBlacklist($managerUuid)));
            $this->assertContains($reportUuid, array_map(fn($report) => $report->getUuid(), $anonymous->listOperatorReports($managerUuid)));
            $this->assertContains($reportUuid, array_map(fn($report) => $report->getUuid(), $anonymous->listAssignedOperatorReports($managerUuid)));
        }

        public function testOperatorListingsOfNonExistentOperator(): void
        {
            $operatorUuid = Uuid::v7()->toRfc4122();
            $listings = [
                'listOperatorEvidence' => fn() => $this->client->listOperatorEvidence($operatorUuid),
                'listOperatorBlacklist' => fn() => $this->client->listOperatorBlacklist($operatorUuid),
                'listOperatorReports' => fn() => $this->client->listOperatorReports($operatorUuid),
                'listAssignedOperatorReports' => fn() => $this->client->listAssignedOperatorReports($operatorUuid),
                'listOperatorAuditLogs' => fn() => $this->client->listOperatorAuditLogs($operatorUuid),
            ];

            foreach ($listings as $method => $listing)
            {
                $this->expectRequestFailure($listing, [HttpResponseCode::NOT_FOUND->value], "$method should not list a non-existent operator");
            }
        }

        public function testOperatorListingsOfDeletedOperator(): void
        {
            $submitter = $this->createLimitedOperator('deleted_lister', client: true);
            $submitterUuid = $submitter->getSelf()->getUuid();
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity(), client: $submitter);

            $this->client->deleteOperator($submitterUuid);
            $this->createdOperators = array_values(array_diff($this->createdOperators, [$submitterUuid]));

            $this->expectRequestFailure(
                fn() => $this->client->getEvidenceRecord($evidenceUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'The evidence of a deleted operator should be deleted with it'
            );
            $this->expectRequestFailure(
                fn() => $this->client->listOperatorEvidence($submitterUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'A deleted operator should not be listable'
            );
        }

        public function testOperatorListingsRejectMalformedUuid(): void
        {
            foreach (['evidence', 'blacklist', 'reports', 'reports/assigned'] as $listing)
            {
                [$code] = $this->rawRequest('GET', 'operators/not-a-valid-uuid/' . $listing, getenv('SERVER_ACCESS_TOKEN'));
                $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::NOT_FOUND->value], "operators/{uuid}/$listing returned $code for a malformed UUID");
            }
        }

        public function testOperatorListingsRejectInvalidBooleanFilters(): void
        {
            $operatorUuid = $this->client->getSelf()->getUuid();

            [$evidenceCode] = $this->rawRequest('GET', 'operators/' . $operatorUuid . '/evidence?include_confidential=maybe', getenv('SERVER_ACCESS_TOKEN'));
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $evidenceCode);

            [$blacklistCode] = $this->rawRequest('GET', 'operators/' . $operatorUuid . '/blacklist?include_lifted=maybe', getenv('SERVER_ACCESS_TOKEN'));
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $blacklistCode);
        }

        public function testOperatorListingsValidateArgumentsClientSide(): void
        {
            $calls = [
                fn() => $this->client->listOperatorEvidence(''),
                fn() => $this->client->listOperatorEvidence(Uuid::v7()->toRfc4122(), 0),
                fn() => $this->client->listOperatorBlacklist(''),
                fn() => $this->client->listOperatorBlacklist(Uuid::v7()->toRfc4122(), 1, 0),
                fn() => $this->client->listOperatorReports(''),
                fn() => $this->client->listAssignedOperatorReports(''),
                fn() => $this->client->listAssignedOperatorReports(Uuid::v7()->toRfc4122(), 0),
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
    }
