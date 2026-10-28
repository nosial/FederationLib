<?php

    namespace FederationLib\Tests\Evidence;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\EvidenceRecord;
    use InvalidArgumentException;

    class EvidenceLogicTest extends ServerTestCase
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
         * Submits a report about the entity and returns its UUID
         */
        private function submitReport(string $entityUuid, string $content, IncidentType $type = IncidentType::SPAM): string
        {
            $submission = $this->client->submitReport($entityUuid, ['text_content' => $content], $type);
            $this->createdReports[] = $submission->getReport()->getUuid();
            $this->createdEvidenceRecords[] = $submission->getEvidence()[0]->getUuid();
            return $submission->getReport()->getUuid();
        }

        public function testEvidenceLifecycle(): void
        {
            $evidenceUuid = $this->client->submitEvidence($this->createSecurityEntity(), 'Original evidence content', 'Original note', 'lifecycle_test');
            $this->createdEvidenceRecords[] = $evidenceUuid;
            $original = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertFalse($original->isConfidential());

            // Changing the confidentiality changes nothing else
            foreach ([true, false, true, false] as $confidential)
            {
                $this->client->updateEvidenceConfidentiality($evidenceUuid, $confidential);
                $record = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertSame($confidential, $record->isConfidential());
                $this->assertEquals($original->getEntityUuid(), $record->getEntityUuid());
                $this->assertEquals($original->getOperatorUuid(), $record->getOperatorUuid());
                $this->assertEquals($original->getTextContent(), $record->getTextContent());
                $this->assertEquals($original->getNote(), $record->getNote());
                $this->assertEquals($original->getTag(), $record->getTag());
            }

            $this->client->deleteEvidence($evidenceUuid);
            $this->removeFromCleanup($this->createdEvidenceRecords, $evidenceUuid);
            $this->expectRequestFailure(
                fn() => $this->client->getEvidenceRecord($evidenceUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Deleted evidence should no longer be found'
            );
        }

        public function testEvidenceContentVariations(): void
        {
            $entityUuid = $this->createSecurityEntity();

            $testCases = [
                ['content' => 'Single word', 'note' => 'Single word test', 'tag' => 'single'],
                ['content' => str_repeat('A', 10000), 'note' => 'Large content', 'tag' => 'large'],
                ['content' => "Multi\nline\ncontent\ntest", 'note' => 'Multiline test', 'tag' => 'multiline'],
                ['content' => 'Special chars: @#$%^&*()[]{}|;:\'",.<>?/', 'note' => 'Special chars', 'tag' => 'special'],
                ['content' => json_encode(['type' => 'json', 'data' => ['key' => 'value']]), 'note' => 'JSON data', 'tag' => 'json'],
                ['content' => 'Unicode content: 你好世界 🌍 åäö ñ', 'note' => 'Unicode test', 'tag' => 'unicode'],
            ];

            foreach ($testCases as $testCase)
            {
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $testCase['content'], $testCase['note'], $testCase['tag']);
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $record = $this->client->getEvidenceRecord($evidenceUuid);
                $this->assertSame($testCase['content'], $record->getTextContent());
                $this->assertSame($testCase['note'], $record->getNote());
                $this->assertSame($testCase['tag'], $record->getTag());
            }
        }

        public function testLinkingEvidenceMovesItBetweenReports(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $reportA = $this->submitReport($entityUuid, 'Report A');
            $reportB = $this->submitReport($entityUuid, 'Report B', IncidentType::SCAM);

            $this->client->addEvidenceToReport($evidenceUuid, $reportA);
            $this->assertEquals($reportA, $this->client->getEvidenceRecord($evidenceUuid)->getReport());
            $this->assertContains($evidenceUuid, self::uuids($this->client->listReportEvidenceRecords($reportA, includeConfidential: true)));

            // Evidence belongs to one report at a time
            $this->client->addEvidenceToReport($evidenceUuid, $reportB);
            $this->assertEquals($reportB, $this->client->getEvidenceRecord($evidenceUuid)->getReport());
            $this->assertContains($evidenceUuid, self::uuids($this->client->listReportEvidenceRecords($reportB, includeConfidential: true)));
            $this->assertNotContains($evidenceUuid, self::uuids($this->client->listReportEvidenceRecords($reportA, includeConfidential: true)));
        }

        public function testEvidenceTagValidation(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            foreach (['valid_tag', 'tag-123', 'a', str_repeat('a', 32)] as $tag)
            {
                $this->client->updateEvidenceTag($evidenceUuid, $tag);
                $this->assertEquals($tag, $this->client->getEvidenceRecord($evidenceUuid)->getTag());
            }

            $invalidTags = ['tag with space', 'tag!special', 'tag@invalid', str_repeat('a', 33), "tag\nwith\nnewlines", "tag\twith\ttabs", "tag\x00null"];
            foreach ($invalidTags as $tag)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->updateEvidenceTag($evidenceUuid, $tag),
                    [HttpResponseCode::BAD_REQUEST->value],
                    'Invalid tag ' . json_encode($tag) . ' should be rejected'
                );
            }

            // The last valid tag is kept
            $this->assertEquals(str_repeat('a', 32), $this->client->getEvidenceRecord($evidenceUuid)->getTag());

            $this->expectException(InvalidArgumentException::class);
            $this->client->updateEvidenceTag($evidenceUuid, '');
        }

        public function testListEvidenceFallbacksAndCaseInsensitivity(): void
        {
            $this->createSecurityEvidence($this->createSecurityEntity());

            $default = self::uuids($this->client->listEvidence(1, 10, true));
            $this->assertNotEmpty($default);
            $this->assertSame($default, self::uuids($this->client->listEvidence(1, 10, true, null, 'created', 'BAD_ORDER')), 'An invalid order falls back to the default');
            $this->assertSame($default, self::uuids($this->client->listEvidence(1, 10, true, 'BOGUS_CATEGORY')), 'An invalid category falls back to no category');

            $upper = self::uuids($this->client->listEvidence(1, 10, true, 'NOT_CONFIDENTIAL'));
            $this->assertNotEmpty($upper);
            foreach (['not_confidential', 'Not_Confidential'] as $category)
            {
                $this->assertSame($upper, self::uuids($this->client->listEvidence(1, 10, true, $category)), $category);
            }
        }

        public function testEvidenceClassificationCanBeReplaced(): void
        {
            $evidenceUuid = $this->createSecurityEvidence($this->createSecurityEntity());

            $this->client->classifyEvidence($evidenceUuid, ClassificationFlag::NORMAL);
            $this->assertSame(ClassificationFlag::NORMAL, $this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());

            $this->client->classifyEvidence($evidenceUuid, ClassificationFlag::MALICIOUS);
            $this->assertSame(ClassificationFlag::MALICIOUS, $this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());

            // Classifying with the same classification succeeds and changes nothing
            $this->client->classifyEvidence($evidenceUuid, ClassificationFlag::MALICIOUS);
            $this->assertSame(ClassificationFlag::MALICIOUS, $this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());
        }

        public function testSubmitEvidenceWithClassificationPersistsClassification(): void
        {
            $evidenceUuid = $this->client->submitEvidence($this->createSecurityEntity(), 'Classified at submission', 'Note', 'submit_classification', classification: ClassificationFlag::SUSPICIOUS);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $this->assertSame(ClassificationFlag::SUSPICIOUS, $this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());

            // A classification assigned at submission can be replaced
            $this->client->classifyEvidence($evidenceUuid, ClassificationFlag::NORMAL);
            $this->assertSame(ClassificationFlag::NORMAL, $this->client->getEvidenceRecord($evidenceUuid)->getClassificationFlag());
        }

        public function testCloseReportSkipsPreviouslyClassifiedEvidence(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $submission = $this->client->submitReport($entityUuid, ['text_content' => 'Previously classified report evidence'], IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $classifiedEvidenceUuid = $submission->getEvidence()[0]->getUuid();
            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $classifiedEvidenceUuid;

            $this->client->classifyEvidence($classifiedEvidenceUuid, ClassificationFlag::NORMAL);

            $unclassifiedEvidenceUuid = $this->createSecurityEvidence($entityUuid);
            $this->client->addEvidenceToReport($unclassifiedEvidenceUuid, $reportUuid);

            $this->client->assignOperatorToReport($reportUuid, $this->client->getSelf()->getUuid());
            $this->client->closeReport($reportUuid, ClassificationFlag::MALICIOUS);

            $this->assertSame(ClassificationFlag::NORMAL, $this->client->getEvidenceRecord($classifiedEvidenceUuid)->getClassificationFlag());
            $this->assertSame(ClassificationFlag::MALICIOUS, $this->client->getEvidenceRecord($unclassifiedEvidenceUuid)->getClassificationFlag());
        }
    }
