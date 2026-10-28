<?php

    namespace FederationLib\Tests\Evidence;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ContentInput;

    /**
     * Regression tests of the confidentiality of evidence linked to ILLEGAL_CONTENT reports (always confidential), through
     * the server's HTTP interface, including competing requests
     */
    class EvidenceConfidentialityRegressionTest extends ServerTestCase
    {
        private FederationClient $limitedClient;

        protected function setUp(): void
        {
            parent::setUp();

            $this->assertTrue($this->client->getServerInformation()->isAllowIllegalContent(), 'The test environment must enable FEDERATION_ALLOW_ILLEGAL_CONTENT');
            $this->limitedClient = $this->createLimitedOperator('confidentiality', client: true);
        }

        private function report(string $entityUuid, IncidentType $type): string
        {
            $submission = $this->client->submitReport($entityUuid, new ContentInput('Synthetic confidentiality regression fixture'), $type);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            foreach ($submission->getEvidence() as $evidence)
            {
                $this->createdEvidenceRecords[] = $evidence->getUuid();
            }

            return $reportUuid;
        }

        private function assertProtected(string $evidenceUuid, string $reportUuid): void
        {
            // A new client performs a separate read after the writes have completed
            $record = new FederationClient(getenv('SERVER_ENDPOINT'), $this->rootToken())->getEvidenceRecord($evidenceUuid);
            $this->assertSame($reportUuid, $record->getReport());
            $this->assertTrue($record->isConfidential());

            [$status] = $this->jsonRequest('GET', '/evidence/' . $evidenceUuid, $this->limitedClient->getAccessToken());
            $this->assertSame(HttpResponseCode::FORBIDDEN->value, $status, 'Client-only operators must not see confidential evidence');
        }

        public function testLinkingPublicEvidenceMakesItConfidential(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $reportUuid = $this->report($entityUuid, IncidentType::ILLEGAL_CONTENT);
            $this->assertFalse($this->client->getEvidenceRecord($evidenceUuid)->isConfidential());

            $this->client->addEvidenceToReport($evidenceUuid, $reportUuid);
            $this->assertProtected($evidenceUuid, $reportUuid);
        }

        public function testDeclassificationBeforeLinkingCannotLeaveIllegalContentPublic(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, true);
            $ordinary = $this->report($entityUuid, IncidentType::OTHER);
            $illegal = $this->report($entityUuid, IncidentType::ILLEGAL_CONTENT);

            $this->client->addEvidenceToReport($evidenceUuid, $ordinary);
            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $this->assertFalse($this->client->getEvidenceRecord($evidenceUuid)->isConfidential());

            $this->client->addEvidenceToReport($evidenceUuid, $illegal);
            $this->assertProtected($evidenceUuid, $illegal);
        }

        public function testDeclassificationAfterLinkingIsRejectedAndPreservesConfidentiality(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $reportUuid = $this->report($entityUuid, IncidentType::ILLEGAL_CONTENT);
            $this->client->addEvidenceToReport($evidenceUuid, $reportUuid);

            $this->expectRequestFailure(fn() => $this->client->updateEvidenceConfidentiality($evidenceUuid, false), [HttpResponseCode::CONFLICT->value]);
            $this->assertProtected($evidenceUuid, $reportUuid);

            // Retaining confidentiality remains a valid operation
            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $this->assertProtected($evidenceUuid, $reportUuid);
        }

        public function testConcurrentLinkAndDeclassificationPreserveConfidentiality(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $ordinary = $this->report($entityUuid, IncidentType::OTHER);
            $illegal = $this->report($entityUuid, IncidentType::ILLEGAL_CONTENT);

            // The submission order alternates and the server decides which request acquires the lock first, the
            // declassification either succeeds before the link or conflicts after it; either way the evidence ends up
            // confidential
            for ($iteration = 0; $iteration < 6; $iteration++)
            {
                $evidenceUuid = $this->createSecurityEvidence($entityUuid, true);
                $this->client->addEvidenceToReport($evidenceUuid, $ordinary);

                $requests = [
                    'link' => ['method' => 'PATCH', 'path' => '/evidence/' . $evidenceUuid . '/link-report', 'token' => $this->rootToken(), 'body' => json_encode(['report_uuid' => $illegal])],
                    'declassify' => ['method' => 'PATCH', 'path' => '/evidence/' . $evidenceUuid . '/update-confidentiality', 'token' => $this->rootToken(), 'body' => json_encode(['confidential' => false])],
                ];
                if ($iteration % 2 !== 0)
                {
                    $requests = array_reverse($requests, true);
                }

                $results = $this->concurrentRawRequests($requests);
                $this->assertSame(200, $results['link'][0], $results['link'][1]);
                $this->assertContains($results['declassify'][0], [200, HttpResponseCode::CONFLICT->value], $results['declassify'][1]);
                $this->assertProtected($evidenceUuid, $illegal);
            }
        }

        public function testMalformedConfidentialityUpdatesCannotExposeEvidence(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity(), true);

            foreach (['{}', '{"confidential":null}', '{"confidential":"invalid"}', '{"confidential":[]}', '{"confidential":42}', 'null', '[]', '{'] as $body)
            {
                [$status, $response] = $this->rawRequest('PATCH', '/evidence/' . $evidenceUuid . '/update-confidentiality', $this->rootToken(), $body);
                $this->assertSame(HttpResponseCode::BAD_REQUEST->value, $status, $response);
                $this->assertTrue($this->client->getEvidenceRecord($evidenceUuid)->isConfidential(), $body);
            }
        }
    }
