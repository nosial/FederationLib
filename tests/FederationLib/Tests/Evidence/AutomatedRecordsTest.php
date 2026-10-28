<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Evidence;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\EvidenceRecord;

    /**
     * Automated records (OFD "Automated Records"): the evidence and reports submitted by bot operators are automated,
     * automated evidence is created confidential and a manager may make it non-confidential afterwards
     */
    class AutomatedRecordsTest extends ServerTestCase
    {
        private function createBot(): FederationClient
        {
            $bot = $this->createLimitedOperator('bot', client: true);
            $this->client->setBot($bot->getSelf()->getUuid(), true);
            return $bot;
        }

        public function testBotEvidenceIsAutomatedAndConfidential(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);

            $evidenceUuid = $bot->submitEvidence($entityUuid, 'Spam detected by the bot', null, null, false);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($evidence->isAutomated());
            $this->assertTrue($evidence->isConfidential());

            [$code, $body] = $this->jsonRequest('GET', '/evidence/' . $evidenceUuid, $this->rootToken());
            $this->assertSame(200, $code);
            $this->assertTrue($body['automated']);
            $this->assertTrue($body['confidential']);
        }

        public function testOrdinaryEvidenceIsNotAutomated(): void
        {
            $operator = $this->createLimitedOperator('not_bot', client: true);
            $entityUuid = $this->createSecurityEntity($operator);

            $evidenceUuid = $operator->submitEvidence($entityUuid, 'Submitted by a person', null, null, false);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($evidence->isAutomated());
            $this->assertFalse($evidence->isConfidential());
        }

        public function testClientCannotDeclareEvidenceAutomated(): void
        {
            $operator = $this->createLimitedOperator('declare', client: true);
            $entityUuid = $this->createSecurityEntity($operator);

            [$code, $body] = $this->jsonRequest('POST', '/evidence', $operator->getAccessToken(), [
                'entity_identifier' => $entityUuid,
                'text_content' => 'Not automated',
                'automated' => true,
            ]);

            if($code === 201)
            {
                // The member is ignored, only the host decides what is automated
                $this->createdEvidenceRecords[] = $body;
                $this->assertFalse($this->client->getEvidenceRecord($body)->isAutomated());
            }
            else
            {
                $this->assertSame(400, $code, json_encode($body));
            }
        }

        public function testManagerCanMakeAutomatedEvidenceNonConfidential(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);
            $evidenceUuid = $bot->submitEvidence($entityUuid, 'Reviewed by a manager');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $manager = $this->createLimitedOperator('reviewer', management: true);
            $manager->updateEvidenceConfidentiality($evidenceUuid, false);

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($evidence->isConfidential());
            // It remains automated
            $this->assertTrue($evidence->isAutomated());

            // Now visible without authentication, evidence is public by default
            [$code, $body] = $this->jsonRequest('GET', '/evidence/' . $evidenceUuid);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame($evidenceUuid, $body['uuid']);
        }

        public function testBotCannotMakeItsEvidenceNonConfidential(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);
            $evidenceUuid = $bot->submitEvidence($entityUuid, 'Bot evidence');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Changing the confidentiality requires management permissions
            $this->expectRequestFailure(fn() => $bot->updateEvidenceConfidentiality($evidenceUuid, false), [HttpResponseCode::FORBIDDEN->value]);
            $this->assertTrue($this->client->getEvidenceRecord($evidenceUuid)->isConfidential());
        }

        public function testBotReportAndItsEvidenceAreAutomated(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);

            $submission = $bot->submitReport($entityUuid, [new ContentInput('First message'), new ContentInput('Second message')], IncidentType::SPAM, 'Reported by the bot');
            $this->createdReports[] = $submission->getReport()->getUuid();
            foreach($submission->getEvidence() as $evidence)
            {
                $this->createdEvidenceRecords[] = $evidence->getUuid();
            }

            $this->assertTrue($this->client->getReport($submission->getReport()->getUuid())->isAutomated());
            $this->assertCount(2, $submission->getEvidence());
            foreach($submission->getEvidence() as $evidence)
            {
                $record = $this->client->getEvidenceRecord($evidence->getUuid());
                $this->assertTrue($record->isAutomated());
                $this->assertTrue($record->isConfidential());
            }
        }

        public function testOrdinaryReportIsNotAutomated(): void
        {
            $operator = $this->createLimitedOperator('reporter', client: true);
            $entityUuid = $this->createSecurityEntity($operator);

            $reportUuid = $this->createReportForEntity($entityUuid, $operator);
            $this->assertFalse($this->client->getReport($reportUuid)->isAutomated());
        }

        public function testBotFlagCanBeCleared(): void
        {
            $bot = $this->createBot();
            $botUuid = $bot->getSelf()->getUuid();
            $this->client->setBot($botUuid, false);

            $entityUuid = $this->createSecurityEntity($bot);
            $evidenceUuid = $bot->submitEvidence($entityUuid, 'No longer a bot');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->assertFalse($this->client->getEvidenceRecord($evidenceUuid)->isAutomated());
        }

        public function testAutomatedEvidenceCategory(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);
            $automatedUuid = $bot->submitEvidence($entityUuid, 'Automated evidence');
            $this->createdEvidenceRecords[] = $automatedUuid;
            $manualUuid = $this->client->submitEvidence($entityUuid, 'Manual evidence');
            $this->createdEvidenceRecords[] = $manualUuid;

            $automated = array_map(fn(EvidenceRecord $record) => $record->getUuid(), $this->client->listEvidence(1, 100, true, 'AUTOMATED'));
            $this->assertContains($automatedUuid, $automated);
            $this->assertNotContains($manualUuid, $automated);

            $notAutomated = array_map(fn(EvidenceRecord $record) => $record->getUuid(), $this->client->listEvidence(1, 100, true, 'NOT_AUTOMATED'));
            $this->assertContains($manualUuid, $notAutomated);
            $this->assertNotContains($automatedUuid, $notAutomated);
        }

        public function testAutomatedReportCategory(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);
            $automatedUuid = $this->createReportForEntity($entityUuid, $bot);
            $manualUuid = $this->createReportForEntity($entityUuid, $this->client);

            $automated = array_map(fn($report) => $report->getUuid(), $this->client->listReports(1, 100, 'AUTOMATED'));
            $this->assertContains($automatedUuid, $automated);
            $this->assertNotContains($manualUuid, $automated);
        }

        public function testOperatorEvidenceOfBotIsConfidentialToOthers(): void
        {
            $bot = $this->createBot();
            $entityUuid = $this->createSecurityEntity($bot);
            $evidenceUuid = $bot->submitEvidence($entityUuid, 'Hidden from clients');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Automated evidence is confidential: hidden from anonymous clients (401) and other clients (403)
            $client = $this->createLimitedOperator('other_client', client: true);
            $this->expectRequestFailure(fn() => $client->getEvidenceRecord($evidenceUuid), [HttpResponseCode::FORBIDDEN->value]);
            $this->expectRequestFailure(fn() => new FederationClient(getenv('SERVER_ENDPOINT'))->getEvidenceRecord($evidenceUuid), [HttpResponseCode::UNAUTHORIZED->value]);

            $manager = $this->createLimitedOperator('bot_manager', management: true);
            $this->assertTrue($manager->getEvidenceRecord($evidenceUuid)->isConfidential());
        }
    }
