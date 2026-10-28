<?php

    namespace FederationLib\Tests\Attachments;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\AttachmentFiles;
    use FederationLib\Helpers\ServerTestCase;
    use InvalidArgumentException;

    class AttachmentsSecurityTest extends ServerTestCase
    {
        use AttachmentFiles;

        public function testUploadRequiresClientPermissions(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $file = $this->createTestFile('restricted.txt', 'Restricted upload');

            $noPermissions = $this->createLimitedOperator('attachment_none');
            $operatorOnly = $this->createLimitedOperator('attachment_operator', operator: true);
            $clientOnly = $this->createLimitedOperator('attachment_client', client: true);

            $this->expectRequestFailure(
                fn() => new FederationClient(getenv('SERVER_ENDPOINT'))->uploadFileAttachment($evidenceUuid, $file),
                [HttpResponseCode::UNAUTHORIZED->value],
                'An anonymous client should not upload attachments'
            );
            $this->expectRequestFailure(
                fn() => $noPermissions->uploadFileAttachment($evidenceUuid, $file),
                [HttpResponseCode::FORBIDDEN->value],
                'An operator without permissions should not upload attachments'
            );
            $this->expectRequestFailure(
                fn() => $operatorOnly->uploadNoteAttachment($evidenceUuid, 'note', 'content'),
                [HttpResponseCode::FORBIDDEN->value],
                'An operator without client permissions should not upload attachments'
            );
            $this->expectRequestFailure(
                fn() => $clientOnly->uploadNoteAttachment($this->randomUuid(), 'note', 'content'),
                [HttpResponseCode::NOT_FOUND->value],
                'An upload to unknown evidence should not be found'
            );

            // Empty files are rejected by the client before reaching the server
            $this->expectException(InvalidArgumentException::class);
            $clientOnly->uploadFileAttachment($evidenceUuid, $this->createTestFile('empty.txt', ''));
        }

        public function testListAttachmentsRequiresManagementPermissions(): void
        {
            $this->expectRequestFailure(
                fn() => new FederationClient(getenv('SERVER_ENDPOINT'))->listAttachments(),
                [HttpResponseCode::UNAUTHORIZED->value],
                'An anonymous client should not list attachments'
            );

            $clientOnly = $this->createLimitedOperator('attachment_list_client', client: true);
            $this->expectRequestFailure(
                fn() => $clientOnly->listAttachments(),
                [HttpResponseCode::FORBIDDEN->value],
                'An operator without management permissions should not list attachments'
            );

            $managementOnly = $this->createLimitedOperator('attachment_list_management', management: true);
            $this->assertIsArray($managementOnly->listAttachments());
        }

        public function testSecurityUploadAttachmentByUrlRejectsNonHttpUrl(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $this->expectException(InvalidArgumentException::class);
            $this->client->uploadFileAttachmentByUrl($evidenceUuid, 'file:///etc/passwd');
        }

        public function testSecurityUploadAttachmentByUrlRejectsNonPublicAddresses(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $urls = [
                'http://127.0.0.1/',
                'http://localhost:7000/',
                'http://10.0.0.1/',
                'http://169.254.169.254/latest/meta-data/',
                'http://[::1]/',
                'http://[::ffff:127.0.0.1]/',
            ];

            foreach ($urls as $url)
            {
                try
                {
                    $this->createdAttachments[] = $this->client->uploadFileAttachmentByUrl($evidenceUuid, $url)->getUuid();
                    $this->fail(sprintf('URL upload from non-public address %s should be rejected', $url));
                }
                catch (RequestException $e)
                {
                    $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $e->getCode(), sprintf('URL upload from %s should be rejected with HTTP 400', $url));
                }
            }
        }

        public function testSecurityUploadAttachmentByUrlRejectsCredentials(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->uploadFileAttachmentByUrl($evidenceUuid, 'http://user:password@example.com/file.txt');
        }

        public function testSecurityUploadAttachmentWithFileAndUrlIsRejected(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $testFilePath = $this->createTestFile('file_and_url.txt', 'File and URL content');

            $ch = curl_init(rtrim(getenv('SERVER_ENDPOINT'), '/') . '/attachments');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $this->rootToken()],
                CURLOPT_POSTFIELDS => [
                    'evidence_uuid' => $evidenceUuid,
                    'url' => 'https://example.com/file.txt',
                    'file' => new \CURLFile($testFilePath, 'text/plain', 'file_and_url.txt'),
                ],
            ]);
            $response = (string)curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $code, 'Upload with both a file and a URL should be rejected');
            $this->assertStringContainsString('Provide either a file upload or a URL, not both', $response);
        }

        public function testAttachmentConfidentialityFollowsEvidence(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $attachmentUuid = $this->client->uploadFileAttachment($evidenceUuid, $this->createTestFile('confidential_follow.txt', 'Confidential follow content'))->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            // Evidence is public by default, and so are its attachments
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->assertEquals($evidenceUuid, $anonymousClient->getAttachmentInfo($attachmentUuid)->getEvidenceUuid());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);

            $this->expectRequestFailure(
                fn() => $anonymousClient->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::UNAUTHORIZED->value],
                'Attachment should become inaccessible when evidence is made confidential'
            );
        }

        public function testUploadWithPathTraversalFilenameIsSanitized(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $maliciousNames = [
                '../../../etc/passwd',
                '..\\..\\windows\\system32\\config\\sam',
                'file.txt%00.php',
                'normal.txt',
            ];

            foreach ($maliciousNames as $name)
            {
                $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $this->createTestFile('safe_local_name.txt', 'Content for ' . $name), $name);
                $this->createdAttachments[] = $uploadResult->getUuid();

                $fileName = $this->client->getAttachmentInfo($uploadResult->getUuid())->getFileName();
                $this->assertStringNotContainsString('..', $fileName, 'Path traversal sequences should be removed');
                $this->assertStringNotContainsString('/', $fileName, 'Directory separators should be removed from filename');
                $this->assertStringNotContainsString('\\', $fileName, 'Backslash separators should be removed from filename');
            }

            $this->assertEquals('normal.txt', $fileName, 'An ordinary file name is kept');
        }
    }
