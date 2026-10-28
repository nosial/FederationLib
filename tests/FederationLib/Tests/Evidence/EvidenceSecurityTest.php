<?php

    namespace FederationLib\Tests\Evidence;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;

    class EvidenceSecurityTest extends ServerTestCase
    {
        public function testSubmitEvidenceRequiresClientPermissions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $submit = fn(FederationClient $client) => fn() => $client->submitEvidence($entityUuid, 'Test text content', 'Test note', 'test_tag');

            $this->expectRequestFailure($submit(new FederationClient(getenv('SERVER_ENDPOINT'))), [HttpResponseCode::UNAUTHORIZED->value], 'An anonymous client should not submit evidence');
            $this->expectRequestFailure($submit($this->createLimitedOperator('evidence_none')), [HttpResponseCode::FORBIDDEN->value], 'An operator without permissions should not submit evidence');
            $this->expectRequestFailure($submit($this->createLimitedOperator('evidence_operator', operator: true)), [HttpResponseCode::FORBIDDEN->value], 'An operator without client permissions should not submit evidence');

            $this->expectRequestFailure(
                fn() => $this->createLimitedOperator('evidence_unknown', client: true)->submitEvidence($this->randomUuid(), 'Evidence for non-existent entity', 'Note', 'ghost'),
                [HttpResponseCode::NOT_FOUND->value],
                'Submitting evidence for a non-existent entity should fail'
            );
        }

        public function testConfidentialEvidenceRequiresManagementPermissions(): void
        {
            $confidentialUuid = $this->createSecurityEvidence($this->createSecurityEntity(), true);

            // OFD "Get Evidence Record": 401 without authentication, 403 without management permissions
            $this->expectRequestFailure(
                fn() => new FederationClient(getenv('SERVER_ENDPOINT'))->getEvidenceRecord($confidentialUuid),
                [HttpResponseCode::UNAUTHORIZED->value],
                'An anonymous client should not view confidential evidence'
            );

            foreach (['client' => $this->createLimitedOperator('confidential_client', client: true), 'operator' => $this->createLimitedOperator('confidential_operator', operator: true)] as $label => $operator)
            {
                $this->expectRequestFailure(
                    fn() => $operator->getEvidenceRecord($confidentialUuid),
                    [HttpResponseCode::FORBIDDEN->value],
                    "An operator with only $label permissions should not view confidential evidence"
                );
            }

            $evidenceRecord = $this->createLimitedOperator('confidential_management', management: true)->getEvidenceRecord($confidentialUuid);
            $this->assertEquals($confidentialUuid, $evidenceRecord->getUuid());
            $this->assertTrue($evidenceRecord->isConfidential());
        }

        public function testEvidenceConfidentialityToggleAffectsAnonymousAccess(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            // Evidence is public by default
            $this->assertFalse($anonymousClient->getEvidenceRecord($evidenceUuid)->isConfidential());

            $this->client->updateEvidenceConfidentiality($evidenceUuid, true);
            $this->expectRequestFailure(
                fn() => $anonymousClient->getEvidenceRecord($evidenceUuid),
                [HttpResponseCode::UNAUTHORIZED->value],
                'Anonymous client should not access newly-confidential evidence'
            );

            $this->client->updateEvidenceConfidentiality($evidenceUuid, false);
            $this->assertFalse($anonymousClient->getEvidenceRecord($evidenceUuid)->isConfidential());
        }

        public function testConfidentialEvidenceAttachmentAccessControl(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity(), true);
            $attachmentUuid = $this->client->uploadFileAttachment($evidenceUuid, $this->createSecurityTempFile('Confidential attachment content'))->getUuid();
            $this->createdAttachments[] = $attachmentUuid;

            $this->expectRequestFailure(
                fn() => $this->createLimitedOperator('conf_attach_client', client: true)->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not access confidential evidence attachment'
            );

            $attachmentInfo = $this->createLimitedOperator('conf_attach_manager', management: true)->getAttachmentInfo($attachmentUuid);
            $this->assertEquals($evidenceUuid, $attachmentInfo->getEvidenceUuid());
        }

        public function testUpdateEvidenceTagRequiresOperatorPermissions(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $this->expectRequestFailure(
                fn() => $this->createLimitedOperator('tag_client', client: true)->updateEvidenceTag($evidenceUuid, 'client_tag'),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not update evidence tag'
            );

            $this->createLimitedOperator('tag_operator', operator: true)->updateEvidenceTag($evidenceUuid, 'updated_tag');
            $this->assertEquals('updated_tag', $this->client->getEvidenceRecord($evidenceUuid)->getTag());
        }

        public function testClassifyingEvidenceRequiresManagementPermissions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $clientOnly = $this->createLimitedOperator('classify_client', client: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->classifyEvidence($evidenceUuid, ClassificationFlag::MALICIOUS),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator must not classify evidence'
            );
            $this->expectRequestFailure(
                fn() => $clientOnly->submitEvidence($entityUuid, 'Unauthorized classified evidence', 'Note', 'submit_classify', classification: ClassificationFlag::MALICIOUS),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator must not assign classifications during evidence submission'
            );

            $this->assertNull($this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());
        }
    }
