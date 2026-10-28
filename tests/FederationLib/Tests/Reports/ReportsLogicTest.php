<?php

    namespace FederationLib\Tests\Reports;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\BlacklistRecord;

    class ReportsLogicTest extends ServerTestCase
    {
        /**
         * Submits a report for the entity, closes it with the classification flag and returns the report UUID
         */
        private function submitAndCloseReport(string $entityUuid, string $content, ClassificationFlag $classificationFlag): string
        {
            $submission = $this->client->submitReport($entityUuid, ['text_content' => $content], IncidentType::OTHER);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()[0]->getUuid();

            $this->client->assignOperatorToReport($reportUuid, $this->client->getSelf()->getUuid());
            $this->client->closeReport($reportUuid, $classificationFlag);
            return $reportUuid;
        }

        /**
         * Closes the report through the HTTP interface with the given members
         *
         * @return array{0: int, 1: string}
         */
        private function closeWith(string $reportUuid, string $token, array $members): array
        {
            return $this->rawRequest('PATCH', 'reports/' . $reportUuid . '/close', $token, json_encode($members));
        }

        public function testReportFullLifecycleWorkflow(): void
        {
            $submitter = $this->createLimitedOperator('lifecycle_submitter', client: true);
            $manager = $this->createLimitedOperator('lifecycle_manager', management: true, operator: true);
            $managerUuid = $manager->getSelf()->getUuid();
            $this->client->setAutoAssign($managerUuid, true);

            $entityUuid = $this->createSecurityEntity($submitter);
            $reportUuid = $this->createReportForEntity($entityUuid, $submitter, 'Full lifecycle report');

            $report = $this->client->getReport($reportUuid);
            $this->assertTrue($report->isOpened());
            $this->assertEquals($managerUuid, $report->getAssignedOperator(), 'Report should be assigned to an eligible auto-assign operator');

            $standaloneEvidenceUuid = $this->createSecurityEvidence($entityUuid, false, $submitter);
            $manager->addEvidenceToReport($standaloneEvidenceUuid, $reportUuid);
            $this->assertEquals($reportUuid, $this->client->getEvidenceRecord($standaloneEvidenceUuid)->getReport());

            $manager->closeReport($reportUuid, ClassificationFlag::SUSPICIOUS);
            $this->assertFalse($this->client->getReport($reportUuid)->isOpened());
            $this->assertSame(ClassificationFlag::SUSPICIOUS, $this->client->getEvidenceRecord($standaloneEvidenceUuid)->getClassificationFlag());

            $this->expectRequestFailure(
                fn() => $manager->closeReport($reportUuid),
                [HttpResponseCode::BAD_REQUEST->value],
                'Closing an already-closed report should fail'
            );
        }

        public function testReportAssignmentTransferBetweenManagers(): void
        {
            $firstManager = $this->createLimitedOperator('first_transfer_manager', management: true);
            $secondManager = $this->createLimitedOperator('second_transfer_manager', management: true);
            $report = $this->createSecurityReport();

            $firstManager->assignOperatorToReport($report['report'], $firstManager->getSelf()->getUuid());
            $this->assertEquals($firstManager->getSelf()->getUuid(), $this->client->getReport($report['report'])->getAssignedOperator());

            $secondManager->assignOperatorToReport($report['report'], $secondManager->getSelf()->getUuid());
            $this->assertEquals($secondManager->getSelf()->getUuid(), $this->client->getReport($report['report'])->getAssignedOperator());
        }

        public function testDeletingAReportKeepsItsEvidence(): void
        {
            $report = $this->createSecurityReport();
            $extraEvidenceUuid = $this->createSecurityEvidence($report['entity']);
            $this->client->addEvidenceToReport($extraEvidenceUuid, $report['report']);

            $this->client->deleteReport($report['report']);
            $this->removeFromCleanup($this->createdReports, $report['report']);

            $this->expectRequestFailure(fn() => $this->client->getReport($report['report']), [HttpResponseCode::NOT_FOUND->value], 'Deleted report should not be retrievable');

            // The evidence is kept and no longer linked to a report
            foreach ([$report['evidence'], $extraEvidenceUuid] as $evidenceUuid)
            {
                $this->assertNull($this->client->getEvidenceRecord($evidenceUuid)->getReport());
            }
        }

        public function testCloseReportWithBlacklistAction(): void
        {
            $manager = $this->createLimitedOperator('close_blacklist_manager', management: true);
            $managerUuid = $manager->getSelf()->getUuid();
            $expires = time() + 7200;

            $cases = [
                'permanent' => [['blacklist_incident_type' => IncidentType::SPAM->value], null],
                'temporary' => [['classification_flag' => ClassificationFlag::MALICIOUS->value, 'blacklist_incident_type' => IncidentType::SPAM->value, 'blacklist_expires' => $expires], $expires],
            ];

            foreach ($cases as $label => [$members, $expectedExpires])
            {
                $report = $this->createSecurityReport();
                $manager->assignOperatorToReport($report['report'], $managerUuid);

                [$code, $response] = $this->closeWith($report['report'], $manager->getAccessToken(), $members);
                $this->assertEquals(HttpResponseCode::OK->value, $code, "Closing with a $label blacklist action failed: $response");
                $this->assertFalse($this->client->getReport($report['report'])->isOpened());

                // Exactly one blacklist record, referencing the report
                $records = $this->client->listEntityBlacklistRecords($report['entity'], 1, 100, true);
                foreach ($records as $record)
                {
                    $this->createdBlacklistRecords[] = $record->getUuid();
                }

                $this->assertCount(1, $records, $label);
                $this->assertEquals($report['report'], $records[0]->getReportUuid());
                $this->assertEquals($managerUuid, $records[0]->getOperatorUuid());
                $this->assertSame(IncidentType::SPAM, $records[0]->getType());
                $this->assertSame($expectedExpires, $records[0]->getExpires());
                $this->assertFalse($records[0]->isLifted());
            }
        }

        public function testCloseReportRejectsInvalidBlacklistAction(): void
        {
            $manager = $this->createLimitedOperator('close_invalid_bl_manager', management: true);

            foreach ([
                'an unknown incident type' => ['blacklist_incident_type' => 'NOT_A_REAL_TYPE'],
                'an expiration in the past' => ['blacklist_incident_type' => IncidentType::SPAM->value, 'blacklist_expires' => time() - 3600],
            ] as $label => $blacklist)
            {
                $report = $this->createSecurityReport();
                $manager->assignOperatorToReport($report['report'], $manager->getSelf()->getUuid());

                [$code, $response] = $this->closeWith($report['report'], $manager->getAccessToken(), ['classification_flag' => ClassificationFlag::MALICIOUS->value] + $blacklist);
                $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code, "A blacklist action with $label should be rejected: $response");

                // Nothing is applied
                $this->assertTrue($this->client->getReport($report['report'])->isOpened());
                $this->assertSame([], array_map(fn(BlacklistRecord $record) => $record->getUuid(), $this->client->listEntityBlacklistRecords($report['entity'], 1, 100, true)));
            }
        }

        public function testIllegalContentReportEvidenceIsAlwaysConfidential(): void
        {
            $this->assertTrue($this->client->getServerInformation()->isAllowIllegalContent(), 'The test environment must enable FEDERATION_ALLOW_ILLEGAL_CONTENT');

            $submission = $this->client->submitReport($this->createSecurityEntity(), [
                ['text_content' => 'Illegal content evidence, not flagged'],
                ['text_content' => 'Illegal content evidence, explicitly public', 'confidential' => false],
            ], IncidentType::ILLEGAL_CONTENT);
            $this->createdReports[] = $submission->getReport()->getUuid();

            $this->assertCount(2, $submission->getEvidence());
            foreach ($submission->getEvidence() as $evidenceRecord)
            {
                $this->createdEvidenceRecords[] = $evidenceRecord->getUuid();
                $this->assertTrue($evidenceRecord->isConfidential(), 'Evidence of an ILLEGAL_CONTENT report must be confidential');
                $this->assertTrue($this->client->getEvidenceRecord($evidenceRecord->getUuid())->isConfidential());
            }
        }

        public function testOtherReportsRespectTheConfidentialFlag(): void
        {
            $submission = $this->client->submitReport($this->createSecurityEntity(), [
                ['text_content' => 'Spam evidence'],
                ['text_content' => 'Confidential spam evidence', 'confidential' => true],
            ], IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();

            [$public, $confidential] = $submission->getEvidence();
            $this->createdEvidenceRecords[] = $public->getUuid();
            $this->createdEvidenceRecords[] = $confidential->getUuid();

            $this->assertFalse($this->client->getEvidenceRecord($public->getUuid())->isConfidential());
            $this->assertTrue($this->client->getEvidenceRecord($confidential->getUuid())->isConfidential());
        }

        public function testClosingReportAdjustsReportedEntityReputationByClassification(): void
        {
            // Default configuration: NORMAL +1, SUSPICIOUS -10, MALICIOUS -20
            $expectedDeltas = [
                ClassificationFlag::NORMAL->value => 1,
                ClassificationFlag::SUSPICIOUS->value => -10,
                ClassificationFlag::MALICIOUS->value => -20,
            ];

            foreach ($expectedDeltas as $flag => $expectedDelta)
            {
                $entityUuid = $this->createSecurityEntity();
                $before = $this->client->getEntityRecord($entityUuid)->getReputation();
                $this->submitAndCloseReport($entityUuid, 'Reputation delta test for ' . $flag, ClassificationFlag::from($flag));
                $this->assertEquals($expectedDelta, $this->client->getEntityRecord($entityUuid)->getReputation() - $before, 'Unexpected reputation delta for ' . $flag);
            }
        }

        public function testClosingReportAdjustsMentionedEntityReputation(): void
        {
            $reportedUuid = $this->createSecurityEntity();
            $mentionedHost = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->client->pushEntity($mentionedHost);
            $this->createdEntities[] = $mentionedUuid;

            $reportedBefore = $this->client->getEntityRecord($reportedUuid)->getReputation();
            $mentionedBefore = $this->client->getEntityRecord($mentionedUuid)->getReputation();

            $this->submitAndCloseReport($reportedUuid, "Claim your prize at https://$mentionedHost/login now", ClassificationFlag::MALICIOUS);

            $this->assertEquals(-20, $this->client->getEntityRecord($reportedUuid)->getReputation() - $reportedBefore);
            // Default configuration: mentioned entities receive MALICIOUS -10
            $this->assertEquals(-10, $this->client->getEntityRecord($mentionedUuid)->getReputation() - $mentionedBefore, 'Entities mentioned in the evidence must be affected');
        }

        public function testClosingReportAdjustsReportedEntityOnlyOnceWhenSelfMentioned(): void
        {
            $host = $this->uniqueHost('self-mention');
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;
            $before = $this->client->getEntityRecord($entityUuid)->getReputation();

            $this->submitAndCloseReport($entityUuid, "Visit https://$host and $host for more", ClassificationFlag::SUSPICIOUS);

            $this->assertEquals(-10, $this->client->getEntityRecord($entityUuid)->getReputation() - $before);
        }

        public function testClosingReportDoesNotAffectWhitelistedReportedEntityReputation(): void
        {
            foreach (ClassificationFlag::cases() as $classificationFlag)
            {
                $entityUuid = $this->createSecurityEntity();
                $this->client->setEntityWhitelist($entityUuid, true);
                $before = $this->client->getEntityRecord($entityUuid)->getReputation();

                $this->submitAndCloseReport($entityUuid, 'Whitelisted reputation test for ' . $classificationFlag->value, $classificationFlag);

                $this->assertEquals($before, $this->client->getEntityRecord($entityUuid)->getReputation(), 'Whitelisted entity reputation must not change for ' . $classificationFlag->value);
            }
        }

        public function testClosingReportDoesNotAffectWhitelistedMentionedEntityReputation(): void
        {
            $reportedUuid = $this->createSecurityEntity();
            $mentionedHost = $this->uniqueHost('mentioned-whitelisted');
            $mentionedUuid = $this->client->pushEntity($mentionedHost);
            $this->createdEntities[] = $mentionedUuid;
            $this->client->setEntityWhitelist($mentionedUuid, true);

            $reportedBefore = $this->client->getEntityRecord($reportedUuid)->getReputation();
            $mentionedBefore = $this->client->getEntityRecord($mentionedUuid)->getReputation();

            $this->submitAndCloseReport($reportedUuid, "Claim your prize at https://$mentionedHost/login now", ClassificationFlag::MALICIOUS);

            $this->assertEquals(-20, $this->client->getEntityRecord($reportedUuid)->getReputation() - $reportedBefore, 'Non-whitelisted reported entity must still be affected');
            $this->assertEquals($mentionedBefore, $this->client->getEntityRecord($mentionedUuid)->getReputation(), 'Whitelisted mentioned entity reputation must not change');
        }
    }
