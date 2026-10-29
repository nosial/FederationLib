<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Entities;

    use FederationLib\Enums\ContentType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\EntityRecord;

    /**
     * Telephone entities (<international number>@e164.arpa, OFD "Telephone Entities"): hidden from unauthenticated
     * requests that do not already know the number, and the evidence about them is always confidential. The server is
     * assumed to use its defaults: entities, entity queries and content scanning are public, telephone entities are not.
     */
    class TelephoneEntitiesTest extends ServerTestCase
    {
        private FederationClient $anonymous;

        protected function setUp(): void
        {
            parent::setUp();
            $this->anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        /**
         * Pushes a telephone entity with metadata
         *
         * @return array{0: string, 1: string} The entity UUID and its local part (the number)
         */
        private function createTelephoneEntity(): array
        {
            $number = $this->uniqueTelephoneNumber();
            $entityUuid = $this->client->pushEntity('e164.arpa', $number, ['carrier' => 'Test Carrier']);
            $this->createdEntities[] = $entityUuid;
            return [$entityUuid, $number];
        }

        public function testPushTelephoneEntity(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();

            $entity = $this->client->getEntityRecord($entityUuid);
            $this->assertSame('e164.arpa', $entity->getHost());
            $this->assertSame($number, $entity->getId());
            $this->assertTrue($entity->isTelephone());

            // The same number resolves to the same entity
            $this->assertSame($entityUuid, $this->client->pushEntity('e164.arpa', $number));
            $this->assertSame($entityUuid, $this->client->getEntityRecord($number . '@e164.arpa')->getUuid());
        }

        public function testInvalidTelephoneEntitiesAreRejected(): void
        {
            foreach(['+16502530000', '016502530000', '1650-253-0000', '1234567890123456', 'john'] as $id)
            {
                [$code, $response] = $this->rawRequest('POST', '/entities', $this->rootToken(), json_encode(['host' => 'e164.arpa', 'id' => $id]));
                $this->assertSame(400, $code, $id . ' ' . $response);
            }

            // The numbering namespace is not itself an entity
            [$code, $response] = $this->rawRequest('POST', '/entities', $this->rootToken(), json_encode(['host' => 'e164.arpa']));
            $this->assertSame(400, $code, $response);
        }

        public function testAnonymousCannotResolveTelephoneEntityByUuid(): void
        {
            [$entityUuid] = $this->createTelephoneEntity();

            [$code] = $this->rawRequest('GET', '/entities/' . $entityUuid);
            $this->assertSame(404, $code);

            [$code] = $this->rawRequest('GET', '/entities/' . $entityUuid . '/query');
            $this->assertSame(404, $code);

            // An authenticated operator can
            $this->assertSame($entityUuid, $this->client->getEntityRecord($entityUuid)->getUuid());
        }

        public function testAnonymousResolvesTelephoneEntityByNumberWithoutMetadata(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();

            [$code, $body] = $this->jsonRequest('GET', '/entities/' . $number . '@e164.arpa');
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame($entityUuid, $body['uuid']);
            $this->assertNull($body['metadata']);

            [$code, $body] = $this->jsonRequest('GET', '/entities/' . $number . '@e164.arpa/query');
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame($entityUuid, $body['entity_record']['uuid']);
            $this->assertNull($body['entity_record']['metadata']);

            // An authenticated operator sees the metadata
            $this->assertSame(['carrier' => 'Test Carrier'], $this->client->getEntityRecord($entityUuid)->getMetadata());
        }

        public function testAnonymousListingExcludesTelephoneEntities(): void
        {
            [$entityUuid] = $this->createTelephoneEntity();

            $anonymousEntities = $this->anonymous->listEntities(1, 100);
            $this->assertNotContains($entityUuid, array_map(fn(EntityRecord $entity) => $entity->getUuid(), $anonymousEntities));
            foreach($anonymousEntities as $entity)
            {
                $this->assertNotSame('e164.arpa', $entity->getHost());
            }

            $entities = $this->client->listEntities(1, 100);
            $this->assertContains($entityUuid, array_map(fn(EntityRecord $entity) => $entity->getUuid(), $entities));
        }

        public function testAnonymousSearchExcludesTelephoneEntities(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();

            [$code, $body] = $this->jsonRequest('GET', '/entities/search?q=' . substr($number, 0, 8));
            $this->assertSame(200, $code, json_encode($body));
            $this->assertNotContains($entityUuid, array_column($body, 'uuid'));

            $results = $this->client->searchEntities(substr($number, 0, 8), 1, 50);
            $this->assertContains($entityUuid, array_map(fn(EntityRecord $entity) => $entity->getUuid(), $results));
        }

        public function testAnonymousTopThreatsExcludeTelephoneEntities(): void
        {
            [$entityUuid] = $this->createTelephoneEntity();
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() + 3600);

            // The blacklisted telephone entity is a top threat, only operators see it as one
            $anonymousThreats = $this->anonymous->getTopThreats(100);
            $this->assertNotContains($entityUuid, array_map(fn(EntityRecord $entity) => $entity->getUuid(), $anonymousThreats));
            foreach($anonymousThreats as $threat)
            {
                $this->assertNotSame('e164.arpa', $threat->getHost());
            }

            $this->assertContains($entityUuid, array_map(fn(EntityRecord $entity) => $entity->getUuid(), $this->client->getTopThreats(100)));
        }

        public function testEvidenceAboutTelephoneEntityIsAlwaysConfidential(): void
        {
            [$entityUuid] = $this->createTelephoneEntity();

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'A private text message', null, null, false);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->assertTrue($this->client->getEvidenceRecord($evidenceUuid)->isConfidential());

            // Evidence is public by default, confidential evidence is not
            [$code] = $this->rawRequest('GET', '/evidence/' . $evidenceUuid);
            // OFD "Get Evidence Record": confidential evidence requires authentication
            $this->assertSame(401, $code);
        }

        public function testReportEvidenceAboutTelephoneEntityIsConfidential(): void
        {
            [$entityUuid] = $this->createTelephoneEntity();

            $submission = $this->client->submitReport($entityUuid, new ContentInput('Unsolicited text message', null, null, false, null, ContentType::SMS), IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();

            foreach($submission->getEvidence() as $evidence)
            {
                $this->createdEvidenceRecords[] = $evidence->getUuid();
                $this->assertTrue($this->client->getEvidenceRecord($evidence->getUuid())->isConfidential());
            }

            $this->assertNotEmpty($submission->getEvidence());
        }

        public function testAuditLogsNeverContainTheNumber(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();
            $this->client->updateEntity($entityUuid, ['carrier' => 'Another Carrier']);
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() + 3600);

            $logs = $this->client->listEntityAuditLogs($entityUuid, 1, 100);
            $this->assertNotEmpty($logs);
            foreach($logs as $log)
            {
                $this->assertStringNotContainsString($number, $log->getMessage());
            }
        }

        public function testScanResolvesTelephoneNumberInContent(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();

            [$code, $body] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => [
                'text_content' => 'Call me at ' . $this->formatTelephoneNumber($number),
            ]]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertContains($entityUuid, array_map(fn($resolved) => $resolved['entity']['uuid'], $body['resolved_entities']));
        }

        public function testScanResolvesNationalNumberWithSmsRegion(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();
            $national = sprintf('(%s) %s-%s', substr($number, 1, 3), substr($number, 4, 3), substr($number, 7));

            [$code, $text] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['text_content' => 'Reply to ' . $national]]);
            $this->assertSame(200, $code, json_encode($text));
            $this->assertNotContains($entityUuid, array_map(fn($resolved) => $resolved['entity']['uuid'], $text['resolved_entities']));

            [$code, $sms] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => [
                'text_content' => 'Reply to ' . $national, 'content_type' => 'SMS', 'context' => ['region' => 'US'],
            ]]);
            $this->assertSame(200, $code, json_encode($sms));
            $this->assertContains($entityUuid, array_map(fn($resolved) => $resolved['entity']['uuid'], $sms['resolved_entities']));
        }

        public function testAnonymousScanByNumberHidesMetadata(): void
        {
            [$entityUuid, $number] = $this->createTelephoneEntity();

            [$code, $body] = $this->jsonRequest('POST', '/scan', null, [
                'evidence' => ['text_content' => 'Hello', 'content_type' => 'SMS'],
                'author' => $number . '@e164.arpa',
            ]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame($entityUuid, $body['author_entity']['entity']['uuid']);
            $this->assertNull($body['author_entity']['entity']['metadata']);
        }

        public function testAnonymousScanByUuidDoesNotResolveTelephoneAuthor(): void
        {
            [$entityUuid] = $this->createTelephoneEntity();

            [$code, $body] = $this->jsonRequest('POST', '/scan', null, [
                'evidence' => ['text_content' => 'Hello'],
                'author' => $entityUuid,
            ]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertNull($body['author_entity']);
        }
    }
