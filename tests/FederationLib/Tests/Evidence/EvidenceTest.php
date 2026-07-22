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

class EvidenceTest extends TestCase
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

    public function testListEvidenceSortByCreatedAscending(): void
    {
        $entityUuid = $this->client->pushEntity('evidence-sort-asc.com', 'evidence_asc_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $evidenceUuids = [];
        for ($i = 0; $i < 3; $i++)
        {
            $uuid = $this->client->submitEvidence($entityUuid, "Evidence sort ASC $i", "Note $i", "sort_asc_$i");
            $this->createdEvidenceRecords[] = $uuid;
            $evidenceUuids[] = $uuid;
        }

        $records = $this->client->listEvidence(1, 100, false, null, 'created', 'ASC');
        $filtered = array_values(array_filter($records, fn($r) => in_array($r->getUuid(), $evidenceUuids, true)));

        $this->assertCount(3, $filtered);
        $this->assertEquals($evidenceUuids[0], $filtered[0]->getUuid());
        $this->assertEquals($evidenceUuids[1], $filtered[1]->getUuid());
        $this->assertEquals($evidenceUuids[2], $filtered[2]->getUuid());
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

    public function testListEvidenceCategoryConfidential(): void
    {
        $entityUuid = $this->client->pushEntity('ev-cat-conf.com', 'ev_conf_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $publicUuid = $this->client->submitEvidence($entityUuid, 'Public cat evidence', 'Note', 'cat_public');
        $this->createdEvidenceRecords[] = $publicUuid;

        $confUuid = $this->client->submitEvidence($entityUuid, 'Confidential cat evidence', 'Note', 'cat_conf', true);
        $this->createdEvidenceRecords[] = $confUuid;

        $records = $this->client->listEvidence(1, 100, true, 'CONFIDENTIAL');
        $uuids = array_map(fn($r) => $r->getUuid(), $records);

        $this->assertContains($confUuid, $uuids);
        $this->assertNotContains($publicUuid, $uuids);
        foreach ($records as $r)
        {
            $this->assertTrue($r->isConfidential());
        }
    }

    public function testListEvidenceCategoryNotConfidential(): void
    {
        $entityUuid = $this->client->pushEntity('ev-cat-not-conf.com', 'ev_not_conf_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $publicUuid = $this->client->submitEvidence($entityUuid, 'Public cat evidence', 'Note', 'cat_public');
        $this->createdEvidenceRecords[] = $publicUuid;

        $confUuid = $this->client->submitEvidence($entityUuid, 'Confidential cat evidence', 'Note', 'cat_conf', true);
        $this->createdEvidenceRecords[] = $confUuid;

        $records = $this->client->listEvidence(1, 100, true, 'NOT_CONFIDENTIAL');
        $uuids = array_map(fn($r) => $r->getUuid(), $records);

        $this->assertContains($publicUuid, $uuids);
        $this->assertNotContains($confUuid, $uuids);
        foreach ($records as $r)
        {
            $this->assertFalse($r->isConfidential());
        }
    }

    public function testListEvidenceCategoryUnclassified(): void
    {
        $entityUuid = $this->client->pushEntity('ev-cat-unclass.com', 'ev_unclass_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $uuid = $this->client->submitEvidence($entityUuid, 'Unclassified evidence', 'Note', 'unclass');
        $this->createdEvidenceRecords[] = $uuid;

        $records = $this->client->listEvidence(1, 100, true, 'UNCLASSIFIED');
        $uuids = array_map(fn($r) => $r->getUuid(), $records);
        $this->assertContains($uuid, $uuids);
    }

    public function testListEvidenceCategoryWithSort(): void
    {
        $entityUuid = $this->client->pushEntity('ev-cat-sort.com', 'ev_cat_sort_' . uniqid());
        $this->createdEntityRecords[] = $entityUuid;

        $uuids = [];
        for ($i = 0; $i < 3; $i++)
        {
            $uuid = $this->client->submitEvidence($entityUuid, "Cat sort $i", "Note $i", 'cat_sort');
            $this->createdEvidenceRecords[] = $uuid;
            $uuids[] = $uuid;
        }

        $records = $this->client->listEvidence(1, 100, true, 'NOT_CONFIDENTIAL', 'created', 'ASC');
        $filtered = array_values(array_filter($records, fn($r) => in_array($r->getUuid(), $uuids, true)));

        $this->assertCount(3, $filtered);
        $this->assertEquals($uuids[0], $filtered[0]->getUuid());
        $this->assertEquals($uuids[1], $filtered[1]->getUuid());
        $this->assertEquals($uuids[2], $filtered[2]->getUuid());
    }
}
