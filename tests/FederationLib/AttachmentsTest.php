<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\UploadResult;
    use PHPUnit\Framework\TestCase;

    class AttachmentsTest extends TestCase
    {
        private FederationClient $client;
        private array $createdAttachments = [];
        private array $createdEvidence = [];
        private array $createdEntities = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up any attachments that were created during tests
            foreach ($this->createdAttachments as $attachmentUuid) {
                try {
                    $this->client->deleteAttachment($attachmentUuid);
                } catch (RequestException $e) {
                    // Ignore errors during cleanup
                }
            }
            $this->createdAttachments = [];

            // Clean up any evidence that was created during tests
            foreach ($this->createdEvidence as $evidenceUuid) {
                try {
                    $this->client->deleteEvidence($evidenceUuid);
                } catch (RequestException $e) {
                    // Ignore errors during cleanup
                }
            }
            $this->createdEvidence = [];

            // Clean up any entities that were created during tests
            foreach ($this->createdEntities as $entityId) {
                try {
                    $this->client->deleteEntity($entityId);
                } catch (RequestException $e) {
                    // Ignore errors during cleanup
                }
            }
            $this->createdEntities = [];

            // Clean up temporary files
            foreach ($this->tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            $this->tempFiles = [];
        }

        private function createTestFile(string $content = 'Test file content', string $extension = '.txt'): string
        {
            $tempFile = tempnam(sys_get_temp_dir(), 'federation_test_') . $extension;
            file_put_contents($tempFile, $content);
            $this->tempFiles[] = $tempFile;
            return $tempFile;
        }

        private function createEvidenceForTesting(): string
        {
            // Create entity first
            $entityId = 'test-attachment-entity-' . uniqid();
            $this->client->pushEntity($entityId, 'attachment.example.com');
            $this->createdEntities[] = $entityId;

            // Get entity UUID
            $entity = $this->client->getEntityRecord($entityId);
            $entityUuid = $entity->getUuid();

            // Create evidence
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for attachment testing');
            $this->createdEvidence[] = $evidenceUuid;

            return $evidenceUuid;
        }

        public function testUploadFileAttachment()
        {
            $evidenceUuid = $this->createEvidenceForTesting();
            $testFile = $this->createTestFile('Test attachment content');

            // Upload the file
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFile);
            $this->assertInstanceOf(UploadResult::class, $uploadResult);
            $this->assertNotEmpty($uploadResult->getUuid());
            $this->assertNotEmpty($uploadResult->getDownloadUrl());
            $this->createdAttachments[] = $uploadResult->getUuid();
        }

        public function testUploadFileAttachmentWithDifferentTypes()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Test different file types
            $testFiles = [
                'text' => $this->createTestFile('Plain text content', '.txt'),
                'json' => $this->createTestFile('{"test": "json"}', '.json'),
                'csv' => $this->createTestFile("name,value\ntest,123", '.csv'),
                'xml' => $this->createTestFile('<?xml version="1.0"?><root><test>data</test></root>', '.xml')
            ];

            foreach ($testFiles as $type => $filePath) {
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $filePath);
                $this->assertInstanceOf(UploadResult::class, $uploadResult);
                $this->assertNotEmpty($uploadResult->getUuid());
                $this->createdAttachments[] = $uploadResult->getUuid();
            }
        }

        public function testUploadFileAttachmentValidation()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Test empty evidence UUID
            $testFile = $this->createTestFile();
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence UUID cannot be empty');
            $this->client->uploadFileAttachment('', $testFile);
        }

        public function testUploadNonExistentFile()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Test non-existent file
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('File does not exist');
            $this->client->uploadFileAttachment($evidenceUuid, '/path/to/nonexistent/file.txt');
        }

        public function testUploadEmptyFile()
        {
            $evidenceUuid = $this->createEvidenceForTesting();
            
            // Create empty file
            $emptyFile = $this->createTestFile('');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid file or empty file');
            $this->client->uploadFileAttachment($evidenceUuid, $emptyFile);
        }

        public function testUploadFileAttachmentFromUrl()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Use a simple test URL (you might need to adjust this based on your test environment)
            $testUrl = 'https://httpbin.org/json';

            try {
                $uploadResult = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $testUrl);
                $this->assertInstanceOf(UploadResult::class, $uploadResult);
                $this->assertNotEmpty($uploadResult->getUuid());
                $this->assertNotEmpty($uploadResult->getDownloadUrl());
                $this->createdAttachments[] = $uploadResult->getUuid();
            } catch (RequestException $e) {
                // Skip this test if external URL is not accessible in test environment
                $this->markTestSkipped('External URL not accessible in test environment: ' . $e->getMessage());
            }
        }

        public function testUploadFileAttachmentFromUrlValidation()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Test empty evidence UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence UUID cannot be empty');
            $this->client->uploadFileAttachmentFromUrl('', 'https://example.com/file.txt');
        }

        public function testUploadFileAttachmentFromInvalidUrl()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Test invalid URL
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid URL provided');
            $this->client->uploadFileAttachmentFromUrl($evidenceUuid, 'not-a-valid-url');
        }

        public function testUploadFileAttachmentFromUrlWithMaxSize()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Test with very small max file size
            $testUrl = 'https://httpbin.org/json';
            
            try {
                $this->expectException(RequestException::class);
                $this->expectExceptionMessage('exceeds maximum size limit');
                $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $testUrl, 10); // 10 bytes max
            } catch (RequestException $e) {
                if (strpos($e->getMessage(), 'not accessible') !== false) {
                    $this->markTestSkipped('External URL not accessible in test environment');
                }
                throw $e;
            }
        }

        public function testDeleteAttachment()
        {
            $evidenceUuid = $this->createEvidenceForTesting();
            $testFile = $this->createTestFile('Content to be deleted');

            // Upload and then delete
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFile);
            $attachmentUuid = $uploadResult->getUuid();

            // Delete the attachment
            $this->client->deleteAttachment($attachmentUuid);

            // Verify it's deleted by trying to download it (should fail)
            $tempDownloadPath = tempnam(sys_get_temp_dir(), 'download_test_');
            $this->tempFiles[] = $tempDownloadPath;

            $this->expectException(RequestException::class);
            $this->client->downloadAttachment($attachmentUuid, $tempDownloadPath);
        }

        public function testDeleteAttachmentValidation()
        {
            // Test empty attachment UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Attachment UUID cannot be empty');
            $this->client->deleteAttachment('');
        }

        public function testDownloadAttachment()
        {
            $evidenceUuid = $this->createEvidenceForTesting();
            $originalContent = 'Content to download and verify';
            $testFile = $this->createTestFile($originalContent);

            // Upload the file
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFile);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            // Download to a specific file
            $downloadPath = tempnam(sys_get_temp_dir(), 'download_test_') . '.txt';
            $this->tempFiles[] = $downloadPath;

            $this->client->downloadAttachment($attachmentUuid, $downloadPath);

            // Verify the file was downloaded and content matches
            $this->assertFileExists($downloadPath);
            $downloadedContent = file_get_contents($downloadPath);
            $this->assertEquals($originalContent, $downloadedContent);
        }

        public function testDownloadAttachmentToDirectory()
        {
            $evidenceUuid = $this->createEvidenceForTesting();
            $testFile = $this->createTestFile('Content for directory download');

            // Upload the file
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFile);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            // Download to a directory (should use suggested filename)
            $downloadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
            
            $this->client->downloadAttachment($attachmentUuid, $downloadDir);

            // Find the downloaded file
            $files = glob($downloadDir . $attachmentUuid . '*');
            $this->assertNotEmpty($files, 'Downloaded file not found in directory');
            
            $downloadedFile = $files[0];
            $this->tempFiles[] = $downloadedFile;
            $this->assertFileExists($downloadedFile);
        }

        public function testDownloadAttachmentValidation()
        {
            // Test empty attachment UUID
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Attachment UUID cannot be empty');
            $this->client->downloadAttachment('', '/tmp/test.txt');
        }

        public function testDownloadAttachmentEmptyPath()
        {
            $attachmentUuid = 'test-uuid-' . uniqid();

            // Test empty file path
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('File path cannot be empty');
            $this->client->downloadAttachment($attachmentUuid, '');
        }

        public function testAttachmentLifecycle()
        {
            // Complete lifecycle: create evidence, upload attachment, download, delete
            $evidenceUuid = $this->createEvidenceForTesting();
            $originalContent = 'Lifecycle test content';
            $testFile = $this->createTestFile($originalContent);

            // 1. Upload attachment
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFile);
            $attachmentUuid = $uploadResult->getUuid();
            $this->assertNotEmpty($attachmentUuid);
            $this->assertNotEmpty($uploadResult->getDownloadUrl());

            // 2. Download attachment
            $downloadPath = tempnam(sys_get_temp_dir(), 'lifecycle_download_') . '.txt';
            $this->tempFiles[] = $downloadPath;
            
            $this->client->downloadAttachment($attachmentUuid, $downloadPath);
            $this->assertFileExists($downloadPath);
            $this->assertEquals($originalContent, file_get_contents($downloadPath));

            // 3. Delete attachment
            $this->client->deleteAttachment($attachmentUuid);

            // 4. Verify deletion
            $this->expectException(RequestException::class);
            $this->client->downloadAttachment($attachmentUuid, $downloadPath);
        }

        public function testUploadLargeFile()
        {
            $evidenceUuid = $this->createEvidenceForTesting();
            
            // Create a larger test file (1MB)
            $largeContent = str_repeat('This is a large file test content. ', 30000);
            $largeFile = $this->createTestFile($largeContent, '.txt');

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $largeFile);
            $this->assertInstanceOf(UploadResult::class, $uploadResult);
            $this->assertNotEmpty($uploadResult->getUuid());
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Verify we can download it back
            $downloadPath = tempnam(sys_get_temp_dir(), 'large_download_') . '.txt';
            $this->tempFiles[] = $downloadPath;
            
            $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);
            $this->assertEquals($largeContent, file_get_contents($downloadPath));
        }

        public function testUploadBinaryFile()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Create a simple binary file (a tiny PNG-like structure)
            $binaryContent = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100);
            $binaryFile = $this->createTestFile($binaryContent, '.png');

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $binaryFile);
            $this->assertInstanceOf(UploadResult::class, $uploadResult);
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Verify binary content is preserved
            $downloadPath = tempnam(sys_get_temp_dir(), 'binary_download_') . '.png';
            $this->tempFiles[] = $downloadPath;
            
            $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);
            $this->assertEquals($binaryContent, file_get_contents($downloadPath));
        }

        public function testMultipleAttachmentsPerEvidence()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Upload multiple attachments to the same evidence
            $attachmentUuids = [];
            for ($i = 1; $i <= 3; $i++) {
                $testFile = $this->createTestFile("Attachment $i content", ".txt");
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFile);
                $attachmentUuids[] = $uploadResult->getUuid();
                $this->createdAttachments[] = $uploadResult->getUuid();
            }

            // Verify all attachments were uploaded successfully
            $this->assertCount(3, $attachmentUuids);
            foreach ($attachmentUuids as $attachmentUuid) {
                $this->assertNotEmpty($attachmentUuid);
            }

            // Verify we can download all of them
            foreach ($attachmentUuids as $i => $attachmentUuid) {
                $downloadPath = tempnam(sys_get_temp_dir(), "multi_download_$i") . '.txt';
                $this->tempFiles[] = $downloadPath;
                
                $this->client->downloadAttachment($attachmentUuid, $downloadPath);
                $this->assertFileExists($downloadPath);
                $expectedContent = "Attachment " . ($i + 1) . " content";
                $this->assertEquals($expectedContent, file_get_contents($downloadPath));
            }
        }

        public function testUploadFileWithSpecialCharactersInName()
        {
            $evidenceUuid = $this->createEvidenceForTesting();

            // Create file with special characters in name
            $specialFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test-file-with-spéciál-chars-中文-🚀.txt';
            file_put_contents($specialFile, 'Content with special filename');
            $this->tempFiles[] = $specialFile;

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $specialFile);
            $this->assertInstanceOf(UploadResult::class, $uploadResult);
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Verify we can download it
            $downloadPath = tempnam(sys_get_temp_dir(), 'special_download_') . '.txt';
            $this->tempFiles[] = $downloadPath;
            
            $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);
            $this->assertEquals('Content with special filename', file_get_contents($downloadPath));
        }
    }
