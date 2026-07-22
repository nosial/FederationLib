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

    class AttachmentsTest extends TestCase
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

        public function testUploadFileAttachment(): void
        {
            $entityUuid = $this->client->pushEntity('attachment-test.com', 'attachment_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for attachment', 'Attachment test', 'attachment');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('test_attachment.txt', 'This is test content for file attachment.');

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->assertNotNull($uploadResult);
            $this->assertNotEmpty($uploadResult->getUuid());
            $this->assertNotEmpty($uploadResult->getUrl());
            $this->createdAttachments[] = $uploadResult->getUuid();

            $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
            $this->assertNotNull($attachmentInfo);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            $this->assertEquals('test_attachment.txt', $attachmentInfo->getFileName());
            $this->assertGreaterThan(0, $attachmentInfo->getFileSize());
            $this->assertNotEmpty($attachmentInfo->getFileMime());
        }

        public function testUploadFileAttachmentFromUrl(): void
        {
            $entityUuid = $this->client->pushEntity('url-attachment-test.com', 'url_attachment_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for URL attachment', 'URL attachment test', 'url_attachment');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $sourceFileName = 'source_attachment.txt';
            $sourceFilePath = $this->createHttpServerFile($sourceFileName, 'Source content for URL attachment upload.');
            $sourceUrl = sprintf('http://127.0.0.1:%d/%s', $this->httpServerPort, $sourceFileName);

            $uploadResult = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $sourceUrl);
            $this->assertNotNull($uploadResult);
            $this->assertNotEmpty($uploadResult->getUuid());
            $this->assertNotEmpty($uploadResult->getUrl());
            $this->createdAttachments[] = $uploadResult->getUuid();

            $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
            $this->assertNotNull($attachmentInfo);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            $this->assertGreaterThan(0, $attachmentInfo->getFileSize());
        }

        public function testDownloadAttachment(): void
        {
            $entityUuid = $this->client->pushEntity('download-test.com', 'download_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for download', 'Download test', 'download');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $originalContent = 'This is the original content for download test.';
            $testFilePath = $this->createTestFile('download_test.txt', $originalContent);

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $downloadPath = sys_get_temp_dir();
            $downloadedFile = $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);
            $this->tempFiles[] = $downloadedFile;

            $this->assertTrue(file_exists($downloadedFile));
            $this->assertEquals($originalContent, file_get_contents($downloadedFile));
        }

        public function testDeleteAttachment(): void
        {
            $entityUuid = $this->client->pushEntity('delete-attachment-test.com', 'delete_attachment_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for delete', 'Delete attachment test', 'delete_attachment');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = $this->createTestFile('delete_test.txt', 'This file will be deleted.');

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();

            $attachmentInfo = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertNotNull($attachmentInfo);

            $this->client->deleteAttachment($attachmentUuid);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getAttachmentInfo($attachmentUuid);
        }

        public function testUploadAttachmentInvalidEvidenceUuid(): void
        {
            $testFilePath = $this->createTestFile('invalid_evidence.txt', 'Test content');

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Evidence UUID cannot be empty');
            $this->client->uploadFileAttachment('', $testFilePath);
        }

        public function testUploadAttachmentNonExistentFile(): void
        {
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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
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
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteAttachment($fakeUuid);
        }

        public function testDownloadAttachmentInvalidUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Attachment UUID cannot be empty');
            $this->client->downloadAttachment('', '/tmp/test');
        }

        public function testUploadDifferentFileTypes(): void
        {
            $entityUuid = $this->client->pushEntity('file-types-test.com', 'file_types_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test evidence for file types', 'File types test', 'file_types');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $fileTypes = [
                'text' => ['extension' => 'txt', 'content' => 'Plain text content'],
                'json' => ['extension' => 'json', 'content' => '{"key": "value"}'],
                'csv' => ['extension' => 'csv', 'content' => "col1,col2\nval1,val2"],
                'xml' => ['extension' => 'xml', 'content' => '<?xml version="1.0"?><root><item>test</item></root>'],
            ];

            foreach ($fileTypes as $type => $fileData)
            {
                $fileName = "test_file_{$type}.{$fileData['extension']}";
                $testFilePath = $this->createTestFile($fileName, $fileData['content']);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertNotNull($attachmentInfo);
                $this->assertEquals($fileName, $attachmentInfo->getFileName());
                $this->assertEquals(strlen($fileData['content']), $attachmentInfo->getFileSize());
                $this->assertNotEmpty($attachmentInfo->getFileMime());
            }
        }

        public function testGetEvidenceAttachments(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-attachments-test.com', 'evidence_attachments_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence with attachments', 'Evidence attachments test', 'evidence_attachments');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $attachmentUuids = [];

            for ($i = 1; $i <= 2; $i++)
            {
                $content = "Content for evidence attachment number $i";
                $fileName = "evidence_attachment_$i.txt";
                $testFilePath = $this->createTestFile($fileName, $content);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $attachmentUuids[] = $uploadResult->getUuid();
                $this->createdAttachments[] = $uploadResult->getUuid();
            }

            $attachments = $this->client->getEvidenceAttachments($evidenceUuid);
            $this->assertCount(2, $attachments);

            foreach ($attachments as $attachment)
            {
                $this->assertContains($attachment->getUuid(), $attachmentUuids);
                $this->assertEquals($evidenceUuid, $attachment->getEvidenceUuid());
            }
        }

        public function testListAttachments(): void
        {
            $entityUuid = $this->client->pushEntity('list-attachments-test.com', 'list_attachments_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for listing attachments', 'List attachments test', 'list_attachments');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $attachmentUuids = [];

            for ($i = 1; $i <= 3; $i++)
            {
                $content = "Content for list attachment number $i";
                $fileName = "list_attachment_$i.txt";
                $testFilePath = $this->createTestFile($fileName, $content);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $attachmentUuids[] = $uploadResult->getUuid();
                $this->createdAttachments[] = $uploadResult->getUuid();
            }

            $attachments = $this->client->listAttachments(1, 100);
            $this->assertIsArray($attachments);
            $this->assertGreaterThanOrEqual(3, count($attachments));

            $listedUuids = array_map(fn($a) => $a->getUuid(), $attachments);
            foreach ($attachmentUuids as $uuid)
            {
                $this->assertContains($uuid, $listedUuids);
            }
        }

        public function testListAttachmentsPagination(): void
        {
            $entityUuid = $this->client->pushEntity('list-attachments-paginate.com', 'paginate_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence for paginated listing', 'Pagination test', 'pagination');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            for ($i = 1; $i <= 5; $i++)
            {
                $content = "Pagination content $i";
                $fileName = "paginate_attachment_$i.txt";
                $testFilePath = $this->createTestFile($fileName, $content);

                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $this->createdAttachments[] = $uploadResult->getUuid();
            }

            $limitedAttachments = $this->client->listAttachments(1, 2);
            $this->assertCount(2, $limitedAttachments);

            $pageTwoAttachments = $this->client->listAttachments(2, 2);
            $this->assertCount(2, $pageTwoAttachments);

            $pageOneUuids = array_map(fn($a) => $a->getUuid(), $limitedAttachments);
            $pageTwoUuids = array_map(fn($a) => $a->getUuid(), $pageTwoAttachments);
            foreach ($pageOneUuids as $uuid)
            {
                $this->assertNotContains($uuid, $pageTwoUuids);
            }
        }

        public function testListAttachmentsInvalidParameters(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Page must be greater than 0');
            $this->client->listAttachments(0);
        }

        public function testListAttachmentsInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Limit must be greater than 0');
            $this->client->listAttachments(1, 0);
        }

        public function testListAttachmentsSortByFileSizeDescending(): void
        {
            $entityUuid = $this->client->pushEntity('attachment-sort-size.com', 'att_size_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Attachment size sort', 'Note', 'att_size_sort');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $sizes = [100, 300, 200];
            $attachmentUuids = [];
            foreach ($sizes as $size)
            {
                $content = str_repeat('x', $size);
                $filePath = $this->createTestFile("att_size_$size.txt", $content);
                $result = $this->client->uploadFileAttachment($evidenceUuid, $filePath);
                $this->createdAttachments[] = $result->getUuid();
                $attachmentUuids[] = $result->getUuid();
            }

            $attachments = $this->client->listAttachments(1, 100, null, 'file_size', 'DESC');
            $filtered = array_values(array_filter($attachments, fn($a) => in_array($a->getUuid(), $attachmentUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals(300, $filtered[0]->getFileSize());
            $this->assertEquals(200, $filtered[1]->getFileSize());
            $this->assertEquals(100, $filtered[2]->getFileSize());
        }

        public function testListAttachmentsSortByFileNameAscending(): void
        {
            $entityUuid = $this->client->pushEntity('attachment-sort-name.com', 'att_name_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Attachment name sort', 'Note', 'att_name_sort');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $files = ['c_file.txt', 'a_file.txt', 'b_file.txt'];
            $attachmentUuids = [];
            foreach ($files as $fileName)
            {
                $filePath = $this->createTestFile($fileName, 'content for ' . $fileName);
                $result = $this->client->uploadFileAttachment($evidenceUuid, $filePath, $fileName);
                $this->createdAttachments[] = $result->getUuid();
                $attachmentUuids[] = $result->getUuid();
            }

            $attachments = $this->client->listAttachments(1, 100, null, 'file_name', 'ASC');
            $filtered = array_values(array_filter($attachments, fn($a) => in_array($a->getUuid(), $attachmentUuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertStringEndsWith('a_file.txt', $filtered[0]->getFileName());
            $this->assertStringEndsWith('b_file.txt', $filtered[1]->getFileName());
            $this->assertStringEndsWith('c_file.txt', $filtered[2]->getFileName());
        }

        public function testListAttachmentsSortInvalidOrderFallsBack(): void
        {
            $resultDefault = $this->client->listAttachments(1, 10, null, 'created', 'DESC');
            $resultInvalid = $this->client->listAttachments(1, 10, null, 'created', 'NONEXISTENT');

            $defaultUuids = array_map(fn($a) => $a->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($a) => $a->getUuid(), $resultInvalid);

            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testBinaryAttachmentDownloadIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('binary-attachment-test.com', 'binary_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Binary attachment evidence', 'Note', 'binary');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $binaryContent = random_bytes(1024);
            $testFilePath = $this->createTestFile('binary_test.bin', $binaryContent);

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $this->createdAttachments[] = $uploadResult->getUuid();

            $downloadPath = sys_get_temp_dir();
            $downloadedFile = $this->client->downloadAttachment($uploadResult->getUuid(), $downloadPath);
            $this->tempFiles[] = $downloadedFile;

            $this->assertEquals(strlen($binaryContent), filesize($downloadedFile));
            $this->assertEquals($binaryContent, file_get_contents($downloadedFile));
        }

        public function testAttachmentMimeTypeDetection(): void
        {
            $entityUuid = $this->client->pushEntity('mime-type-test.com', 'mime_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'MIME type evidence', 'Note', 'mime');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $cases = [
                'text/plain' => ['plain.txt', 'Plain text content'],
                'application/json' => ['data.json', '{"key":"value"}'],
                'text/csv' => ['data.csv', "a,b\n1,2"],
                'text/xml' => ['data.xml', '<?xml version="1.0"?><root/>'],
            ];

            foreach ($cases as $expectedMime => $case)
            {
                $testFilePath = $this->createTestFile($case[0], $case[1]);
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $info = $this->client->getAttachmentInfo($uploadResult->getUuid());
                $this->assertStringStartsWith(
                    explode('/', $expectedMime)[0],
                    $info->getFileMime(),
                    "MIME type for {$case[0]} should start with expected type"
                );
            }
        }

        public function testListAttachmentsCategoryImage(): void
        {
            $entityUuid = $this->client->pushEntity('att-cat-img.com', 'att_img_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Image cat attachment', 'Note', 'att_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $imagePath = $this->createMinimalPng();
            $imageResult = $this->client->uploadFileAttachment($evidenceUuid, $imagePath);
            $this->createdAttachments[] = $imageResult->getUuid();

            $textContent = str_repeat('x', 100);
            $textPath = $this->createTestFile('test_doc.txt', $textContent);
            $textResult = $this->client->uploadFileAttachment($evidenceUuid, $textPath);
            $this->createdAttachments[] = $textResult->getUuid();

            $attachments = $this->client->listAttachments(1, 100, 'IMAGE');
            $uuids = array_map(fn($a) => $a->getUuid(), $attachments);

            $this->assertContains($imageResult->getUuid(), $uuids);
            $this->assertNotContains($textResult->getUuid(), $uuids);
        }

        public function testListAttachmentsCategoryDocument(): void
        {
            $entityUuid = $this->client->pushEntity('att-cat-doc.com', 'att_doc_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Doc cat attachment', 'Note', 'att_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $textContent = str_repeat('x', 100);
            $textPath = $this->createTestFile('test_doc.txt', $textContent);
            $textResult = $this->client->uploadFileAttachment($evidenceUuid, $textPath);
            $this->createdAttachments[] = $textResult->getUuid();

            $imagePath = $this->createMinimalPng();
            $imageResult = $this->client->uploadFileAttachment($evidenceUuid, $imagePath);
            $this->createdAttachments[] = $imageResult->getUuid();

            $attachments = $this->client->listAttachments(1, 100, 'DOCUMENT');
            $uuids = array_map(fn($a) => $a->getUuid(), $attachments);

            $this->assertContains($textResult->getUuid(), $uuids);
            $this->assertNotContains($imageResult->getUuid(), $uuids);
        }

        public function testListAttachmentsCategoryWithSort(): void
        {
            $entityUuid = $this->client->pushEntity('att-cat-sort.com', 'att_cat_sort_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Att cat sort', 'Note', 'att_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $sizes = [200, 100];
            $uuids = [];
            foreach ($sizes as $size)
            {
                $content = str_repeat('x', $size);
                $filePath = $this->createTestFile("att_cat_$size.txt", $content);
                $result = $this->client->uploadFileAttachment($evidenceUuid, $filePath);
                $this->createdAttachments[] = $result->getUuid();
                $uuids[] = $result->getUuid();
            }

            $attachments = $this->client->listAttachments(1, 100, 'DOCUMENT', 'file_size', 'DESC');
            $filtered = array_values(array_filter($attachments, fn($a) => in_array($a->getUuid(), $uuids, true)));

            $this->assertCount(2, $filtered);
            $this->assertEquals(200, $filtered[0]->getFileSize());
            $this->assertEquals(100, $filtered[1]->getFileSize());
        }

        public function testListAttachmentsCategoryInvalidFallsBack(): void
        {
            $entityUuid = $this->client->pushEntity('att-cat-inv.com', 'att_cat_inv_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Cat fallback test', 'Note', 'att_cat');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $content = str_repeat('x', 50);
            $filePath = $this->createTestFile('fallback.txt', $content);
            $result = $this->client->uploadFileAttachment($evidenceUuid, $filePath);
            $this->createdAttachments[] = $result->getUuid();

            $resultDefault = $this->client->listAttachments(1, 10);
            $resultInvalid = $this->client->listAttachments(1, 10, 'BOGUS_CATEGORY');

            $defaultUuids = array_map(fn($a) => $a->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($a) => $a->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListAttachmentsCategoryCaseInsensitive(): void
        {
            $entityUuid = $this->client->pushEntity('att-cat-ci.com', 'att_ci_' . uniqid());
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'CI test', 'Note', 'att_ci');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $content = str_repeat('x', 50);
            $filePath = $this->createTestFile('ci_test.txt', $content);
            $result = $this->client->uploadFileAttachment($evidenceUuid, $filePath);
            $this->createdAttachments[] = $result->getUuid();

            $resultUpper = $this->client->listAttachments(1, 10, 'DOCUMENT');
            $resultLower = $this->client->listAttachments(1, 10, 'document');
            $resultMixed = $this->client->listAttachments(1, 10, 'Document');

            $upperUuids = array_map(fn($a) => $a->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($a) => $a->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($a) => $a->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
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
