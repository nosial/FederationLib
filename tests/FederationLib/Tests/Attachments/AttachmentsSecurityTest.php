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

    class AttachmentsSecurityTest extends TestCase
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

        public function testUploadAttachmentUnauthorized(): void
        {
            $operatorUuid = $this->client->createOperator('no-blacklist-operator');
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, false);
            $this->client->setClientPermissions($operatorUuid, false);
            $this->client->setOperatorPermissions($operatorUuid, false);

            $operator = $this->client->getOperator($operatorUuid);
            $restrictedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $this->client->pushEntity('unauthorized-upload-test.com', 'unauthorized_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('unauthorized.txt', 'Unauthorized upload test');

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $restrictedClient->uploadFileAttachment($evidenceUuid, $testFilePath);
        }

        public function testAccessAttachmentAsAnonymousClient(): void
        {
            $entityUuid = $this->client->pushEntity('anonymous-access-test.com', 'anonymous_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for anonymous access', 'Anonymous test', 'anonymous');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('anonymous_test.txt', 'Anonymous access test content');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            if (!$this->client->getServerInformation()->isPublicEvidence())
            {
                try
                {
                    $anonymousClient->getAttachmentInfo($uploadResult->getUuid());
                    $this->fail('Expected RequestException for non-public evidence attachment access');
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 401, 403], 'Expected 400, 401 or 403 for unauthorized attachment access');
                }
            }
            else
            {
                $attachmentInfo = $anonymousClient->getAttachmentInfo($uploadResult->getUuid());
                $this->assertNotNull($attachmentInfo);
                $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            }
        }

        public function testListAttachmentsUnauthorized(): void
        {
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            try
            {
                $anonymousClient->listAttachments();
                $this->fail('Expected RequestException for unauthenticated list attachments');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401], 'Expected 400 or 401 for unauthenticated request');
            }
        }

        public function testListAttachmentsForbidden(): void
        {
            $operatorUuid = $this->client->createOperator('no-blacklist-list-operator');
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, false);
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $restrictedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $restrictedClient->listAttachments();
        }

        public function testSecurityAttachmentUploadRestrictions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $clientOnly = $this->createLimitedOperator('attachment_client', client: true);
            $operatorOnly = $this->createLimitedOperator('attachment_operator', operator: true);

            // Uploading to a non-existent evidence record must fail.
            $this->expectRequestFailure(
                fn() => $clientOnly->uploadNoteAttachment('00000000-0000-0000-0000-000000000000', 'note.txt', 'content'),
                [HttpResponseCode::NOT_FOUND->value],
                'Upload to non-existent evidence should fail'
            );

            // Operators without client permissions cannot upload attachments.
            $this->expectRequestFailure(
                fn() => $operatorOnly->uploadNoteAttachment($evidenceUuid, 'note.txt', 'content'),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not upload attachments'
            );

            // Empty file uploads are rejected client-side before reaching the server.
            $emptyFile = $this->createSecurityTempFile('');
            $this->expectException(InvalidArgumentException::class);
            $clientOnly->uploadFileAttachment($evidenceUuid, $emptyFile);
        }

        public function testSecurityUploadAttachmentFromInvalidUrl(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $this->expectException(InvalidArgumentException::class);
            $this->client->uploadFileAttachmentFromUrl($evidenceUuid, 'not-a-valid-url');
        }

        public function testAttachmentConfidentialityFollowsEvidence(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential attachment evidence', 'Note', 'conf_follow', false);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('confidential_follow.txt', 'Confidential follow content');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $attachmentInfo = $anonymousClient->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);

            $this->expectRequestFailure(
                fn() => $anonymousClient->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::FORBIDDEN->value, HttpResponseCode::UNAUTHORIZED->value],
                'Attachment should become inaccessible when evidence is made confidential'
            );
        }

        public function testAttachmentInfoAccessControlAsAnonymous(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $testFilePath = $this->createTestFile('anon_access.txt', 'Anonymous access test');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            if ($this->client->getServerInformation()->isPublicEvidence())
            {
                $info = $anonymousClient->getAttachmentInfo($uploadResult->getUuid());
                $this->assertEquals($evidenceUuid, $info->getEvidenceUuid());
            }
            else
            {
                $this->expectRequestFailure(
                    fn() => $anonymousClient->getAttachmentInfo($uploadResult->getUuid()),
                    [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                    'Anonymous attachment access should follow public_evidence setting'
                );
            }
        }

        public function testUploadWithPathTraversalFilenameIsSanitized(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $maliciousNames = [
                '../../../etc/passwd',
                '..\\..\\windows\\system32\\config\\sam',
                'file.txt%00.php',
                'normal.txt',
            ];

            foreach ($maliciousNames as $name)
            {
                $testFilePath = $this->createTestFile('safe_local_name.txt', 'Content for ' . $name);
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath, $name);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $info = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertStringNotContainsString('..', $info->getFileName(), 'Path traversal sequences should be removed');
                $this->assertStringNotContainsString('/', $info->getFileName(), 'Directory separators should be removed from filename');
                $this->assertStringNotContainsString('\\', $info->getFileName(), 'Backslash separators should be removed from filename');
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
