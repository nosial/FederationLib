<?php

    namespace FederationLib\Tests\Attachments;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    class AttachmentsLogicTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdAttachments = [];
        private array $createdEvidenceRecords = [];
        private array $createdEntityRecords = [];
        private array $createdOperators = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];
        private $httpServerProcess = null;
        private ?int $httpServerPort = null;
        private ?string $httpServerRoot = null;

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $this->startHttpServer();
        }

        protected function tearDown(): void
        {
            foreach ($this->createdAttachments as $attachmentUuid)
            {
                try
                {
                    $this->client->deleteAttachment($attachmentUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete attachment $attachmentUuid: " . $e->getMessage());
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

            foreach ($this->createdEntityRecords as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

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

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile) && is_file($tempFile))
                {
                    unlink($tempFile);
                }
                elseif (file_exists($tempFile) && is_dir($tempFile))
                {
                    $this->recursiveRmdir($tempFile);
                }
            }

            if (is_resource($this->httpServerProcess))
            {
                proc_terminate($this->httpServerProcess, 9);
                proc_close($this->httpServerProcess);
                $this->httpServerProcess = null;
            }

            $this->createdAttachments = [];
            $this->createdEvidenceRecords = [];
            $this->createdEntityRecords = [];
            $this->createdOperators = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
            $this->httpServerPort = null;
            $this->httpServerRoot = null;
        }

        public function testUploadLargeFile(): void
        {
            $entityUuid = $this->client->pushEntity('large-file-test.com', 'large_file_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for large file', 'Large file test', 'large_file');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $largeContent = str_repeat('A', 1024 * 1024);
            $testFilePath = $this->createTestFile('large_test.txt', $largeContent);

            try
            {
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertEquals(1024 * 1024, $attachmentInfo->getFileSize());
            }
            catch (RequestException $e)
            {
                if ($e->getCode() === 413 || $e->getCode() === 400)
                {
                    Logger::getLogger()->info('Large file upload rejected by server (expected): ' . $e->getMessage());
                    $this->addToAssertionCount(1);
                }
                else
                {
                    throw $e;
                }
            }
        }

        public function testAttachmentLifecycleIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('lifecycle-test.com', 'lifecycle_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Lifecycle test evidence', 'Lifecycle test', 'lifecycle');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('lifecycle_test.txt', 'Lifecycle test content');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();

            $originalInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertNotNull($originalInfo);

            $downloadPath = sys_get_temp_dir();
            $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $downloadPath);
            $this->tempFiles[] = $downloadedFile;
            $this->assertTrue(file_exists($downloadedFile));
            $this->assertEquals('Lifecycle test content', file_get_contents($downloadedFile));

            $secondInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($originalInfo->getUuid(), $secondInfo->getUuid());
            $this->assertEquals($originalInfo->getEvidenceUuid(), $secondInfo->getEvidenceUuid());
            $this->assertEquals($originalInfo->getFileName(), $secondInfo->getFileName());
            $this->assertEquals($originalInfo->getFileSize(), $secondInfo->getFileSize());

            $this->client->deleteAttachment($attachmentUuid);

            try
            {
                $this->client->getAttachmentInfo($attachmentUuid);
                $this->fail('Expected RequestException for deleted attachment');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(404, $e->getCode());
            }
        }

        public function testMultipleAttachmentsPerEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('multiple-attachments-test.com', 'multiple_attachments_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence with multiple attachments', 'Multiple attachments test', 'multiple');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $attachmentUuids = [];

            for ($i = 1; $i <= 3; $i++)
            {
                $content = "Content for attachment number $i";
                $fileName = "attachment_$i.txt";
                $testFilePath = $this->createTestFile($fileName, $content);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $attachmentUuids[] = $uploadResult->getUuid();
                $this->createdAttachments[] = $uploadResult->getUuid();
            }

            foreach ($attachmentUuids as $index => $attachmentUuid)
            {
                $attachmentInfo = $this->client->getAttachmentInfo($attachmentUuid);
                $this->assertNotNull($attachmentInfo);
                $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
                $this->assertEquals('attachment_' . ($index + 1) . '.txt', $attachmentInfo->getFileName());
            }
        }

        public function testConcurrentAttachmentOperations(): void
        {
            $entityUuid = $this->client->pushEntity('concurrent-attachments-test.com', 'concurrent_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Concurrent operations evidence', 'Concurrent test', 'concurrent');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('concurrent_test.txt', 'Concurrent operations test');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $info1 = $this->client->getAttachmentInfo($attachmentUuid);
            $info2 = $this->client->getAttachmentInfo($attachmentUuid);
            $info3 = $this->client->getAttachmentInfo($attachmentUuid);

            $this->assertEquals($info1->getUuid(), $info2->getUuid());
            $this->assertEquals($info2->getUuid(), $info3->getUuid());
            $this->assertEquals($info1->getEvidenceUuid(), $info2->getEvidenceUuid());
            $this->assertEquals($info1->getFileName(), $info3->getFileName());

            $downloadPaths = [];
            for ($i = 1; $i <= 3; $i++)
            {
                $downloadPath = sys_get_temp_dir();
                $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $downloadPath);
                $this->tempFiles[] = $downloadedFile;
                $downloadPaths[] = $downloadedFile;
            }

            $originalContent = file_get_contents($downloadPaths[0]);
            foreach ($downloadPaths as $path)
            {
                $this->assertEquals($originalContent, file_get_contents($path));
            }
        }

        public function testAttachmentUploadFromUrlLifecycle(): void
        {
            $entityUuid = $this->client->pushEntity('url-attachment-lifecycle.com', 'url_lifecycle_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'URL attachment lifecycle evidence', 'Note', 'url_lifecycle');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $sourceFileName = 'lifecycle_source.txt';
            $sourceContent = 'Lifecycle source content for URL upload.';
            $this->createHttpServerFile($sourceFileName, $sourceContent);
            $sourceUrl = sprintf('http://127.0.0.1:%d/%s', $this->httpServerPort, $sourceFileName);

            $uploadResult = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $sourceUrl);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $attachmentInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertEquals(strlen($sourceContent), $attachmentInfo->getFileSize());

            $downloadPath = sys_get_temp_dir();
            $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $downloadPath);
            $this->tempFiles[] = $downloadedFile;

            $this->assertEquals($sourceContent, file_get_contents($downloadedFile));
        }

        public function testAttachmentDeletionDoesNotDeleteEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('delete-attachment-evidence.com', 'delete_attach_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence with attachment to delete', 'Note', 'delete_attach');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('delete_attach_only.txt', 'Delete attachment only content');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $this->client->deleteAttachment($attachmentUuid);
            array_splice($this->createdAttachments, array_search($attachmentUuid, $this->createdAttachments), 1);

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
        }

        public function testUploadUrlAttachmentWithRedirectIsHandled(): void
        {
            $entityUuid = $this->client->pushEntity('redirect-attachment.com', 'redirect_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Redirect attachment evidence', 'Note', 'redirect');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $sourceFileName = 'redirect_source.txt';
            $sourceContent = 'Redirect source content.';
            $this->createHttpServerFile($sourceFileName, $sourceContent);

            $redirectFileName = 'redirect.php';
            $redirectContent = "<?php header('Location: http://127.0.0.1:" . $this->httpServerPort . "/$sourceFileName'); exit;";
            $this->createHttpServerFile($redirectFileName, $redirectContent);

            $redirectUrl = sprintf('http://127.0.0.1:%d/%s', $this->httpServerPort, $redirectFileName);

            try
            {
                $uploadResult = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $redirectUrl);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $info = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertGreaterThan(0, $info->getFileSize());
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 422], 'Redirected URL attachment should be rejected or followed gracefully');
            }
        }

        public function testUploadNoteAttachmentContentVariations(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $contents = [
                'Plain text note',
                "Multi\nline\nnote",
                'Unicode note: 你好 🌍',
                str_repeat('Long note ', 100),
            ];

            foreach ($contents as $content)
            {
                $uploadResult = $this->client->uploadNoteAttachment($evidenceUuid, 'note.txt', $content);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $info = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertEquals(strlen($content), $info->getFileSize());

                $downloadPath = sys_get_temp_dir();
                $downloadedFile = $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);
                $this->tempFiles[] = $downloadedFile;
                $this->assertEquals($content, file_get_contents($downloadedFile));
            }
        }

        public function testUploadRejectedWhenNoFileProvided(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $url = rtrim(getenv('SERVER_ENDPOINT'), '/') . '/attachments';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->client->getAccessToken(),
                    'Content-Type: multipart/form-data; boundary=----boundary',
                ],
                CURLOPT_POSTFIELDS => "------boundary\r\nContent-Disposition: form-data; name=\"evidence_uuid\"\r\n\r\n$evidenceUuid\r\n------boundary--\r\n",
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $this->assertContains($code, [400, 404, 422], 'Upload without file should be rejected');
        }

        public function testAttachmentDownloadRequiresValidUuid(): void
        {
            try
            {
                $this->client->downloadAttachment('00000000-0000-0000-0000-000000000000', sys_get_temp_dir());
            }
            catch (RequestException $e)
            {
                $this->assertContains(
                    $e->getCode(),
                    [HttpResponseCode::NOT_FOUND->value, 500],
                    'Download with non-existent UUID should return 404 or server error'
                );
            }
        }

        private function createTestFile(string $fileName, string $content): string
        {
            $tempDir = sys_get_temp_dir();
            $filePath = $tempDir . '/' . $fileName;

            if (file_put_contents($filePath, $content) === false)
            {
                throw new RuntimeException("Failed to create test file: $filePath");
            }

            $this->tempFiles[] = $filePath;
            return $filePath;
        }

        private function startHttpServer(): void
        {
            $this->httpServerRoot = sys_get_temp_dir() . '/federation_http_' . uniqid();
            if (!mkdir($this->httpServerRoot, 0755, true) && !is_dir($this->httpServerRoot))
            {
                throw new RuntimeException('Failed to create HTTP server root: ' . $this->httpServerRoot);
            }

            $this->tempFiles[] = $this->httpServerRoot;

            $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false || !@\socket_bind($socket, '127.0.0.1', 0) || !@\socket_getsockname($socket, $address, $port))
            {
                throw new RuntimeException('Failed to find available port for HTTP server');
            }
            \socket_close($socket);

            $this->httpServerPort = $port;

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];

            $command = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($this->httpServerRoot));
            $this->httpServerProcess = proc_open($command, $descriptors, $pipes);

            if (!is_resource($this->httpServerProcess))
            {
                throw new RuntimeException('Failed to start HTTP server');
            }

            $started = microtime(true);
            while (microtime(true) - $started < 5)
            {
                $handle = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($handle !== false)
                {
                    fclose($handle);
                    return;
                }
                usleep(100000);
            }

            throw new RuntimeException('HTTP server did not start in time');
        }

        private function createHttpServerFile(string $fileName, string $content): string
        {
            $filePath = $this->httpServerRoot . '/' . $fileName;
            if (file_put_contents($filePath, $content) === false)
            {
                throw new RuntimeException("Failed to create HTTP server file: $filePath");
            }
            return $filePath;
        }

        private function recursiveRmdir(string $dir): void
        {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file)
            {
                $path = $dir . '/' . $file;
                is_dir($path) ? $this->recursiveRmdir($path) : unlink($path);
            }
            rmdir($dir);
        }

        private function createMinimalPng(): string
        {
            $filePath = tempnam(sys_get_temp_dir(), 'png_') . '.png';
            $minimalPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            file_put_contents($filePath, $minimalPng);
            $this->tempFiles[] = $filePath;
            return $filePath;
        }
    }
