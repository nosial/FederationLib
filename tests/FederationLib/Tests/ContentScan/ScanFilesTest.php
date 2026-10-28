<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\ContentScan;

    use CURLFile;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\ContentInput;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    /**
     * Files submitted with scanned content (OFD 2.1 "Scanned files"). FederationLib interprets no file itself, so
     * files are accepted and validated but never affect the result of the scan.
     */
    class ScanFilesTest extends TestCase
    {
        use TestHelpers;

        /**
         * A 1x1 PNG image
         */
        private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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
         * Creates a temporary PNG image and registers it for cleanup
         *
         * @return string The path of the image
         */
        private function createImage(): string
        {
            return $this->createSecurityTempFile(base64_decode(self::PNG), 'png');
        }

        /**
         * Scans content with a multipart/form-data body as the root operator
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

        public function testServerPublishesScanFileLimits(): void
        {
            $serverInformation = $this->client->getServerInformation();

            $this->assertSame('2.1', $serverInformation->getApiVersion());
            $this->assertSame(4, $serverInformation->getMaxScanFiles());
            $this->assertSame(10485760, $serverInformation->getMaxScanFileSize());
        }

        public function testScanContentWithFile(): void
        {
            $result = $this->client->scanContent(new ContentInput('Your parcel is on hold'), files: [0 => [$this->createImage()]]);

            $this->assertGreaterThanOrEqual(0.0, $result->getRiskScore());
            $this->assertLessThanOrEqual(100.0, $result->getRiskScore());
        }

        public function testFileOnlyContentKeepsTheNeutralRiskScore(): void
        {
            $result = $this->client->scanContent(new ContentInput(), files: [0 => [$this->createImage()]]);

            $this->assertEqualsWithDelta(50.0, $result->getRiskScore(), 0.0001);
            $this->assertNull($result->getSuggestedAction());
        }

        public function testFileOfAnyMediaTypeIsAccepted(): void
        {
            $path = $this->createSecurityTempFile(random_bytes(512), 'bin');
            $result = $this->client->scanContent(new ContentInput(), files: [0 => [$path]]);

            $this->assertEqualsWithDelta(50.0, $result->getRiskScore(), 0.0001);
        }

        public function testFilesOfSeveralEvidenceItems(): void
        {
            $result = $this->client->scanContent([new ContentInput('First message'), new ContentInput()], files: [
                0 => [$this->createImage(), $this->createImage()],
                1 => [$this->createImage()],
            ]);

            $this->assertIsFloat($result->getRiskScore());
        }

        public function testDetectionOfTheTextHasNoFileIndex(): void
        {
            $host = $this->uniqueHost('scanfiles');
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() + 3600);

            [$code, $body] = $this->multipartScan([
                'evidence' => json_encode(['text_content' => 'Visit ' . $host, 'files' => ['image']]),
                'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(200, $code, json_encode($body));
            $this->assertNotEmpty($body['detections']);
            foreach($body['detections'] as $detection)
            {
                $this->assertArrayHasKey('file_index', $detection);
                $this->assertNull($detection['file_index']);
            }
        }

        public function testMultipartConcernsAreJson(): void
        {
            [$code, $body] = $this->multipartScan([
                'evidence' => json_encode(['files' => ['image']]),
                'concerns' => json_encode(['SPAM']),
                'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(200, $code, json_encode($body));
        }

        public function testUnauthenticatedFilesAreIgnored(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'));
            $result = $client->scanContent([new ContentInput('Hello'), new ContentInput()], files: [
                0 => [$this->createImage()],
                1 => [$this->createImage()],
            ]);

            $this->assertIsFloat($result->getRiskScore());
        }

        public function testFilesWithoutClientPermissionsAreIgnored(): void
        {
            $client = $this->createLimitedOperator('scanfiles');
            $result = $client->scanContent(new ContentInput(), files: [0 => [$this->createImage()]]);

            $this->assertEqualsWithDelta(50.0, $result->getRiskScore(), 0.0001);
        }

        public function testIgnoredFilesAreStillValidated(): void
        {
            [$code] = $this->multipartScan([
                'evidence' => json_encode(['text_content' => 'Hello']),
                'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
            ], null);

            $this->assertSame(400, $code);
        }

        public function testRejectsFilePartThatIsNotNamed(): void
        {
            [$code] = $this->multipartScan([
                'evidence' => json_encode(['text_content' => 'Hello']),
                'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsFilePartNamedTwice(): void
        {
            [$code] = $this->multipartScan([
                'evidence' => json_encode([['files' => ['image']], ['files' => ['image']]]),
                'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsNamedPartThatIsMissing(): void
        {
            [$code] = $this->multipartScan([
                'evidence' => json_encode(['text_content' => 'Hello', 'files' => ['image']]),
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsNamedPartThatIsNotAFile(): void
        {
            [$code] = $this->multipartScan([
                'evidence' => json_encode(['text_content' => 'Hello', 'files' => ['image']]),
                'image' => 'not a file',
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsFilesInJsonRequest(): void
        {
            [$code] = $this->jsonRequest('POST', '/scan', $this->rootToken(), ['evidence' => ['text_content' => 'Hello', 'files' => ['image']]]);

            $this->assertSame(400, $code);
        }

        public function testRejectsInvalidFilesMember(): void
        {
            foreach([[], 'image', [1], ['evidence'], ['has space'], [str_repeat('a', 65)]] as $files)
            {
                [$code] = $this->multipartScan([
                    'evidence' => json_encode(['text_content' => 'Hello', 'files' => $files]),
                    'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
                ], $this->rootToken());

                $this->assertSame(400, $code, json_encode($files));
            }
        }

        public function testRejectsEmptyFile(): void
        {
            $path = $this->createSecurityTempFile('', 'png');

            [$code] = $this->multipartScan([
                'evidence' => json_encode(['files' => ['image']]),
                'image' => new CURLFile($path, 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsTooManyFiles(): void
        {
            $fields = [];
            $names = [];
            for($i = 0; $i < 5; $i++)
            {
                $names[] = 'image_' . $i;
                $fields['image_' . $i] = new CURLFile($this->createImage(), 'image/png', 'image.png');
            }

            [$code] = $this->multipartScan(['evidence' => json_encode(['files' => $names])] + $fields, $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsFileExceedingTheMaximumSize(): void
        {
            $path = $this->createSecurityTempFile(str_repeat("\0", 10485761), 'bin');

            [$code] = $this->multipartScan([
                'evidence' => json_encode(['files' => ['file']]),
                'file' => new CURLFile($path, 'application/octet-stream', 'large.bin'),
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsEvidencePartThatIsNotJson(): void
        {
            [$code] = $this->multipartScan([
                'evidence' => 'not json',
                'image' => new CURLFile($this->createImage(), 'image/png', 'image.png'),
            ], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testRejectsContentWithoutTextOrFiles(): void
        {
            [$code] = $this->multipartScan(['evidence' => json_encode(['note' => 'Nothing to scan'])], $this->rootToken());

            $this->assertSame(400, $code);
        }

        public function testClientRequiresTextOrFiles(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->scanContent(new ContentInput());
        }

        public function testClientRejectsFilesOfUnknownEvidence(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->scanContent(new ContentInput('Hello'), files: [1 => [$this->createImage()]]);
        }
    }
