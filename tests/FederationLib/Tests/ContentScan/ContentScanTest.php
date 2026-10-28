<?php

    namespace FederationLib\Tests\ContentScan;

    use FederationLib\Classes\Utilities;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ContentInput;
    use InvalidArgumentException;

    class ContentScanTest extends ServerTestCase
    {
        private const string BENIGN_SAMPLE_TEXT = 'This is a simple, benign message used for scanning tests.';

        public function testScanContentBasic(): void
        {
            // Content may be given as a string or as a ContentInput, both scan the same text content
            foreach ([self::BENIGN_SAMPLE_TEXT, new ContentInput(self::BENIGN_SAMPLE_TEXT)] as $evidence)
            {
                $scanned = $this->client->scanContent($evidence);

                $this->assertSame([], $scanned->getResolvedEntities());
                $this->assertNull($scanned->getAuthorEntity());
                $this->assertGreaterThanOrEqual(0.0, $scanned->getRiskScore());
                $this->assertLessThanOrEqual(100.0, $scanned->getRiskScore());
                $this->assertNotEmpty($scanned->getScanResults());
            }
        }

        public function testScanContentEmptyContent(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('At least one evidence record must contain text content');
            $this->client->scanContent('');
        }

        public function testScanContentEmptyEvidenceArray(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence cannot be empty');
            $this->client->scanContent([]);
        }

        public function testScanContentWithAuthorByUuid(): void
        {
            $entityUuid = $this->client->pushEntity('scan-author-uuid.com', 'scan_author_uuid');
            $this->createdEntities[] = $entityUuid;

            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $entityUuid);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertEquals($entityUuid, $scanned->getAuthorEntity()->getEntity()->getUuid());
        }

        public function testScanContentWithAuthorByHash(): void
        {
            $host = 'scan-author-hash.com';
            $id = 'scan_author_hash';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $hash = Utilities::hashEntity($host, $id);
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $hash);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertEquals($entityUuid, $scanned->getAuthorEntity()->getEntity()->getUuid());
        }

        public function testScanContentWithAuthorByAddress(): void
        {
            $host = 'scan-author-address.com';
            $id = 'scan_author_address';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $address = $id . '@' . $host;
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, $address);

            $this->assertNotNull($scanned);
            $this->assertNotNull($scanned->getAuthorEntity());
            $this->assertEquals($entityUuid, $scanned->getAuthorEntity()->getEntity()->getUuid());
        }

        public function testScanContentWithInvalidAuthor(): void
        {
            $scanned = $this->client->scanContent(self::BENIGN_SAMPLE_TEXT, 'not-a-valid-identifier');
            $this->assertNotNull($scanned);
            $this->assertNull($scanned->getAuthorEntity());
        }

        public function testScanContentEntityPositions(): void
        {
            $host = 'scan-position.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $prefix = 'Before ';
            $text = $prefix . $host . ' after';
            $scanned = $this->client->scanContent($text);

            $this->assertNotNull($scanned);
            $this->assertCount(1, $scanned->getResolvedEntities());

            $position = $scanned->getResolvedEntities()[0]->getEntityPosition();
            $this->assertNotNull($position);
            $this->assertEquals(strlen($prefix), $position->getOffset());
            $this->assertEquals(strlen($host), $position->getLength());
        }

        public function testScanContentRejectsInvalidTopKAndThreshold(): void
        {
            // top_k and threshold are handed to the plugins that classify the content (see PluginRequestsTest), the
            // server only validates them
            $invalid = [
                ['top_k' => 'two'],
                ['top_k' => 1.5],
                ['threshold' => 'high'],
                ['threshold' => true],
            ];

            foreach ($invalid as $parameters)
            {
                [$code, $body] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['text_content' => self::BENIGN_SAMPLE_TEXT]] + $parameters);
                $this->assertSame(400, $code, json_encode($parameters) . ' should be rejected: ' . json_encode($body));
            }

            [$code, $body] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['text_content' => self::BENIGN_SAMPLE_TEXT], 'top_k' => '2', 'threshold' => '0.25']);
            $this->assertSame(200, $code, 'Numeric strings should be accepted: ' . json_encode($body));
        }

        public function testScanContentDoesNotResolveUnknownEntities(): void
        {
            $unknownHost = 'unknown-domain-' . uniqid() . '.test';
            $scanned = $this->client->scanContent("Visit $unknownHost for details. " . self::BENIGN_SAMPLE_TEXT);

            $this->assertSame([], $scanned->getResolvedEntities(), 'A host without an entity should not be resolved');
        }

        public function testScanContentEntityPositionsForUrlEmailAndIps(): void
        {
            $domainHost = 'scan-position-domain.com';
            $emailHost = 'scan-position-email.com';
            $emailId = 'position_user';
            $ipv4 = '203.0.113.45';
            $ipv6 = '2001:db8:85a3::8a2e:370:7335';

            $domainUuid = $this->client->pushEntity($domainHost);
            $emailUuid = $this->client->pushEntity($emailHost, $emailId);
            $ipv4Uuid = $this->client->pushEntity($ipv4);
            $ipv6Uuid = $this->client->pushEntity($ipv6);
            $this->createdEntities[] = $domainUuid;
            $this->createdEntities[] = $emailUuid;
            $this->createdEntities[] = $ipv4Uuid;
            $this->createdEntities[] = $ipv6Uuid;

            $urlPrefix = 'Check ';
            $url = "https://$domainHost/path";
            $emailPrefix = ' or email ';
            $email = "$emailId@$emailHost";
            $ipv4Prefix = ' or server ';
            $ipv6Prefix = ' or v6 ';

            $text = $urlPrefix . $url . $emailPrefix . $email . $ipv4Prefix . $ipv4 . $ipv6Prefix . $ipv6;

            $scanned = $this->client->scanContent($text);

            // The domain of a URL, an email address and IPv4 and IPv6 addresses are each resolved where they appear
            $expected = [
                $domainUuid => strlen($urlPrefix),
                $emailUuid => strlen($urlPrefix . $url . $emailPrefix),
                $ipv4Uuid => strlen($urlPrefix . $url . $emailPrefix . $email . $ipv4Prefix),
                $ipv6Uuid => strlen($urlPrefix . $url . $emailPrefix . $email . $ipv4Prefix . $ipv4 . $ipv6Prefix),
            ];

            $offsets = [];
            foreach ($scanned->getResolvedEntities() as $resolvedEntity)
            {
                $position = $resolvedEntity->getEntityPosition();
                $this->assertNotNull($position, 'Expected a position for resolved entity ' . $resolvedEntity->getEntity()->getUuid());
                $offsets[$resolvedEntity->getEntity()->getUuid()] = $position->getOffset();
            }

            ksort($expected);
            ksort($offsets);
            $this->assertSame($expected, $offsets);
        }
    }
