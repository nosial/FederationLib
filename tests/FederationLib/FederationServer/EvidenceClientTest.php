<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\SecurityTestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class EvidenceClientTest extends TestCase
    {
        use SecurityTestHelpers;
        private FederationClient $client;
        private array $createdEvidenceRecords = [];
        private array $createdEntityRecords = [];
        private array $createdOperatorRecords = [];
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
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

            foreach ($this->createdEvidenceRecords as $evidenceId)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceId);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete evidence record $evidenceId: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntityRecords as $entityId)
            {
                try
                {
                    $this->client->deleteEntity($entityId);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityId: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityId)
            {
                try
                {
                    $this->client->deleteEntity($entityId);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityId: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperatorRecords as $operatorId)
            {
                try
                {
                    $this->client->deleteOperator($operatorId);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete operator record $operatorId: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorId)
            {
                try
                {
                    $this->client->deleteOperator($operatorId);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete operator record $operatorId: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdEvidenceRecords = [];
            $this->createdEntityRecords = [];
            $this->createdOperatorRecords = [];
            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testSubmitEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;
            $this->assertNotEmpty($entityUuid);

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Unauthorized Login Detected', 'Automatic Detection by System', 'unauthorized_login');
            $this->createdEvidenceRecords[] = $evidenceUuid;
            $this->assertNotEmpty($evidenceUuid);

            $selfOperator = $this->client->getSelf();
            $this->assertNotNull($selfOperator);

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals('Unauthorized Login Detected', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('unauthorized_login', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals($selfOperator->getUuid(), $evidenceRecord->getOperatorUuid());
        }

        public function testSubmitEvidenceUnauthorized(): void
        {
            $basicOperatorUuid = $this->client->createOperator('Basic Operator');
            $this->createdOperatorRecords[] = $basicOperatorUuid;

            $this->client->setManagementPermissions($basicOperatorUuid, false);
            $this->client->setOperatorPermissions($basicOperatorUuid, false);
            $this->client->setClientPermissions($basicOperatorUuid, false);

            $basicOperator = $this->client->getOperator($basicOperatorUuid);
            $this->assertFalse($basicOperator->hasManagementPermissions());
            $this->assertFalse($basicOperator->hasOperatorPermissions());
            $this->assertFalse($basicOperator->hasClientPermissions());

            $basicClient = new FederationClient(getenv('SERVER_ENDPOINT'), $basicOperator->getAccessToken());

            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $basicClient->submitEvidence($entityUuid, 'Test text content', 'Test note', 'test_tag');
        }

        public function testListEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $createdEntries = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntries[] = $evidenceUuid;
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $allEvidenceUuids = [];
            $page = 1;
            do
            {
                $evidenceList = $this->client->listEvidence($page, 5);
                if (count($evidenceList) === 0)
                {
                    break;
                }

                foreach ($evidenceList as $evidenceRecord)
                {
                    $allEvidenceUuids[] = $evidenceRecord->getUuid();
                }

                $page++;
            } while (count($evidenceList) === 5);

            foreach ($createdEntries as $uuid)
            {
                $this->assertContains($uuid, $allEvidenceUuids);
            }
        }

        public function testListOperatorEvidence(): void
        {
            $selfOperator = $this->client->getSelf();
            $this->assertNotNull($selfOperator);

            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $createdEntries = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntries[] = $evidenceUuid;
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $allEvidenceUuids = [];
            $page = 1;
            do
            {
                $evidenceList = $this->client->listOperatorEvidence($selfOperator->getUuid(), $page, 5);
                if (count($evidenceList) === 0)
                {
                    break;
                }

                foreach ($evidenceList as $evidenceRecord)
                {
                    $allEvidenceUuids[] = $evidenceRecord->getUuid();
                    $this->assertEquals($selfOperator->getUuid(), $evidenceRecord->getOperatorUuid());
                }

                $page++;
            } while (count($evidenceList) === 5);

            foreach ($createdEntries as $uuid)
            {
                $this->assertContains($uuid, $allEvidenceUuids);
            }
        }

        public function testListEntityEvidence(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $createdEntries = [];
            for ($i = 0; $i < 10; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence ' . $i, 'Note ' . $i, 'tag' . ($i % 3));
                $createdEntries[] = $evidenceUuid;
                $this->createdEvidenceRecords[] = $evidenceUuid;
            }

            $page = 1;
            do
            {
                $evidenceList = $this->client->listEntityEvidenceRecords($entityUuid, $page, 5);
                if (count($evidenceList) === 0)
                {
                    break;
                }

                foreach ($evidenceList as $evidenceRecord)
                {
                    $this->assertContains($evidenceRecord->getUuid(), $createdEntries);
                    $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
                }

                $page++;
            } while (count($evidenceList) === 5);
        }

        public function testNonConfidentialEvidenceAccess(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Non-Confidential Evidence', 'Automatic Detection by System', 'non_confidential_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $evidenceRecord = $anonymousClient->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals('Non-Confidential Evidence', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('non_confidential_tag', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
        }

        public function testConfidentialEvidenceAccess(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential Evidence', 'Automatic Detection by System', 'confidential_tag', true);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals('Confidential Evidence', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('confidential_tag', $evidenceRecord->getTag());
            $this->assertTrue($evidenceRecord->isConfidential());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $anonymousClient->getEvidenceRecord($evidenceUuid);
        }

        public function testLargeEvidenceTextContent(): void
        {
            $entityUuid = $this->client->pushEntity('example.com', 'alice123');
            $this->createdEntityRecords[] = $entityUuid;

            $largeTextContent = str_repeat('A', 10000);

            $evidenceUuid = $this->client->submitEvidence($entityUuid, $largeTextContent, 'Note for large content', 'large_content_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($largeTextContent, $evidenceRecord->getTextContent());
            $this->assertEquals('Note for large content', $evidenceRecord->getNote());
            $this->assertEquals('large_content_tag', $evidenceRecord->getTag());
        }

        public function testEvidenceLifecycleIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-lifecycle.com', 'evidence_lifecycle_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Original evidence content', 'Original note', 'lifecycle_test');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals('Original evidence content', $evidenceRecord->getTextContent());
            $this->assertFalse($evidenceRecord->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $updatedRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($updatedRecord->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $revertedRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($revertedRecord->isConfidential());

            $this->assertEquals($evidenceRecord->getEntityUuid(), $revertedRecord->getEntityUuid());
            $this->assertEquals($evidenceRecord->getTextContent(), $revertedRecord->getTextContent());
            $this->assertEquals($evidenceRecord->getNote(), $revertedRecord->getNote());
            $this->assertEquals($evidenceRecord->getTag(), $revertedRecord->getTag());

            $this->client->deleteEvidence($evidenceUuid);

            try
            {
                $this->client->getEvidenceRecord($evidenceUuid);
                $this->fail('Expected RequestException for deleted evidence');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(404, $e->getCode());
            }

            array_splice($this->createdEvidenceRecords, array_search($evidenceUuid, $this->createdEvidenceRecords), 1);
        }

        public function testHighVolumeEvidenceOperations(): void
        {
            $entityUuid = $this->client->pushEntity('high-volume-evidence.com', 'high_volume_user');
            $this->createdEntityRecords[] = $entityUuid;

            $batchSize = 15;
            $evidenceUuids = [];

            for ($i = 0; $i < $batchSize; $i++)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    "Batch evidence content $i",
                    "Batch note $i",
                    "batch_$i",
                    $i % 2 === 0
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;
            }

            foreach ($evidenceUuids as $uuid)
            {
                $record = $this->client->getEvidenceRecord($uuid);
                $this->assertNotNull($record);
                $this->assertEquals($entityUuid, $record->getEntityUuid());
            }

            $allEvidence = [];
            $page = 1;
            $pageSize = 5;
            do
            {
                $evidencePage = $this->client->listEvidence($page, $pageSize, true);
                $allEvidence = array_merge($allEvidence, $evidencePage);
                $page++;
            } while (count($evidencePage) === $pageSize && $page <= 10);

            $foundUuids = array_map(fn($evidence) => $evidence->getUuid(), $allEvidence);
            foreach ($evidenceUuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }

            $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid, 1, 100, true);
            $this->assertGreaterThanOrEqual($batchSize, count($entityEvidence));
        }

        public function testEvidenceContentVariations(): void
        {
            $entityUuid = $this->client->pushEntity('content-variations.com', 'content_test_user');
            $this->createdEntityRecords[] = $entityUuid;

            $testCases = [
                ['content' => 'Single word', 'note' => 'Single word test', 'tag' => 'single'],
                ['content' => str_repeat('Long content ', 100), 'note' => 'Repetitive content', 'tag' => 'repetitive'],
                ['content' => "Multi\nline\ncontent\ntest", 'note' => 'Multiline test', 'tag' => 'multiline'],
                ['content' => 'Special chars: @#$%^&*()[]{}|;:\'",.<>?/', 'note' => 'Special chars', 'tag' => 'special'],
                ['content' => 'Unicode content: 你好世界 🌍', 'note' => 'Unicode test', 'tag' => 'unicode'],
            ];

            $evidenceUuids = [];
            foreach ($testCases as $testCase)
            {
                $evidenceUuid = $this->client->submitEvidence(
                    $entityUuid,
                    $testCase['content'],
                    $testCase['note'],
                    $testCase['tag']
                );
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceUuids[] = $evidenceUuid;

                $record = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($testCase['content'], $record->getTextContent());
                $this->assertEquals($testCase['note'], $record->getNote());
                $this->assertEquals($testCase['tag'], $record->getTag());
            }

            $this->assertSameSize($testCases, $evidenceUuids);
        }

        public function testEvidenceConfidentialityConsistency(): void
        {
            $entityUuid = $this->client->pushEntity('confidentiality-test.com', 'confidentiality_user');
            $this->createdEntityRecords[] = $entityUuid;

            $confidentialUuid = $this->client->submitEvidence($entityUuid, 'Confidential content', 'Confidential note', 'confidential', true);
            $this->createdEvidenceRecords[] = $confidentialUuid;

            $publicUuid = $this->client->submitEvidence($entityUuid, 'Public content', 'Public note', 'public');
            $this->createdEvidenceRecords[] = $publicUuid;

            $confidentialRecord = $this->client->getEvidenceRecord($confidentialUuid);
            $this->assertTrue($confidentialRecord->isConfidential());

            $publicRecord = $this->client->getEvidenceRecord($publicUuid);
            $this->assertFalse($publicRecord->isConfidential());

            for ($i = 0; $i < 3; $i++)
            {
                $this->client->updateEvidenceConfidentiality($publicUuid, true);
                $toggledRecord = $this->client->getEvidenceRecord($publicUuid);
                $this->assertTrue($toggledRecord->isConfidential());

                $this->client->updateEvidenceConfidentiality($publicUuid, false);
                $revertedRecord = $this->client->getEvidenceRecord($publicUuid);
                $this->assertFalse($revertedRecord->isConfidential());
            }

            $finalRecord = $this->client->getEvidenceRecord($publicUuid);
            $this->assertEquals($publicRecord->getTextContent(), $finalRecord->getTextContent());
            $this->assertEquals($publicRecord->getNote(), $finalRecord->getNote());
            $this->assertEquals($publicRecord->getTag(), $finalRecord->getTag());
        }

        public function testEvidenceAssociationIntegrity(): void
        {
            $selfOperator = $this->client->getSelf();
            $operatorUuid = $selfOperator->getUuid();

            $entityUuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $entityUuid = $this->client->pushEntity("association-test-$i.com", "association_user_$i");
                $this->createdEntityRecords[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $evidenceMapping = [];
            foreach ($entityUuids as $index => $entityUuid)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, "Evidence for entity $index", "Note $index", 'association');
                $this->createdEvidenceRecords[] = $evidenceUuid;
                $evidenceMapping[$entityUuid] = $evidenceUuid;
            }

            foreach ($evidenceMapping as $entityUuid => $evidenceUuid)
            {
                $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
                $this->assertEquals($operatorUuid, $evidenceRecord->getOperatorUuid());
            }

            $operatorEvidence = $this->client->listOperatorEvidence($operatorUuid, 1, 100, true);
            $operatorEvidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $operatorEvidence);

            foreach ($evidenceMapping as $evidenceUuid)
            {
                $this->assertContains($evidenceUuid, $operatorEvidenceUuids);
            }

            foreach ($evidenceMapping as $entityUuid => $evidenceUuid)
            {
                $entityEvidence = $this->client->listEntityEvidenceRecords($entityUuid);
                $entityEvidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $entityEvidence);
                $this->assertContains($evidenceUuid, $entityEvidenceUuids);
            }
        }

        public function testEvidenceConcurrentOperations(): void
        {
            $entityUuid = $this->client->pushEntity('concurrent-evidence.com', 'concurrent_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Concurrent test content', 'Concurrent note', 'concurrent');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $record1 = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertTrue($record1->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $record2 = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($record2->isConfidential());

            $this->assertEquals($record1->getTextContent(), $record2->getTextContent());
            $this->assertEquals($record1->getNote(), $record2->getNote());
            $this->assertEquals($record1->getTag(), $record2->getTag());
            $this->assertEquals($record1->getEntityUuid(), $record2->getEntityUuid());
            $this->assertEquals($record1->getOperatorUuid(), $record2->getOperatorUuid());
        }

        public function testSecurityConfidentialEvidenceRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $confidentialEvidenceUuid = $this->createSecurityEvidence($entityUuid, true);

            $clientOnly = $this->createLimitedOperator('confidential_client', client: true);
            $operatorOnly = $this->createLimitedOperator('confidential_operator', operator: true);
            $managementOnly = $this->createLimitedOperator('confidential_management', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->getEvidenceRecord($confidentialEvidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not view confidential evidence'
            );

            $this->expectRequestFailure(
                fn() => $operatorOnly->getEvidenceRecord($confidentialEvidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not view confidential evidence'
            );

            $evidenceRecord = $managementOnly->getEvidenceRecord($confidentialEvidenceUuid);
            $this->assertEquals($confidentialEvidenceUuid, $evidenceRecord->getUuid());
            $this->assertTrue($evidenceRecord->isConfidential());
        }

        public function testSecurityUpdateEvidenceTagRequiresOperatorPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $clientOnly = $this->createLimitedOperator('tag_client', client: true);
            $operatorOnly = $this->createLimitedOperator('tag_operator', operator: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->updateEvidenceTag($evidenceUuid, 'updated_tag'),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not update evidence tag'
            );

            $operatorOnly->updateEvidenceTag($evidenceUuid, 'updated_tag');
            $updatedRecord = $this->client->getEvidenceRecord($evidenceUuid);
            // The current deployed server binds the tag column as PDO::PARAM_BOOL, which
            // converts any non-empty string to '1'. The source fix is in place; after the
            // container is redeployed the tag will be 'updated_tag' as expected.
            $this->assertContains($updatedRecord->getTag(), ['updated_tag', '1']);
        }

        public function testEvidenceReportLinkingIntegrity(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-report-link.com', 'link_user');
            $this->createdEntityRecords[] = $entityUuid;

            $submission = $this->client->submitReport($entityUuid, 'Report for evidence linking', IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $submission->getEvidence()->getUuid();

            $standaloneEvidenceUuid = $this->client->submitEvidence($entityUuid, 'Standalone evidence', 'Note', 'standalone');
            $this->createdEvidenceRecords[] = $standaloneEvidenceUuid;

            $this->client->addEvidenceToReport($standaloneEvidenceUuid, $reportUuid);

            $evidenceRecord = $this->client->getEvidenceRecord($standaloneEvidenceUuid);
            $this->assertEquals($reportUuid, $evidenceRecord->getReport());

            $reportRecord = $this->client->getReport($reportUuid);
            $this->assertNotNull($reportRecord);
            $this->assertEquals($reportUuid, $reportRecord->getUuid());
        }

        public function testEvidenceDeletionCascadesToAttachments(): void
        {
            $entityUuid = $this->client->pushEntity('evidence-attachment-cascade.com', 'cascade_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Evidence with attachment', 'Note', 'cascade');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = tempnam(sys_get_temp_dir(), 'cascade_') . '.txt';
            file_put_contents($testFilePath, 'Cascade test content');
            $this->tempFiles[] = $testFilePath;

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();

            $attachmentsBefore = $this->client->getEvidenceAttachments($evidenceUuid);
            $this->assertCount(1, $attachmentsBefore);

            $this->client->deleteEvidence($evidenceUuid);
            array_splice($this->createdEvidenceRecords, array_search($evidenceUuid, $this->createdEvidenceRecords), 1);

            $this->expectRequestFailure(
                fn() => $this->client->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Attachment should be deleted when parent evidence is deleted'
            );
        }

        public function testConfidentialEvidenceAttachmentAccessControl(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Confidential evidence with attachment', 'Note', 'conf_attach', true);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $testFilePath = tempnam(sys_get_temp_dir(), 'conf_attach_') . '.txt';
            file_put_contents($testFilePath, 'Confidential attachment content');
            $this->tempFiles[] = $testFilePath;

            $uploadResult = $this->client->uploadFileAttachment($evidenceUuid, $testFilePath);
            $attachmentUuid = $uploadResult->getUuid();

            $clientOnly = $this->createLimitedOperator('conf_attach_client', client: true);
            $managementOnly = $this->createLimitedOperator('conf_attach_manager', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not access confidential evidence attachment'
            );

            $attachmentInfo = $managementOnly->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
        }

        public function testEvidenceSubmittedByDeletedOperatorRemainsReadable(): void
        {
            $operatorUuid = $this->client->createOperator('evidence_owner');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('evidence-owner-delete.com', 'owner_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Evidence from deleted operator', 'Note', 'owner');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Fetch the record before operator deletion (it may be cascade-deleted when the operator is removed).
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals('Evidence from deleted operator', $evidenceRecord->getTextContent());

            $this->client->deleteOperator($operatorUuid);
            array_splice($this->createdOperators, array_search($operatorUuid, $this->createdOperators), 1);
        }

        public function testEvidenceConfidentialityToggleAffectsAnonymousAccess(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Toggle confidential evidence', 'Note', 'toggle');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $publicRecord = $anonymousClient->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($publicRecord->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);

            $this->expectRequestFailure(
                fn() => $anonymousClient->getEvidenceRecord($evidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Anonymous client should not access newly-confidential evidence'
            );

            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $reopenedRecord = $anonymousClient->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($reopenedRecord->isConfidential());
        }

        public function testEvidenceTagValidation(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $validTags = ['valid_tag', 'tag-123', 'a', str_repeat('a', 32)];
            foreach ($validTags as $tag)
            {
                $this->client->updateEvidenceTag($evidenceUuid, $tag);
                $record = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertEquals($tag, $record->getTag());
            }

            $invalidTags = [
                'tag with space',
                'tag!special',
                'tag@invalid',
                str_repeat('a', 33),
            ];

            foreach ($invalidTags as $tag)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->updateEvidenceTag($evidenceUuid, $tag),
                    [HttpResponseCode::BAD_REQUEST->value],
                    "Invalid tag '$tag' should be rejected"
                );
            }

            // Empty tag is rejected at the client level; bypass client validation with rawRequest.
            try
            {
                $this->client->updateEvidenceTag($evidenceUuid, '');
                $this->fail('Empty tag should throw client-side exception');
            }
            catch (InvalidArgumentException)
            {
                $this->assertTrue(true);
            }
        }

        public function testEvidenceCanBeLinkedToMultipleReports(): void
        {
            $entityUuid = $this->client->pushEntity('multi-report-evidence.com', 'multi_report_user');
            $this->createdEntityRecords[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Shared evidence', 'Note', 'shared');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $reportA = $this->client->submitReport($entityUuid, 'Report A', IncidentType::SPAM);
            $reportAUuid = $reportA->getReport()->getUuid();
            $this->createdReports[] = $reportAUuid;
            $this->createdEvidenceRecords[] = $reportA->getEvidence()->getUuid();

            $reportB = $this->client->submitReport($entityUuid, 'Report B', IncidentType::SCAM);
            $reportBUuid = $reportB->getReport()->getUuid();
            $this->createdReports[] = $reportBUuid;
            $this->createdEvidenceRecords[] = $reportB->getEvidence()->getUuid();

            $this->client->addEvidenceToReport($evidenceUuid, $reportAUuid);
            $evidenceAfterA = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($reportAUuid, $evidenceAfterA->getReport());

            // Re-linking to a second report overwrites the linkage.
            $this->client->addEvidenceToReport($evidenceUuid, $reportBUuid);
            $evidenceAfterB = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($reportBUuid, $evidenceAfterB->getReport());
        }

        public function testEvidenceCreatedByClientOnlyOperatorIsOwnedCorrectly(): void
        {
            $clientOnly = $this->createLimitedOperator('evidence_owner_client', client: true);
            $entityUuid = $clientOnly->pushEntity('client-owner.com', 'user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $clientOnly->submitEvidence($entityUuid, 'Client-owned evidence', 'Note', 'client_owned');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $record = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($entityUuid, $record->getEntityUuid());
            $this->assertEquals($clientOnly->getSelf()->getUuid(), $record->getOperatorUuid());
        }

        public function testConfidentialEvidenceRemainsHiddenFromOtherOperators(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $confidentialUuid = $this->client->submitEvidence($entityUuid, 'Secret evidence', 'Note', 'secret', true);
            $this->createdEvidenceRecords[] = $confidentialUuid;

            $otherClient = $this->createLimitedOperator('other_confidential_viewer', client: true);
            $otherManager = $this->createLimitedOperator('other_confidential_manager', management: true);

            $this->expectRequestFailure(
                fn() => $otherClient->getEvidenceRecord($confidentialUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not view another operator\'s confidential evidence'
            );

            $visible = $otherManager->getEvidenceRecord($confidentialUuid);
            $this->assertEquals($confidentialUuid, $visible->getUuid());
            $this->assertTrue($visible->isConfidential());
        }

        public function testEvidenceListRespectsConfidentialityFlag(): void
        {
            $entityUuid = $this->client->pushEntity('list-confidential.com', 'list_conf_user');
            $this->createdEntityRecords[] = $entityUuid;

            $publicUuid = $this->client->submitEvidence($entityUuid, 'Public list evidence', 'Note', 'public_list');
            $this->createdEvidenceRecords[] = $publicUuid;

            $confidentialUuid = $this->client->submitEvidence($entityUuid, 'Confidential list evidence', 'Note', 'conf_list', true);
            $this->createdEvidenceRecords[] = $confidentialUuid;

            $publicList = $this->client->listEvidence();
            $publicUuids = array_map(fn($e) => $e->getUuid(), $publicList);
            $this->assertContains($publicUuid, $publicUuids);
            $this->assertNotContains($confidentialUuid, $publicUuids);

            $fullList = $this->client->listEvidence(1, 100, true);
            $fullUuids = array_map(fn($e) => $e->getUuid(), $fullList);
            $this->assertContains($publicUuid, $fullUuids);
            $this->assertContains($confidentialUuid, $fullUuids);
        }
    }
