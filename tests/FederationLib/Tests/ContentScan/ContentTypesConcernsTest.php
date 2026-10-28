<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\ContentScan;

    use FederationLib\Enums\ContentType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    /**
     * Content types, concerns and detections of Scan Content and Query Entity (OFD 2.0). The scan responses are read as
     * the JSON the server returned, so that the server's own risk score and suggested action are asserted.
     */
    class ContentTypesConcernsTest extends TestCase
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

        /**
         * Scans content as the root operator and returns the status code and decoded response
         *
         * @return array{0: int, 1: mixed}
         */
        private function scan(array $body): array
        {
            return $this->jsonRequest('POST', '/scan', $this->rootToken(), $body);
        }

        /**
         * Creates a host entity blacklisted for the given incident type
         *
         * @return string The entity UUID
         */
        private function createBlacklistedHost(string $host, IncidentType $type): string
        {
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($entityUuid, null, $type, time() + 3600);
            return $entityUuid;
        }

        public function testScanEachContentType(): void
        {
            $items = [
                ['text_content' => 'Hello there', 'content_type' => 'TEXT'],
                ['text_content' => 'Message body', 'content_type' => 'EMAIL', 'context' => ['subject' => 'Hello', 'sender' => 'someone@example.invalid']],
                ['text_content' => 'Your code is 123', 'content_type' => 'SMS', 'context' => ['region' => 'US', 'sender_id' => 'SHOP']],
                ['text_content' => 'Page text', 'content_type' => 'WEB_PAGE', 'context' => ['url' => 'https://example.invalid/page', 'title' => 'Title']],
                ['text_content' => 'Ads.Example.INVALID.', 'content_type' => 'HOSTNAME', 'context' => ['query_type' => 'AAAA']],
                ['text_content' => 'https://example.invalid/landing?x=1', 'content_type' => 'URL'],
            ];

            foreach($items as $item)
            {
                [$code, $body] = $this->scan(['evidence' => $item]);
                $this->assertSame(200, $code, json_encode($item) . ' ' . json_encode($body));
                $this->assertArrayHasKey('detections', $body);
                $this->assertIsArray($body['detections']);
                $this->assertArrayHasKey('risk_score', $body);
            }
        }

        public function testScanMultipleContentTypesAtOnce(): void
        {
            [$code, $body] = $this->scan(['evidence' => [
                ['text_content' => 'ads.example.invalid', 'content_type' => 'HOSTNAME'],
                ['text_content' => 'Hello'],
            ]]);

            $this->assertSame(200, $code, json_encode($body));
        }

        public function testScanContentTypeIsCaseInsensitive(): void
        {
            [$code] = $this->scan(['evidence' => ['text_content' => 'example.invalid', 'content_type' => 'hostname']]);
            $this->assertSame(200, $code);
        }

        public function testScanRejectsInvalidContent(): void
        {
            $cases = [
                ['text_content' => 'Hello', 'content_type' => 'VIDEO'],
                ['text_content' => 'not a hostname', 'content_type' => 'HOSTNAME'],
                ['text_content' => '192.0.2.1', 'content_type' => 'HOSTNAME'],
                ['text_content' => 'example.invalid/path', 'content_type' => 'URL'],
                ['text_content' => 'Page text', 'content_type' => 'WEB_PAGE'],
                ['text_content' => 'Page text', 'content_type' => 'WEB_PAGE', 'context' => ['url' => 'ftp://example.invalid/']],
                ['text_content' => 'Body', 'content_type' => 'SMS', 'context' => ['region' => 'USA']],
                ['text_content' => 'Body', 'content_type' => 'EMAIL', 'context' => ['region' => 'US']],
                ['text_content' => 'Body', 'context' => ['subject' => 'Not for TEXT']],
                ['text_content' => 'Body', 'content_type' => 'EMAIL', 'context' => ['subject' => '']],
                ['text_content' => 'Body', 'content_type' => 'EMAIL', 'context' => 'not an object'],
                ['text_content' => 'Body', 'automated' => true],
            ];

            foreach($cases as $item)
            {
                [$code, $response] = $this->rawRequest('POST', '/scan', $this->rootToken(), json_encode(['evidence' => $item]));
                $this->assertSame(400, $code, json_encode($item) . ' ' . $response);
            }
        }

        public function testScanReportsTheIndexOfInvalidContent(): void
        {
            [$code, $body] = $this->scan(['evidence' => [
                ['text_content' => 'Hello'],
                ['text_content' => 'not a hostname', 'content_type' => 'HOSTNAME'],
            ]]);

            $this->assertSame(400, $code);
            $this->assertStringContainsString('index 1', json_encode($body));
        }

        public function testScanRejectsInvalidConcerns(): void
        {
            $cases = [
                ['concerns' => []],
                ['concerns' => ['BOGUS']],
                ['concerns' => ['SPAM', 'BOGUS']],
                ['concerns' => 'SPAM'],
                ['concerns' => [5]],
            ];

            foreach($cases as $parameters)
            {
                [$code, $response] = $this->rawRequest('POST', '/scan', $this->rootToken(), json_encode(['evidence' => ['text_content' => 'Hello']] + $parameters));
                $this->assertSame(400, $code, json_encode($parameters) . ' ' . $response);
            }
        }

        public function testScanAcceptsConcernsCaseInsensitively(): void
        {
            [$code, $body] = $this->scan(['evidence' => ['text_content' => 'Hello'], 'concerns' => ['spam', 'Advertising']]);
            $this->assertSame(200, $code, json_encode($body));
        }

        public function testBlacklistDetectionOnlyForRequestedConcerns(): void
        {
            $host = $this->uniqueHost('ads');
            $entityUuid = $this->createBlacklistedHost($host, IncidentType::ADVERTISING);

            // ADVERTISING is not a default concern, the blacklist record neither detects nor affects the result
            [$code, $default] = $this->scan(['evidence' => ['text_content' => $host, 'content_type' => 'HOSTNAME']]);
            $this->assertSame(200, $code, json_encode($default));
            $this->assertSame([], $default['detections']);
            $this->assertNull($default['suggested_action']);

            [$code, $requested] = $this->scan(['evidence' => ['text_content' => $host, 'content_type' => 'HOSTNAME'], 'concerns' => ['ADVERTISING']]);
            $this->assertSame(200, $code, json_encode($requested));
            $this->assertCount(1, $requested['detections']);
            $this->assertSame([
                'concern' => 'ADVERTISING',
                'content_index' => 0,
                'file_index' => null,
                'entity' => $entityUuid,
                'source' => 'blacklist',
                'confidence' => null,
                'verified' => true,
            ], $requested['detections'][0]);
            // The blacklist record of an effective concern contributes to the risk score
            $this->assertNotEquals($default['risk_score'], $requested['risk_score']);

            // The entity is resolved either way, only its effect depends on the concerns
            $this->assertContains($entityUuid, array_map(fn($resolved) => $resolved['entity']['uuid'], $default['resolved_entities']));
        }

        public function testSpamOnlyServiceIsNotAffectedByAdvertising(): void
        {
            $host = $this->uniqueHost('ads');
            $this->createBlacklistedHost($host, IncidentType::ADVERTISING);

            [$code, $body] = $this->scan(['evidence' => ['text_content' => 'Check out ' . $host . ' today'], 'concerns' => ['SPAM']]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame([], $body['detections']);
            $this->assertNull($body['suggested_action']);
        }

        public function testDefaultConcernsDetectSpamBlacklist(): void
        {
            $host = $this->uniqueHost('spam');
            $entityUuid = $this->createBlacklistedHost($host, IncidentType::SPAM);

            [$code, $body] = $this->scan(['evidence' => ['text_content' => 'Visit ' . $host . ' now']]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['SPAM'], array_column($body['detections'], 'concern'));
            $this->assertSame($entityUuid, $body['detections'][0]['entity']);
            $this->assertTrue($body['detections'][0]['verified']);
        }

        public function testOtherBlacklistIsAConcern(): void
        {
            $host = $this->uniqueHost('other');
            $this->createBlacklistedHost($host, IncidentType::OTHER);

            [$code, $body] = $this->scan(['evidence' => ['text_content' => $host, 'content_type' => 'HOSTNAME'], 'concerns' => ['OTHER']]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['OTHER'], array_column($body['detections'], 'concern'));
        }

        public function testSubdomainResolvesToBlacklistedRegistrableDomain(): void
        {
            $host = $this->uniqueHost('tracker');
            $entityUuid = $this->createBlacklistedHost($host, IncidentType::TRACKING);

            [$code, $body] = $this->scan(['evidence' => ['text_content' => 'cdn.' . $host, 'content_type' => 'HOSTNAME'], 'concerns' => ['TRACKING']]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(['TRACKING'], array_column($body['detections'], 'concern'));
            $this->assertSame($entityUuid, $body['detections'][0]['entity']);
        }

        public function testDetectionFromClientContextIsUnverified(): void
        {
            $host = $this->uniqueHost('ads');
            $this->createBlacklistedHost($host, IncidentType::ADVERTISING);

            // The page URL is asserted by the client, a detection relying on it is not verified (OFD "Client Assertions")
            [$code, $body] = $this->scan([
                'evidence' => ['text_content' => 'Some page text', 'content_type' => 'WEB_PAGE', 'context' => ['url' => 'https://' . $host . '/page']],
                'concerns' => ['ADVERTISING'],
            ]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertCount(1, $body['detections']);
            $this->assertFalse($body['detections'][0]['verified']);
        }

        public function testDetectionReferencesTheContentIndex(): void
        {
            $host = $this->uniqueHost('spam');
            $this->createBlacklistedHost($host, IncidentType::SPAM);

            [$code, $body] = $this->scan(['evidence' => [
                ['text_content' => 'Nothing here'],
                ['text_content' => $host, 'content_type' => 'HOSTNAME'],
            ]]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame([1], array_column($body['detections'], 'content_index'));
        }

        public function testWhitelistedEntityIsNotDetected(): void
        {
            $host = $this->uniqueHost('whitelisted');
            $entityUuid = $this->createBlacklistedHost($host, IncidentType::SPAM);
            $this->client->setEntityWhitelist($entityUuid, true);

            [$code, $body] = $this->scan(['evidence' => ['text_content' => $host, 'content_type' => 'HOSTNAME']]);

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame([], $body['detections']);
        }

        public function testClientParsesDetections(): void
        {
            $host = $this->uniqueHost('spam');
            $entityUuid = $this->createBlacklistedHost($host, IncidentType::SPAM);

            $scannedContent = $this->client->scanContent(['text_content' => $host, 'content_type' => 'HOSTNAME'], null, null, null, [IncidentType::SPAM]);

            $this->assertCount(1, $scannedContent->getDetections());
            $this->assertSame(IncidentType::SPAM, $scannedContent->getDetections()[0]->getConcern());
            $this->assertSame($entityUuid, $scannedContent->getDetections()[0]->getEntity());
        }

        public function testQueryEntityConcerns(): void
        {
            $host = $this->uniqueHost('ads');
            $entityUuid = $this->createBlacklistedHost($host, IncidentType::ADVERTISING);

            [$code, $default] = $this->jsonRequest('GET', '/entities/' . $entityUuid . '/query', $this->rootToken());
            $this->assertSame(200, $code, json_encode($default));
            // Every active blacklist record is returned, only those of an effective concern suggest an action
            $this->assertCount(1, $default['active_blacklists']);
            $this->assertNull($default['suggested_action']);

            [$code, $requested] = $this->jsonRequest('GET', '/entities/' . $entityUuid . '/query?concerns=advertising,SPAM', $this->rootToken());
            $this->assertSame(200, $code, json_encode($requested));
            $this->assertSame('TEMPORARILY_BLOCK_ENTITY', $requested['suggested_action']);

            $result = $this->client->queryEntity($entityUuid, [IncidentType::ADVERTISING]);
            $this->assertCount(1, $result->getActiveBlacklists());
        }

        public function testQueryEntityRejectsInvalidConcerns(): void
        {
            $entityUuid = $this->client->pushEntity($this->uniqueHost('query'));
            $this->createdEntities[] = $entityUuid;

            foreach(['', 'BOGUS', 'SPAM,BOGUS', ','] as $concerns)
            {
                [$code, $response] = $this->rawRequest('GET', '/entities/' . $entityUuid . '/query?concerns=' . rawurlencode($concerns), $this->rootToken());
                $this->assertSame(400, $code, $concerns . ' ' . $response);
            }
        }

        public function testEvidenceStoresContentTypeAndContext(): void
        {
            $entityUuid = $this->client->pushEntity($this->uniqueHost('evidence'));
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Win a prize, reply now', null, null, false, null, null,
                ContentType::SMS, ['region' => 'US', 'sender_id' => 'PRIZES']);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertSame(ContentType::SMS, $evidence->getContentType());
            $this->assertSame(['region' => 'US', 'sender_id' => 'PRIZES'], $evidence->getContext());

            [$code, $body] = $this->jsonRequest('GET', '/evidence/' . $evidenceUuid, $this->rootToken());
            $this->assertSame(200, $code);
            $this->assertSame('SMS', $body['content_type']);
            $this->assertSame(['region' => 'US', 'sender_id' => 'PRIZES'], $body['context']);
        }

        public function testEvidenceDefaultsToText(): void
        {
            $entityUuid = $this->client->pushEntity($this->uniqueHost('evidence'));
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Plain text');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidence = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertSame(ContentType::TEXT, $evidence->getContentType());
            $this->assertNull($evidence->getContext());
            $this->assertFalse($evidence->isAutomated());
        }

        public function testEvidenceNormalizesHostname(): void
        {
            $entityUuid = $this->client->pushEntity($this->uniqueHost('evidence'));
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Ads.Example.INVALID.', null, null, false, null, null, ContentType::HOSTNAME);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->assertSame('ads.example.invalid', $this->client->getEvidenceRecord($evidenceUuid)->getTextContent());
        }

        public function testEvidenceRejectsInvalidContent(): void
        {
            $entityUuid = $this->client->pushEntity($this->uniqueHost('evidence'));
            $this->createdEntities[] = $entityUuid;

            $cases = [
                ['text_content' => 'not a hostname', 'content_type' => 'HOSTNAME'],
                ['text_content' => 'Body', 'content_type' => 'BOGUS'],
                ['text_content' => 'Page', 'content_type' => 'WEB_PAGE'],
                ['text_content' => 'Body', 'context' => ['subject' => 'Not for TEXT']],
            ];

            foreach($cases as $parameters)
            {
                [$code, $response] = $this->rawRequest('POST', '/evidence', $this->rootToken(), json_encode(['entity_identifier' => $entityUuid] + $parameters));
                $this->assertSame(400, $code, json_encode($parameters) . ' ' . $response);
            }
        }
    }
