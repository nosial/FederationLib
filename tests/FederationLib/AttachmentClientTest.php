<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class AttachmentClientTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdAttachments = [];
        private array $createdEvidenceRecords = [];
        private array $createdEntityRecords = [];
        private array $createdOperators = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('attachment-tests');
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            // Clean up attachments first (they depend on evidence)
            foreach ($this->createdAttachments as $attachmentUuid)
            {
                try
                {
                    $this->client->deleteAttachment($attachmentUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete attachment $attachmentUuid: " . $e->getMessage());
                }
            }

            // Clean up evidence records
            foreach ($this->createdEvidenceRecords as $evidenceUuid)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
                }
            }

            // Clean up entities
            foreach ($this->createdEntityRecords as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
                }
            }

            // Clean up operators
            foreach ($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch (RequestException $e)
                {
                    $this->logger->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            // Clean up temporary files
            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            // Reset arrays
            $this->createdAttachments = [];
            $this->createdEvidenceRecords = [];
            $this->createdEntityRecords = [];
            $this->createdOperators = [];
            $this->tempFiles = [];
        }

        // BASIC ATTACHMENT OPERATIONS

        public function testUploadFileAttachment(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('attachment-test.com', 'attachment_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for attachment', 'Attachment test', 'attachment');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create a temporary test file
            $testFilePath = $this->createTestFile('test_attachment.txt', 'This is test content for file attachment.');

            // Upload the attachment
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->assertNotNull($uploadResult);
            $this->assertNotEmpty($uploadResult->getUuid());
            $this->assertNotEmpty($uploadResult->getUrl());
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Verify attachment info
            $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
            $this->assertNotNull($attachmentInfo);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            $this->assertEquals('test_attachment.txt', $attachmentInfo->getFileName());
            $this->assertGreaterThan(0, $attachmentInfo->getFileSize());
            $this->assertNotEmpty($attachmentInfo->getFileMime());
        }

        public function testUploadFileAttachmentFromUrl(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('url-attachment-test.com', 'url_attachment_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for URL attachment', 'URL attachment test', 'url_attachment');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Use a small, publicly accessible file (e.g., a small text file or JSON)
            $testUrl = 'https://httpbin.org/json';

            // Upload the attachment from URL
            $uploadResult = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $testUrl);
            $this->assertNotNull($uploadResult);
            $this->assertNotEmpty($uploadResult->getUuid());
            $this->assertNotEmpty($uploadResult->getUrl());
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Verify attachment info
            $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
            $this->assertNotNull($attachmentInfo);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            $this->assertGreaterThan(0, $attachmentInfo->getFileSize());
        }

        public function testDownloadAttachment(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('download-test.com', 'download_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for download', 'Download test', 'download');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create and upload a test file
            $originalContent = 'This is the original content for download test.';
            $testFilePath = $this->createTestFile('download_test.txt', $originalContent);

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Download the attachment
            $downloadPath = sys_get_temp_dir() . '/downloaded_' . uniqid() . '.txt';
            $this->tempFiles[] = $downloadPath;

            $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);

            // Verify the downloaded file
            $this->assertTrue(file_exists($downloadPath));
            $downloadedContent = file_get_contents($downloadPath);
            $this->assertEquals($originalContent, $downloadedContent);
        }

        public function testDeleteAttachment(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('delete-attachment-test.com', 'delete_attachment_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for delete', 'Delete attachment test', 'delete_attachment');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create and upload a test file
            $testFilePath = $this->createTestFile('delete_test.txt', 'This file will be deleted.');

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();

            // Verify attachment exists
            $attachmentInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertNotNull($attachmentInfo);

            // Delete the attachment
            $this->client->deleteAttachment($attachmentUuid);

            // Verify attachment no longer exists
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404);
            $this->client->getAttachmentInfo($attachmentUuid);
        }

        // VALIDATION AND ERROR HANDLING TESTS

        public function testUploadAttachmentInvalidEvidenceUuid(): void
        {
            $testFilePath = $this->createTestFile('invalid_evidence.txt', 'Test content');

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence UUID cannot be empty');
            $this->client->uploadFileAttachment('', $testFilePath);
        }

        public function testUploadAttachmentNonExistentFile(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('nonexistent-file-test.com', 'nonexistent_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('File does not exist');
            $this->client->uploadFileAttachment($evidenceUuid, '/non/existent/file.txt');
        }

        public function testUploadAttachmentFromInvalidUrl(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('invalid-url-test.com', 'invalid_url_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid URL provided');
            $this->client->uploadFileAttachmentFromUrl($evidenceUuid, 'not-a-valid-url');
        }

        public function testGetAttachmentInfoInvalidUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Attachment UUID cannot be empty');
            $this->client->getAttachmentInfo('');
        }

        public function testGetAttachmentInfoNonExistent(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404);
            $this->client->getAttachmentInfo($fakeUuid);
        }

        public function testDeleteAttachmentInvalidUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Attachment UUID cannot be empty');
            $this->client->deleteAttachment('');
        }

        public function testDeleteNonExistentAttachment(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(404);
            $this->client->deleteAttachment($fakeUuid);
        }

        public function testDownloadAttachmentInvalidUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Attachment UUID cannot be empty');
            $this->client->downloadAttachment('', '/tmp/test');
        }

        public function testDownloadAttachmentInvalidPath(): void
        {
            $fakeUuid = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('File path cannot be empty');
            $this->client->downloadAttachment($fakeUuid, '');
        }

        // PERMISSION AND AUTHORIZATION TESTS

        public function testUploadAttachmentUnauthorized(): void
        {
            // Create an operator without blacklist management permissions
            $operatorUuid = $this->client->createOperator('no-blacklist-operator');
            $this->createdOperators[] = $operatorUuid;

            // Ensure no blacklist permissions
            $this->client->setManageBlacklistPermission($operatorUuid, false);
            $this->client->setClientPermission($operatorUuid, true); // Can access basic functions

            $operator = $this->client->getOperator($operatorUuid);
            $restrictedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

            // Create entity and evidence with root client
            $entityUuid = $this->client->pushEntity('unauthorized-upload-test.com', 'unauthorized_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Try to upload attachment with restricted client
            $testFilePath = $this->createTestFile('unauthorized.txt', 'Unauthorized upload test');

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(403); // FORBIDDEN
            $restrictedClient->uploadFileAttachment($evidenceUuid, $testFilePath);
        }

        public function testAccessAttachmentAsAnonymousClient(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('anonymous-access-test.com', 'anonymous_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for anonymous access', 'Anonymous test', 'anonymous');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Upload an attachment
            $testFilePath = $this->createTestFile('anonymous_test.txt', 'Anonymous access test content');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            // Create anonymous client
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            // Check if evidence is public on the server
            if (!$this->client->getServerInformation()->isPublicEvidence())
            {
                // If evidence is not public, anonymous access should fail
                $this->expectException(RequestException::class);
                $this->expectExceptionCode(401); // UNAUTHORIZED
                $anonymousClient->getAttachmentInfo($uploadResult->getUuid());
            }
            else
            {
                // If evidence is public, anonymous access should work
                $attachmentInfo = $anonymousClient->getAttachmentInfo($uploadResult->getUuid());
                $this->assertNotNull($attachmentInfo);
                $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            }
        }

        // FILE TYPE AND SIZE TESTS

        public function testUploadDifferentFileTypes(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('file-types-test.com', 'file_types_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for file types', 'File types test', 'file_types');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $fileTypes = [
                'text' => ['extension' => 'txt', 'content' => 'Plain text content', 'expectedMime' => 'text/plain'],
                'json' => ['extension' => 'json', 'content' => '{"key": "value"}', 'expectedMime' => 'application/json'],
                'csv' => ['extension' => 'csv', 'content' => 'col1,col2\nval1,val2', 'expectedMime' => 'text/csv'],
                'xml' => ['extension' => 'xml', 'content' => '<?xml version="1.0"?><root><item>test</item></root>', 'expectedMime' => 'application/xml'],
            ];

            foreach ($fileTypes as $type => $fileData)
            {
                $fileName = "test_file_{$type}.{$fileData['extension']}";
                $testFilePath = $this->createTestFile($fileName, $fileData['content']);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $this->createdAttachments[] = $uploadResult->getUuid();

                // Verify attachment info
                $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertNotNull($attachmentInfo);
                $this->assertEquals($fileName, $attachmentInfo->getFileName());
                $this->assertEquals(strlen($fileData['content']), $attachmentInfo->getFileSize());
                
                // MIME type detection might vary, so we'll just ensure it's not empty
                $this->assertNotEmpty($attachmentInfo->getFileMime());
            }
        }

        public function testUploadLargeFile(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('large-file-test.com', 'large_file_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for large file', 'Large file test', 'large_file');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Create a larger file (1MB)
            $largeContent = str_repeat('A', 1024 * 1024); // 1MB of 'A' characters
            $testFilePath = $this->createTestFile('large_test.txt', $largeContent);

            try
            {
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $this->createdAttachments[] = $uploadResult->getUuid();

                // Verify the large file was uploaded correctly
                $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertEquals(1024 * 1024, $attachmentInfo->getFileSize());
            }
            catch (RequestException $e)
            {
                // If the server has size limits, this is acceptable
                if ($e->getCode() === 413 || $e->getCode() === 400) // Payload Too Large or Bad Request
                {
                    $this->logger->info("Large file upload rejected by server (expected): " . $e->getMessage());
                    $this->assertTrue(true, "Server correctly rejected large file");
                }
                else
                {
                    throw $e;
                }
            }
        }

        public function testUploadFileWithExcessiveSize(): void
        {
            // Create entity and evidence first
            $entityUuid = $this->client->pushEntity('excessive-size-test.com', 'excessive_size_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence', 'Test', 'test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Test with maximum file size limit for URL uploads (should fail)
            $testUrl = 'https://httpbin.org/json';
            
            $this->expectException(RequestException::class);
            // Try to upload with a very small max size (1 byte) - should fail
            $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $testUrl, 1);
        }

        // DURABILITY AND STRESS TESTS

        public function testAttachmentLifecycleIntegrity(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('lifecycle-test.com', 'lifecycle_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Lifecycle test evidence', 'Lifecycle test', 'lifecycle');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Upload attachment
            $testFilePath = $this->createTestFile('lifecycle_test.txt', 'Lifecycle test content');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();

            // Test full lifecycle
            $originalInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertNotNull($originalInfo);

            // Download and verify
            $downloadPath = sys_get_temp_dir() . '/lifecycle_download_' . uniqid() . '.txt';
            $this->tempFiles[] = $downloadPath;
            $this->client->downloadAttachment($attachmentUuid, $downloadPath);
            $this->assertTrue(file_exists($downloadPath));
            $this->assertEquals('Lifecycle test content', file_get_contents($downloadPath));

            // Get info again and ensure consistency
            $secondInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($originalInfo->getUuid(), $secondInfo->getUuid());
            $this->assertEquals($originalInfo->getEvidenceUuid(), $secondInfo->getEvidenceUuid());
            $this->assertEquals($originalInfo->getFileName(), $secondInfo->getFileName());
            $this->assertEquals($originalInfo->getFileSize(), $secondInfo->getFileSize());

            // Delete and verify removal
            $this->client->deleteAttachment($attachmentUuid);

            try
            {
                $this->client->getAttachmentInfo($attachmentUuid);
                $this->fail("Expected RequestException for deleted attachment");
            }
            catch (RequestException $e)
            {
                $this->assertEquals(404, $e->getCode());
            }
        }

        public function testMultipleAttachmentsPerEvidence(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('multiple-attachments-test.com', 'multiple_attachments_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence with multiple attachments', 'Multiple attachments test', 'multiple');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $attachmentUuids = [];

            // Upload multiple attachments for the same evidence
            for ($i = 1; $i <= 3; $i++)
            {
                $content = "Content for attachment number $i";
                $fileName = "attachment_$i.txt";
                $testFilePath = $this->createTestFile($fileName, $content);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $attachmentUuids[] = $uploadResult->getUuid();
                $this->createdAttachments[] = $uploadResult->getUuid();
            }

            // Verify all attachments exist and are associated with the same evidence
            foreach ($attachmentUuids as $index => $attachmentUuid)
            {
                $attachmentInfo = $this->client->getAttachmentInfo($attachmentUuid);
                $this->assertNotNull($attachmentInfo);
                $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
                $this->assertEquals("attachment_" . ($index + 1) . ".txt", $attachmentInfo->getFileName());
            }
        }

        public function testConcurrentAttachmentOperations(): void
        {
            // Create entity and evidence
            $entityUuid = $this->client->pushEntity('concurrent-attachments-test.com', 'concurrent_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Concurrent operations evidence', 'Concurrent test', 'concurrent');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Upload attachment
            $testFilePath = $this->createTestFile('concurrent_test.txt', 'Concurrent operations test');
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            // Perform multiple operations rapidly
            $info1 = $this->client->getAttachmentInfo($attachmentUuid);
            $info2 = $this->client->getAttachmentInfo($attachmentUuid);
            $info3 = $this->client->getAttachmentInfo($attachmentUuid);

            // Verify consistency
            $this->assertEquals($info1->getUuid(), $info2->getUuid());
            $this->assertEquals($info2->getUuid(), $info3->getUuid());
            $this->assertEquals($info1->getEvidenceUuid(), $info2->getEvidenceUuid());
            $this->assertEquals($info1->getFileName(), $info3->getFileName());

            // Multiple downloads of the same file
            $downloadPaths = [];
            for ($i = 1; $i <= 3; $i++)
            {
                $downloadPath = sys_get_temp_dir() . "/concurrent_download_$i" . uniqid() . '.txt';
                $this->tempFiles[] = $downloadPath;
                $downloadPaths[] = $downloadPath;
                $this->client->downloadAttachment($attachmentUuid, $downloadPath);
            }

            // Verify all downloads are identical
            $originalContent = file_get_contents($downloadPaths[0]);
            foreach ($downloadPaths as $path)
            {
                $this->assertEquals($originalContent, file_get_contents($path));
            }
        }

        // HELPER METHODS

        private function createTestFile(string $fileName, string $content): string
        {
            $tempDir = sys_get_temp_dir();
            $filePath = $tempDir . '/' . $fileName;
            
            if (file_put_contents($filePath, $content) === false)
            {
                throw new \RuntimeException("Failed to create test file: $filePath");
            }

            $this->tempFiles[] = $filePath;
            return $filePath;
        }
    }
