<?php

    namespace FederationLib\Tests\Attachments;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Helpers\AttachmentFiles;
    use FederationLib\Helpers\ServerTestCase;

    class AttachmentsLogicTest extends ServerTestCase
    {
        use AttachmentFiles;

        public function testUploadLargeFile(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            // Well within the default maximum upload size of 50 MB
            $largeContent = random_bytes(1024 * 1024);
            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $this->createTestFile('large_test.bin', $largeContent));
            $this->createdAttachments[] = $uploadResult->getUuid();

            $this->assertEquals(strlen($largeContent), $this->client->getAttachmentInfo($uploadResult->getUuid())->getFileSize());
            $downloadedFile = $this->client->downloadAttachment($uploadResult->getUuid(), $this->createTemporaryDirectory());
            $this->assertSame($largeContent, file_get_contents($downloadedFile));
        }

        public function testAttachmentLifecycleIntegrity(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $content = 'Lifecycle test content';

            $attachmentUuid = $this->client->uploadFileAttachment($evidenceUuid, $this->createTestFile('lifecycle_test.txt', $content))->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $info = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($evidenceUuid, $info->getEvidenceUuid());
            $this->assertEquals('lifecycle_test.txt', $info->getFileName());

            $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $this->createTemporaryDirectory());
            $this->assertEquals($content, file_get_contents($downloadedFile));

            $this->client->deleteAttachment($attachmentUuid);
            $this->removeFromCleanup($this->createdAttachments, $attachmentUuid);

            $this->expectRequestFailure(
                fn() => $this->client->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'A deleted attachment should no longer be found'
            );
            $this->expectRequestFailure(
                fn() => $this->client->downloadAttachment($attachmentUuid, $this->createTemporaryDirectory()),
                [HttpResponseCode::NOT_FOUND->value],
                'A deleted attachment should no longer be downloadable'
            );

            // The evidence remains
            $this->assertEquals($evidenceUuid, $this->client->getEvidenceRecord($evidenceUuid)->getUuid());
        }

        public function testAttachmentUploadFromUrlLifecycle(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $sourceContent = 'Lifecycle source content for URL upload.';
            $sourceUrl = $this->serveFile('lifecycle_source.txt', $sourceContent);

            $attachmentUuid = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $sourceUrl)->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $info = $this->client->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($evidenceUuid, $info->getEvidenceUuid());
            $this->assertEquals(strlen($sourceContent), $info->getFileSize());

            $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $this->createTemporaryDirectory());
            $this->assertEquals($sourceContent, file_get_contents($downloadedFile));
        }

        public function testUploadFromUrlFollowsRedirects(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $sourceContent = 'Redirect source content.';
            $sourceUrl = $this->serveFile('redirect_source.txt', $sourceContent);
            $redirectUrl = $this->serveFile('redirect.php', sprintf("<?php header('Location: %s'); exit;", $sourceUrl));

            // The client retrieves the file itself and follows up to 5 redirects
            $attachmentUuid = $this->client->uploadFileAttachmentFromUrl($evidenceUuid, $redirectUrl)->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $this->assertEquals(strlen($sourceContent), $this->client->getAttachmentInfo($attachmentUuid)->getFileSize());
            $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $this->createTemporaryDirectory());
            $this->assertEquals($sourceContent, file_get_contents($downloadedFile));
        }

        public function testUploadNoteAttachmentContentVariations(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $contents = [
                'Plain text note',
                "Multi\nline\nnote",
                'Unicode note: 你好 🌍',
                str_repeat('Long note ', 100),
            ];

            foreach ($contents as $content)
            {
                $attachmentUuid = $this->client->uploadNoteAttachment($evidenceUuid, 'note', $content)->getUuid();
                $this->createdAttachments[] = $attachmentUuid;

                $this->assertEquals(strlen($content), $this->client->getAttachmentInfo($attachmentUuid)->getFileSize());
                $downloadedFile = $this->client->downloadAttachment($attachmentUuid, $this->createTemporaryDirectory());
                $this->assertEquals($content, file_get_contents($downloadedFile));
            }
        }

        public function testUploadRejectedWhenNoFileProvided(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $ch = curl_init(rtrim(getenv('SERVER_ENDPOINT'), '/') . '/attachments');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->rootToken(),
                    'Content-Type: multipart/form-data; boundary=----boundary',
                ],
                CURLOPT_POSTFIELDS => "------boundary\r\nContent-Disposition: form-data; name=\"evidence_uuid\"\r\n\r\n$evidenceUuid\r\n------boundary--\r\n",
            ]);
            $response = (string)curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code, $response);
            $this->assertStringContainsString('A file upload or a URL is required', $response);
        }
    }
