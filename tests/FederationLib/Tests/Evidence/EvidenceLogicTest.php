<?php

namespace FederationLib\Tests\Evidence;

use FederationLib\Enums\HttpResponseCode;
use FederationLib\Enums\IncidentType;
use FederationLib\Exceptions\RequestException;
use FederationLib\FederationClient;
use FederationLib\Helpers\Logger;
use FederationLib\Helpers\TestHelpers;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EvidenceLogicTest extends TestCase
{
    use TestHelpers;
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

    public function testListEvidenceSortInvalidOrderFallsBack(): void
    {
        $entityUuid = $this->client->pushEntity('evidence-invalid-order.com', 'evidence_order_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $uuid = $this->client->submitEvidence($entityUuid, 'Evidence invalid order', 'Note', 'invalid_order');
        $this->createdEvidenceRecords[] = $uuid;

        $resultDefault = $this->client->listEvidence(1, 10, false);
        $resultInvalid = $this->client->listEvidence(1, 10, false, null, 'created', 'BAD_ORDER');

        $defaultUuids = array_map(fn($r) => $r->getUuid(), $resultDefault);
        $invalidUuids = array_map(fn($r) => $r->getUuid(), $resultInvalid);

        $this->assertSame($defaultUuids, $invalidUuids);
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

        $this->client->addEvidenceToReport($evidenceUuid, $reportBUuid);
        $evidenceAfterB = $this->client->getEvidenceRecord($evidenceUuid);
        $this->assertEquals($reportBUuid, $evidenceAfterB->getReport());
    }

    public function testListEvidenceCategoryInvalidFallsBack(): void
    {
        $entityUuid = $this->client->pushEntity('ev-cat-invalid.com', 'ev_cat_inv_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $uuid = $this->client->submitEvidence($entityUuid, 'Category invalid test', 'Note', 'cat_inv');
        $this->createdEvidenceRecords[] = $uuid;

        $resultDefault = $this->client->listEvidence(1, 10, true);
        $resultInvalid = $this->client->listEvidence(1, 10, true, 'BOGUS_CATEGORY');

        $defaultUuids = array_map(fn($r) => $r->getUuid(), $resultDefault);
        $invalidUuids = array_map(fn($r) => $r->getUuid(), $resultInvalid);

        $this->assertNotEmpty($resultInvalid);
        $this->assertSame($defaultUuids, $invalidUuids);
    }

    public function testListEvidenceCategoryCaseInsensitive(): void
    {
        $entityUuid = $this->client->pushEntity('ev-cat-ci.com', 'ev_ci_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $uuid = $this->client->submitEvidence($entityUuid, 'CI category test', 'Note', 'ci');
        $this->createdEvidenceRecords[] = $uuid;

        $resultUpper = $this->client->listEvidence(1, 10, true, 'NOT_CONFIDENTIAL');
        $resultLower = $this->client->listEvidence(1, 10, true, 'not_confidential');
        $resultMixed = $this->client->listEvidence(1, 10, true, 'Not_Confidential');

        $upperUuids = array_map(fn($r) => $r->getUuid(), $resultUpper);
        $lowerUuids = array_map(fn($r) => $r->getUuid(), $resultLower);
        $mixedUuids = array_map(fn($r) => $r->getUuid(), $resultMixed);

        $this->assertNotEmpty($resultUpper);
        $this->assertSame($upperUuids, $lowerUuids);
        $this->assertSame($upperUuids, $mixedUuids);
    }
}
