<?php

    namespace FederationLib\Tests\Evidence;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\EvidenceRecord;

    class EvidenceTest extends ServerTestCase
    {
        /**
         * @param EvidenceRecord[] $records
         * @return string[]
         */
        private static function uuids(array $records): array
        {
            return array_map(fn(EvidenceRecord $record) => $record->getUuid(), $records);
        }

        /**
         * Submits evidence as the root operator and registers it for cleanup
         */
        private function submit(string $entityUuid, string $content, bool $confidential = false, ?ClassificationFlag $classification = null): string
        {
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'Note', 'tag', $confidential, classification: $classification);
            $this->createdEvidenceRecords[] = $evidenceUuid;
            return $evidenceUuid;
        }

        public function testSubmitEvidence(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Unauthorized Login Detected', 'Automatic Detection by System', 'unauthorized_login');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($evidenceUuid, $evidenceRecord->getUuid());
            $this->assertEquals('Unauthorized Login Detected', $evidenceRecord->getTextContent());
            $this->assertEquals('Automatic Detection by System', $evidenceRecord->getNote());
            $this->assertEquals('unauthorized_login', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());
            $this->assertNull($evidenceRecord->getReport());
            $this->assertNull($evidenceRecord->getClassificationFlag());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals($this->client->getSelf()->getUuid(), $evidenceRecord->getOperatorUuid());
        }

        public function testEvidenceIsOwnedByTheSubmittingOperator(): void
        {
            $clientOnly = $this->createLimitedOperator('evidence_owner_client', client: true);
            $entityUuid = $this->createSecurityEntity($clientOnly);
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $clientOnly);

            $record = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertEquals($entityUuid, $record->getEntityUuid());
            $this->assertEquals($clientOnly->getSelf()->getUuid(), $record->getOperatorUuid());
        }

        public function testListEvidence(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $created[] = $this->submit($entityUuid, "Evidence $i");
            }

            $listed = self::uuids($this->client->listEvidence(1, 100));
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $listed);
            }
        }

        public function testListEntityEvidence(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            for ($i = 0; $i < 7; $i++)
            {
                $created[] = $this->submit($entityUuid, "Evidence $i");
            }

            $pageOne = $this->client->listEntityEvidenceRecords($entityUuid, 1, 5);
            $pageTwo = $this->client->listEntityEvidenceRecords($entityUuid, 2, 5);

            $this->assertCount(5, $pageOne);
            $this->assertCount(2, $pageTwo);
            $this->assertEqualsCanonicalizing($created, array_merge(self::uuids($pageOne), self::uuids($pageTwo)));
            foreach (array_merge($pageOne, $pageTwo) as $record)
            {
                $this->assertEquals($entityUuid, $record->getEntityUuid());
            }
        }

        public function testListEvidenceSortByCreatedAscending(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $created[] = $this->submit($entityUuid, "Evidence sort ASC $i");
            }

            $records = $this->client->listEvidence(1, 100, false, null, 'created', 'ASC');
            $this->assertSame($created, array_values(array_intersect(self::uuids($records), $created)));
        }

        public function testListEvidenceExcludesConfidentialUnlessRequested(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $publicUuid = $this->submit($entityUuid, 'Public list evidence');
            $confidentialUuid = $this->submit($entityUuid, 'Confidential list evidence', true);

            $publicList = self::uuids($this->client->listEvidence());
            $this->assertContains($publicUuid, $publicList);
            $this->assertNotContains($confidentialUuid, $publicList);

            $fullList = self::uuids($this->client->listEvidence(1, 100, true));
            $this->assertContains($publicUuid, $fullList);
            $this->assertContains($confidentialUuid, $fullList);
        }

        public function testListEvidenceByConfidentialityCategory(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $publicUuid = $this->submit($entityUuid, 'Public category evidence');
            $confidentialUuid = $this->submit($entityUuid, 'Confidential category evidence', true);

            $confidential = $this->client->listEvidence(1, 100, true, 'CONFIDENTIAL');
            $this->assertContains($confidentialUuid, self::uuids($confidential));
            $this->assertNotContains($publicUuid, self::uuids($confidential));
            foreach ($confidential as $record)
            {
                $this->assertTrue($record->isConfidential());
            }

            $notConfidential = $this->client->listEvidence(1, 100, true, 'NOT_CONFIDENTIAL');
            $this->assertContains($publicUuid, self::uuids($notConfidential));
            $this->assertNotContains($confidentialUuid, self::uuids($notConfidential));
            foreach ($notConfidential as $record)
            {
                $this->assertFalse($record->isConfidential());
            }
        }

        public function testListEvidenceCategoryUnclassified(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $unclassifiedUuid = $this->submit($entityUuid, 'Unclassified evidence');
            $classifiedUuid = $this->submit($entityUuid, 'Classified evidence', classification: ClassificationFlag::NORMAL);

            $records = $this->client->listEvidence(1, 100, true, 'UNCLASSIFIED');
            $this->assertContains($unclassifiedUuid, self::uuids($records));
            $this->assertNotContains($classifiedUuid, self::uuids($records));
            foreach ($records as $record)
            {
                $this->assertNull($record->getClassificationFlag());
            }
        }

        public function testListEvidenceCategoryWithSort(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $created[] = $this->submit($entityUuid, "Category sort $i");
            }

            $records = $this->client->listEvidence(1, 100, true, 'NOT_CONFIDENTIAL', 'created', 'ASC');
            $this->assertSame($created, array_values(array_intersect(self::uuids($records), $created)));
        }

        public function testEvidenceDeletionCascadesToAttachments(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());
            $attachmentUuid = $this->client->uploadFileAttachment($evidenceUuid, $this->createSecurityTempFile('Cascade test content'))->getUuid();
            $this->assertCount(1, $this->client->getEvidenceAttachments($evidenceUuid));

            $this->client->deleteEvidence($evidenceUuid);
            $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid);

            $this->expectRequestFailure(
                fn() => $this->client->getAttachmentInfo($attachmentUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Attachment should be deleted when parent evidence is deleted'
            );
        }
    }
