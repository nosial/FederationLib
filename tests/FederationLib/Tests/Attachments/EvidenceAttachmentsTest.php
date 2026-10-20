<?php

    namespace FederationLib\Tests\Attachments;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\FileAttachmentRecord;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    /**
     * Client to server tests of the attachments of evidence records uploaded by operators acting through their own client
     */
    class EvidenceAttachmentsTest extends TestCase
    {
        use TestHelpers;

        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $createdAttachments = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
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

            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->createdAttachments = [];
            $this->tempFiles = [];
        }

        public function testEvidenceAttachmentsOnlyListOwnAttachments(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $firstEvidence = $this->createSecurityEvidence($entityUuid);
            $secondEvidence = $this->createSecurityEvidence($entityUuid);

            $this->assertSame([], $this->client->getEvidenceAttachments($firstEvidence));

            $firstUploads = [];
            for ($i = 0; $i < 3; $i++)
            {
                $upload = $this->client->uploadNoteAttachment($firstEvidence, 'first_' . $i, 'First evidence note ' . $i);
                $this->createdAttachments[] = $upload->getUuid();
                $firstUploads[] = $upload->getUuid();
            }

            $secondUpload = $this->client->uploadNoteAttachment($secondEvidence, 'second', 'Second evidence note');
            $this->createdAttachments[] = $secondUpload->getUuid();

            $firstAttachments = $this->client->getEvidenceAttachments($firstEvidence);
            $this->assertContainsOnlyInstancesOf(FileAttachmentRecord::class, $firstAttachments);
            $this->assertEqualsCanonicalizing($firstUploads, array_map(fn(FileAttachmentRecord $record) => $record->getUuid(), $firstAttachments));
            foreach ($firstAttachments as $attachment)
            {
                $this->assertEquals($firstEvidence, $attachment->getEvidenceUuid());
            }

            $this->assertEquals([$secondUpload->getUuid()], array_map(fn(FileAttachmentRecord $record) => $record->getUuid(), $this->client->getEvidenceAttachments($secondEvidence)));
        }

        public function testNoteAttachmentRoundTrip(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $content = "Round trip note\nwith a second line";

            $upload = $this->client->uploadNoteAttachment($evidenceUuid, 'round_trip', $content);
            $this->createdAttachments[] = $upload->getUuid();
            $this->assertNotEmpty($upload->getUrl());

            $attachments = $this->client->getEvidenceAttachments($evidenceUuid);
            $this->assertCount(1, $attachments);

            // The client appends the .txt extension to the file name of a note
            $this->assertEquals('round_trip.txt', $attachments[0]->getFileName());
            $this->assertEquals(strlen($content), $attachments[0]->getFileSize());

            $info = $this->client->getAttachmentInfo($upload->getUuid());
            $this->assertEquals($attachments[0]->getFileName(), $info->getFileName());
            $this->assertEquals($evidenceUuid, $info->getEvidenceUuid());

            $downloadedFile = $this->client->downloadAttachment($upload->getUuid(), sys_get_temp_dir());
            $this->tempFiles[] = $downloadedFile;
            $this->assertEquals($content, file_get_contents($downloadedFile));
        }

        public function testDeletedAttachmentLeavesEvidenceAttachments(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $kept = $this->client->uploadNoteAttachment($evidenceUuid, 'kept', 'Kept note');
            $deleted = $this->client->uploadNoteAttachment($evidenceUuid, 'deleted', 'Deleted note');
            $this->createdAttachments[] = $kept->getUuid();

            $this->client->deleteAttachment($deleted->getUuid());

            $this->assertEquals([$kept->getUuid()], array_map(fn(FileAttachmentRecord $record) => $record->getUuid(), $this->client->getEvidenceAttachments($evidenceUuid)));
            $this->expectRequestFailure(
                fn() => $this->client->getAttachmentInfo($deleted->getUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'A deleted attachment should no longer be found'
            );
        }

        public function testClientUploadsOnlyToOwnEvidence(): void
        {
            $owner = $this->createLimitedOperator('attach_owner', client: true);
            $stranger = $this->createLimitedOperator('attach_stranger', client: true);
            $manager = $this->createLimitedOperator('attach_manager', management: true);
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity(), client: $owner);

            $ownUpload = $owner->uploadNoteAttachment($evidenceUuid, 'owner_note', 'Uploaded by the owner');
            $this->createdAttachments[] = $ownUpload->getUuid();

            $this->expectRequestFailure(
                fn() => $stranger->uploadNoteAttachment($evidenceUuid, 'stranger_note', 'Uploaded by a stranger'),
                [HttpResponseCode::FORBIDDEN->value],
                'A client should not attach files to evidence of another operator'
            );

            // Management permissions allow attaching files to any evidence
            $managerUpload = $manager->uploadNoteAttachment($evidenceUuid, 'manager_note', 'Uploaded by a manager');
            $this->createdAttachments[] = $managerUpload->getUuid();

            $this->assertEqualsCanonicalizing(
                [$ownUpload->getUuid(), $managerUpload->getUuid()],
                array_map(fn(FileAttachmentRecord $record) => $record->getUuid(), $this->client->getEvidenceAttachments($evidenceUuid))
            );
        }

        public function testEvidenceAttachmentsArePublicForPublicEvidence(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $upload = $this->client->uploadNoteAttachment($evidenceUuid, 'public_note', 'Public note');
            $this->createdAttachments[] = $upload->getUuid();

            $anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->assertEquals([$upload->getUuid()], array_map(fn(FileAttachmentRecord $record) => $record->getUuid(), $anonymous->getEvidenceAttachments($evidenceUuid)));
        }

        public function testEvidenceAttachmentsOfMissingEvidence(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->getEvidenceAttachments(Uuid::v7()->toRfc4122()),
                [HttpResponseCode::NOT_FOUND->value],
                'Attachments of a non-existent evidence record should not be found'
            );

            $this->expectRequestFailure(
                fn() => $this->client->uploadNoteAttachment(Uuid::v7()->toRfc4122(), 'missing', 'Missing evidence note'),
                [HttpResponseCode::NOT_FOUND->value],
                'A note should not be attached to a non-existent evidence record'
            );

            [$code] = $this->rawRequest('GET', 'evidence/not-a-valid-uuid/attachments', getenv('SERVER_ACCESS_TOKEN'));
            $this->assertContains($code, [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::NOT_FOUND->value]);
        }

        public function testNoteAttachmentValidatesArgumentsClientSide(): void
        {
            $calls = [
                fn() => $this->client->uploadNoteAttachment('', 'note', 'content'),
                fn() => $this->client->uploadNoteAttachment(Uuid::v7()->toRfc4122(), '', 'content'),
                fn() => $this->client->uploadNoteAttachment(Uuid::v7()->toRfc4122(), 'note', ''),
                fn() => $this->client->getEvidenceAttachments(''),
            ];

            foreach ($calls as $index => $call)
            {
                try
                {
                    $call();
                    $this->fail("Call $index should have been rejected before reaching the server");
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
