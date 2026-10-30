<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\ContentScan;

    use CURLFile;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\ContentInput;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    /**
     * Files named by URL with scanned content (OFD 2.2 "Scanned file URLs"). The server retrieves no file URL by
     * default (remote_retrieval.scan_files), so file URLs are accepted and validated but never retrieved.
     */
    class ScanFileUrlsTest extends TestCase
    {
        use TestHelpers;

        /**
         * A 1x1 PNG image
         */
        private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        private const string FILE_URL = 'https://example.com/media/parcel.jpg';

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

        public function testServerImplementsVersion22(): void
        {
            $this->assertSame('2.2', $this->client->getServerInformation()->getApiVersion());
        }

        public function testFileUrlOnlyContentKeepsTheNeutralRiskScore(): void
        {
            $result = $this->client->scanContent(new ContentInput(), fileUrls: [0 => [self::FILE_URL]]);

            $this->assertEqualsWithDelta(50.0, $result->getRiskScore(), 0.0001);
            $this->assertNull($result->getSuggestedAction());
            $this->assertSame([], $result->getDetections());
        }

        public function testScanContentWithTextAndFileUrls(): void
        {
            $result = $this->client->scanContent(new ContentInput('Your parcel is on hold'), fileUrls: [0 => [self::FILE_URL, 'http://example.com/qr.png']]);

            $this->assertGreaterThanOrEqual(0.0, $result->getRiskScore());
            $this->assertLessThanOrEqual(100.0, $result->getRiskScore());
        }

        public function testFileUrlsInJsonRequest(): void
        {
            [$code, $response] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['file_urls' => [self::FILE_URL]]]);

            $this->assertSame(200, $code, json_encode($response));
            $this->assertEqualsWithDelta(50.0, $response['risk_score'], 0.0001);
        }

        public function testFileUrlsWithFilesInMultipartRequest(): void
        {
            [$code, $response] = $this->multipartScan([
                'evidence' => json_encode(['text_content' => 'Hello', 'files' => ['image'], 'file_urls' => [self::FILE_URL]]),
                'image' => new CURLFile($this->createSecurityTempFile(base64_decode(self::PNG), 'png'), 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(200, $code, json_encode($response));
        }

        public function testFileUrlsOfSeveralEvidenceItems(): void
        {
            $result = $this->client->scanContent([new ContentInput('First message'), new ContentInput()], fileUrls: [
                0 => [self::FILE_URL],
                1 => [self::FILE_URL, self::FILE_URL],
            ]);

            $this->assertIsFloat($result->getRiskScore());
        }

        public function testIgnoredFileUrlsAreStillValidated(): void
        {
            foreach(['ftp://example.com/file.jpg', 'https://user:secret@example.com/file.jpg', 'example.com/file.jpg', '/file.jpg',
                        'https://' . str_repeat('a', 2050) . '.com/file.jpg', 'https://exa mple.com/file.jpg'] as $url)
            {
                [$code] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['text_content' => 'Hello', 'file_urls' => [$url]]]);

                $this->assertSame(400, $code, $url);
            }
        }

        public function testRejectsInvalidFileUrlsMember(): void
        {
            foreach([[], self::FILE_URL, [1], [null], ['url' => self::FILE_URL]] as $fileUrls)
            {
                [$code] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['text_content' => 'Hello', 'file_urls' => $fileUrls]]);

                $this->assertSame(400, $code, json_encode($fileUrls));
            }
        }

        public function testClientRejectsFileUrlsOfUnknownEvidence(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->scanContent(new ContentInput('Hello'), fileUrls: [1 => [self::FILE_URL]]);
        }

        public function testClientRejectsEmptyFileUrls(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->scanContent(new ContentInput('Hello'), fileUrls: [0 => []]);
        }

        public function testSpecificationDocumentsFileUrls(): void
        {
            $specification = $this->client->getSpecification();

            $this->assertStringContainsString('file_urls', json_encode($specification['paths']['/scan']));
        }

        /**
         * Scans content with a multipart/form-data body
         *
         * @param array<string, string|CURLFile> $fields The parts of the body
         * @param string|null $token The access token, null for an unauthenticated request
         * @return array{0: int, 1: mixed} The HTTP status code and the decoded response
         */
        private function multipartScan(array $fields, ?string $token): array
        {
            $ch = curl_init(rtrim(getenv('SERVER_ENDPOINT'), '/') . '/scan');
            $headers = ['Accept: application/json'];
            if($token !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $fields,
            ]);

            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return [(int)$code, json_decode((string)$response, true)];
        }
    }
