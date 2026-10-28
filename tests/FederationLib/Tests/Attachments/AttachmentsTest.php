<?php

    namespace FederationLib\Tests\Attachments;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Helpers\AttachmentFiles;
    use FederationLib\Helpers\ServerTestCase;
    use InvalidArgumentException;

    class AttachmentsTest extends ServerTestCase
    {
        use AttachmentFiles;

        /**
         * Creates an evidence record of a new entity to attach files to
         */
        private function createEvidence(): string
        {
            return $this->createSecurityEvidence($this->createSecurityEntity());
        }

        /**
         * Uploads a file with the given name and content and returns the UUID of the attachment
         */
        private function upload(string $evidenceUuid, string $fileName, string $content): string
        {
            $attachmentUuid = $this->client->uploadFileAttachment($evidenceUuid, $this->createTestFile($fileName, $content))->getUuid();
            $this->createdAttachments[] = $attachmentUuid;
            return $attachmentUuid;
        }

        public function testUploadFileAttachment(): void
        {
            $evidenceUuid = $this->createEvidence();
            $content = 'This is test content for file attachment.';

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $this->createTestFile('test_attachment.txt', $content));
            $this->createdAttachments[] = $uploadResult->getUuid();
            $this->assertNotEmpty($uploadResult->getUrl());

            $attachmentInfo = $this->client->getAttachmentInfo($uploadResult->getUuid());
            $this->assertEquals($uploadResult->getUuid(), $attachmentInfo->getUuid());
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
            $this->assertEquals('test_attachment.txt', $attachmentInfo->getFileName());
            $this->assertEquals(strlen($content), $attachmentInfo->getFileSize());
            $this->assertEquals('text/plain', $attachmentInfo->getFileMime());
        }

        public function testUploadedFileTypesKeepNameSizeAndMediaType(): void
        {
            $evidenceUuid = $this->createEvidence();

            // The media type is detected from the content, its top-level type is asserted
            $cases = [
                ['plain.txt', 'Plain text content', 'text/'],
                ['data.json', '{"key": "value"}', 'application/'],
                ['data.csv', "col1,col2\nval1,val2", 'text/'],
                ['data.xml', '<?xml version="1.0"?><root><item>test</item></root>', 'text/'],
            ];

            foreach ($cases as [$fileName, $content, $mediaType])
            {
                $info = $this->client->getAttachmentInfo($this->upload($evidenceUuid, $fileName, $content));
                $this->assertEquals($fileName, $info->getFileName());
                $this->assertEquals(strlen($content), $info->getFileSize());
                $this->assertStringStartsWith($mediaType, $info->getFileMime(), $fileName);
            }
        }

        public function testBinaryAttachmentDownloadIntegrity(): void
        {
            $evidenceUuid = $this->createEvidence();
            $binaryContent = random_bytes(1024);
            $attachmentUuid = $this->upload($evidenceUuid, 'binary_test.bin', $binaryContent);

            $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $this->createTemporaryDirectory());

            $this->assertSame($binaryContent, file_get_contents($downloadedFile));
        }

        public function testClientValidatesArguments(): void
        {
            $evidenceUuid = $this->createEvidence();
            $file = $this->createTestFile('valid.txt', 'Valid content');

            $calls = [
                'Evidence UUID cannot be empty' => fn() => $this->client->uploadFileAttachment('', $file),
                'File does not exist' => fn() => $this->client->uploadFileAttachment($evidenceUuid, '/non/existent/file.txt'),
                'Invalid URL provided' => fn() => $this->client->uploadFileAttachmentFromUrl($evidenceUuid, 'not-a-valid-url'),
                'Attachment UUID cannot be empty' => fn() => $this->client->getAttachmentInfo(''),
                'Page must be greater than 0' => fn() => $this->client->listAttachments(0),
                'Limit must be greater than 0' => fn() => $this->client->listAttachments(1, 0),
            ];

            foreach ($calls as $message => $call)
            {
                try
                {
                    $call();
                    $this->fail("Expected the client to reject the arguments: $message");
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertStringContainsString($message, $e->getMessage());
                }
            }

            foreach ([fn() => $this->client->deleteAttachment(''), fn() => $this->client->downloadAttachment('', sys_get_temp_dir())] as $call)
            {
                try
                {
                    $call();
                    $this->fail('Expected the client to reject an empty attachment UUID');
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertStringContainsString('Attachment UUID cannot be empty', $e->getMessage());
                }
            }
        }

        public function testUnknownAttachmentIsNotFound(): void
        {
            $unknownUuid = $this->randomUuid();

            foreach ([
                'info' => fn() => $this->client->getAttachmentInfo($unknownUuid),
                'delete' => fn() => $this->client->deleteAttachment($unknownUuid),
                'download' => fn() => $this->client->downloadAttachment($unknownUuid, $this->createTemporaryDirectory()),
            ] as $operation => $call)
            {
                $this->expectRequestFailure($call, [HttpResponseCode::NOT_FOUND->value], "The $operation of an unknown attachment should not be found");
            }
        }

        public function testListAttachments(): void
        {
            $evidenceUuid = $this->createEvidence();
            $attachmentUuids = [];
            for ($i = 1; $i <= 3; $i++)
            {
                $attachmentUuids[] = $this->upload($evidenceUuid, "list_attachment_$i.txt", "Content for list attachment number $i");
            }

            $listedUuids = array_map(fn($attachment) => $attachment->getUuid(), $this->client->listAttachments(1, 100));
            foreach ($attachmentUuids as $uuid)
            {
                $this->assertContains($uuid, $listedUuids);
            }
        }

        public function testListAttachmentsPagination(): void
        {
            $evidenceUuid = $this->createEvidence();
            for ($i = 1; $i <= 5; $i++)
            {
                $this->upload($evidenceUuid, "paginate_attachment_$i.txt", "Pagination content $i");
            }

            $pageOne = array_map(fn($attachment) => $attachment->getUuid(), $this->client->listAttachments(1, 2));
            $pageTwo = array_map(fn($attachment) => $attachment->getUuid(), $this->client->listAttachments(2, 2));

            $this->assertCount(2, $pageOne);
            $this->assertCount(2, $pageTwo);
            $this->assertSame([], array_intersect($pageOne, $pageTwo));
        }

        public function testListAttachmentsSortByFileSizeDescending(): void
        {
            $evidenceUuid = $this->createEvidence();
            $attachmentUuids = [];
            foreach ([100, 300, 200] as $size)
            {
                $attachmentUuids[] = $this->upload($evidenceUuid, "att_size_$size.txt", str_repeat('x', $size));
            }

            $attachments = $this->client->listAttachments(1, 100, null, 'file_size', 'DESC');
            $filtered = array_values(array_filter($attachments, fn($a) => in_array($a->getUuid(), $attachmentUuids, true)));

            $this->assertSame([300, 200, 100], array_map(fn($a) => $a->getFileSize(), $filtered));
        }

        public function testListAttachmentsSortByFileNameAscending(): void
        {
            $evidenceUuid = $this->createEvidence();
            $attachmentUuids = [];
            foreach (['c_file.txt', 'a_file.txt', 'b_file.txt'] as $fileName)
            {
                $attachmentUuids[] = $this->upload($evidenceUuid, $fileName, 'content for ' . $fileName);
            }

            $attachments = $this->client->listAttachments(1, 100, null, 'file_name', 'ASC');
            $filtered = array_values(array_filter($attachments, fn($a) => in_array($a->getUuid(), $attachmentUuids, true)));

            $this->assertSame(['a_file.txt', 'b_file.txt', 'c_file.txt'], array_map(fn($a) => $a->getFileName(), $filtered));
        }

        public function testListAttachmentsSortInvalidOrderFallsBack(): void
        {
            $this->upload($this->createEvidence(), 'order.txt', 'Order fallback');

            $resultDefault = $this->client->listAttachments(1, 10, null, 'created', 'DESC');
            $resultInvalid = $this->client->listAttachments(1, 10, null, 'created', 'NONEXISTENT');

            $this->assertNotEmpty($resultDefault);
            $this->assertSame(
                array_map(fn($a) => $a->getUuid(), $resultDefault),
                array_map(fn($a) => $a->getUuid(), $resultInvalid)
            );
        }

        public function testListAttachmentsCategoryFiltersByMediaType(): void
        {
            $evidenceUuid = $this->createEvidence();
            $imageUuid = $this->client->uploadFileAttachment($evidenceUuid, $this->createMinimalPng())->getUuid();
            $this->createdAttachments[] = $imageUuid;
            $documentUuid = $this->upload($evidenceUuid, 'test_doc.txt', str_repeat('x', 100));

            $images = array_map(fn($a) => $a->getUuid(), $this->client->listAttachments(1, 100, 'IMAGE'));
            $this->assertContains($imageUuid, $images);
            $this->assertNotContains($documentUuid, $images);

            $documents = array_map(fn($a) => $a->getUuid(), $this->client->listAttachments(1, 100, 'DOCUMENT'));
            $this->assertContains($documentUuid, $documents);
            $this->assertNotContains($imageUuid, $documents);
        }

        public function testListAttachmentsCategoryWithSort(): void
        {
            $evidenceUuid = $this->createEvidence();
            $attachmentUuids = [];
            foreach ([200, 100] as $size)
            {
                $attachmentUuids[] = $this->upload($evidenceUuid, "att_cat_$size.txt", str_repeat('x', $size));
            }

            $attachments = $this->client->listAttachments(1, 100, 'DOCUMENT', 'file_size', 'DESC');
            $filtered = array_values(array_filter($attachments, fn($a) => in_array($a->getUuid(), $attachmentUuids, true)));

            $this->assertSame([200, 100], array_map(fn($a) => $a->getFileSize(), $filtered));
        }

        public function testListAttachmentsCategoryInvalidFallsBack(): void
        {
            $this->upload($this->createEvidence(), 'fallback.txt', str_repeat('x', 50));

            $resultDefault = $this->client->listAttachments(1, 10);
            $resultInvalid = $this->client->listAttachments(1, 10, 'BOGUS_CATEGORY');

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame(
                array_map(fn($a) => $a->getUuid(), $resultDefault),
                array_map(fn($a) => $a->getUuid(), $resultInvalid)
            );
        }

        public function testListAttachmentsCategoryCaseInsensitive(): void
        {
            $this->upload($this->createEvidence(), 'ci_test.txt', str_repeat('x', 50));

            $upperUuids = array_map(fn($a) => $a->getUuid(), $this->client->listAttachments(1, 10, 'DOCUMENT'));
            $this->assertNotEmpty($upperUuids);

            foreach (['document', 'Document'] as $category)
            {
                $this->assertSame($upperUuids, array_map(fn($a) => $a->getUuid(), $this->client->listAttachments(1, 10, $category)), $category);
            }
        }
    }
