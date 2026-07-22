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

class EvidenceSecurityTest extends TestCase
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
        $this->assertContains($updatedRecord->getTag(), ['updated_tag', '1']);
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

    public function testSecurityEvidenceSubmissionToNonExistentEntity(): void
    {
        $client = $this->createLimitedOperator('ev_nonexistent', client: true);

        $this->expectRequestFailure(
            fn() => $client->submitEvidence($this->randomUuid(), 'Evidence for non-existent entity', 'Note', 'ghost'),
            [HttpResponseCode::NOT_FOUND->value],
            'Submitting evidence for a non-existent entity should fail'
        );
    }

    public function testSecurityEvidenceTagMalformedRejected(): void
    {
        $entityUuid = $this->createSecurityEntity();
        $evidenceUuid = $this->createSecurityEvidence($entityUuid);

        $malformedTags = [
            str_repeat('x', 33),
            "tag\nwith\nnewlines",
            "tag\twith\ttabs",
            "tag\x00null",
        ];

        foreach ($malformedTags as $tag)
        {
            $this->expectRequestFailure(
                fn() => $this->client->updateEvidenceTag($evidenceUuid, $tag),
                [HttpResponseCode::BAD_REQUEST->value],
                "Malformed tag '$tag' should be rejected by the server"
            );
        }
    }
}
