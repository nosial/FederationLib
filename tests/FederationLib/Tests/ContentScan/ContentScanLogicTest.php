<?php

    namespace FederationLib\Tests\ContentScan;

    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\ScannedContent;

    /**
     * How blacklists of the author and of the entities found in the content decide the scanning rules, the risk score
     * and the suggested action of a scan. The content is made of random tokens the classifier does not know, so it is
     * never classified (see BayesianPluginTest) and only the rules under test contribute: the risk score is the neutral
     * point (50) minus 2.3 times the points of the rules, within 0 and 100. Every blacklist also lowers the reputation
     * of the entity, which only adds to the risk, so the risk scores asserted are lower bounds.
     *
     * The classification of content is tested by BayesianPluginTest and PluginRequestsTest.
     */
    class ContentScanLogicTest extends ServerTestCase
    {
        private const float NEUTRAL_POINT = 50.0;
        private const float SCALING_FACTOR = 2.3;

        /**
         * Returns content the classifier has never seen
         */
        private static function unknownText(): string
        {
            return implode(' ', array_map(fn() => bin2hex(random_bytes(6)), range(1, 12)));
        }

        /**
         * Returns the risk score of content that only the given rule points contribute to
         */
        private static function riskScore(float $points): float
        {
            return max(0.0, min(100.0, self::NEUTRAL_POINT - $points * self::SCALING_FACTOR));
        }

        private function createEntity(string $host, ?string $id = null): string
        {
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        private function blacklist(string $entityUuid, IncidentType $type, ?int $expires): string
        {
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, null, $type, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            return $blacklistUuid;
        }

        /**
         * Asserts the points of the rules, every rule not given must not contribute
         *
         * @param array<string, float> $expected The points of the rules expected to contribute
         * @param string[] $ignored Rules whose points are not asserted (the reputation rules affected by a blacklist)
         */
        private function assertRules(array $expected, ScannedContent $scanned, array $ignored = []): void
        {
            $results = $scanned->getScanResults();
            foreach (ScanningRules::cases() as $rule)
            {
                if (in_array($rule->name, $ignored, true))
                {
                    continue;
                }

                $this->assertEquals($expected[$rule->name] ?? 0.0, $results[$rule->name], $rule->name);
            }
        }

        public function testCleanContentHasNoRulesAndNoSuggestedAction(): void
        {
            $scanned = $this->client->scanContent(self::unknownText());

            $this->assertNull($scanned->getClassification());

            // Every rule is reported, none contributes
            $names = array_map(fn(ScanningRules $rule) => $rule->name, ScanningRules::cases());
            $resultNames = array_keys($scanned->getScanResults());
            sort($names);
            sort($resultNames);
            $this->assertSame($names, $resultNames);
            $this->assertRules([], $scanned);

            $this->assertEquals(self::NEUTRAL_POINT, $scanned->getRiskScore());
            $this->assertNull($scanned->getSuggestedAction());
            $this->assertNull($scanned->getSuggestedLiftTimestamp());
        }

        public function testPermanentlyBlacklistedAuthor(): void
        {
            $authorUuid = $this->createEntity($this->uniqueHost('perm-author'), 'perm_author');
            $this->blacklist($authorUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent(self::unknownText(), $authorUuid);

            $this->assertCount(1, $scanned->getAuthorEntity()->getActiveBlacklists());
            $this->assertRules(['AUTHOR_PERMANENTLY_BLACKLISTED' => ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED->getModifier()], $scanned, ['AUTHOR_BAD_REPUTATION']);
            $this->assertEquals(100.0, $scanned->getRiskScore());
            $this->assertSame(SuggestedActionType::PERMANENTLY_BLOCK_ENTITY, $scanned->getSuggestedAction());
            $this->assertNull($scanned->getSuggestedLiftTimestamp());
        }

        public function testTemporarilyBlacklistedAuthor(): void
        {
            $authorUuid = $this->createEntity($this->uniqueHost('temp-author'), 'temp_author');
            $expires = time() + 3600;
            $this->blacklist($authorUuid, IncidentType::SPAM, $expires - 600);
            $this->blacklist($authorUuid, IncidentType::SPAM, $expires);

            $scanned = $this->client->scanContent(self::unknownText(), $authorUuid);

            $this->assertCount(2, $scanned->getAuthorEntity()->getActiveBlacklists());
            $this->assertRules(['AUTHOR_BLACKLISTED' => 2 * ScanningRules::AUTHOR_BLACKLISTED->getModifier()], $scanned, ['AUTHOR_BAD_REPUTATION']);
            $this->assertGreaterThanOrEqual(self::riskScore(2 * ScanningRules::AUTHOR_BLACKLISTED->getModifier()), $scanned->getRiskScore());
            $this->assertSame(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY, $scanned->getSuggestedAction());

            // The block lasts until the longest of the blacklists expires
            $this->assertSame($expires, $scanned->getSuggestedLiftTimestamp());
        }

        public function testPermanentBlacklistOfAuthorOutweighsTemporaryOne(): void
        {
            $authorUuid = $this->createEntity($this->uniqueHost('mixed-author'), 'mixed_author');
            $this->blacklist($authorUuid, IncidentType::SPAM, time() + 3600);
            $this->blacklist($authorUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent(self::unknownText(), $authorUuid);

            $this->assertCount(2, $scanned->getAuthorEntity()->getActiveBlacklists());
            $this->assertRules([
                'AUTHOR_BLACKLISTED' => ScanningRules::AUTHOR_BLACKLISTED->getModifier(),
                'AUTHOR_PERMANENTLY_BLACKLISTED' => ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED->getModifier(),
            ], $scanned, ['AUTHOR_BAD_REPUTATION']);
            $this->assertSame(SuggestedActionType::PERMANENTLY_BLOCK_ENTITY, $scanned->getSuggestedAction());
            $this->assertNull($scanned->getSuggestedLiftTimestamp());
        }

        public function testBlacklistedParentOfAuthorBlocksTheContent(): void
        {
            $parentHost = $this->uniqueHost('author-parent');
            $parentUuid = $this->createEntity($parentHost);
            $childUuid = $this->createEntity('child.' . $parentHost, 'author_child');
            $this->client->setEntityRelationship($childUuid, $parentUuid, EntityRelationshipType::CHILD);
            $this->blacklist($parentUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent(self::unknownText(), $childUuid);

            $author = $scanned->getAuthorEntity();
            $this->assertSame([], $author->getActiveBlacklists());
            $this->assertSame($parentUuid, $author->getParentEntity()->getEntity()->getUuid());
            $this->assertCount(1, $author->getParentEntity()->getActiveBlacklists());

            $this->assertRules(['AUTHOR_PARENT_PERMANENTLY_BLACKLISTED' => ScanningRules::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED->getModifier()], $scanned, ['AUTHOR_BAD_REPUTATION', 'AUTHOR_PARENT_BAD_REPUTATION']);
            $this->assertEquals(100.0, $scanned->getRiskScore());

            // Only a blacklist of the author itself blocks the entity, its parent's blacklist blocks the content
            $this->assertSame(SuggestedActionType::BLOCK_CONTENT, $scanned->getSuggestedAction());
            $this->assertNull($scanned->getSuggestedLiftTimestamp());
        }

        public function testPermanentlyBlacklistedNamedEntity(): void
        {
            $host = $this->uniqueHost('perm-named');
            $entityUuid = $this->createEntity($host);
            $this->blacklist($entityUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent("Visit $host " . self::unknownText());

            $this->assertCount(1, $scanned->getResolvedEntities());
            $this->assertSame($entityUuid, $scanned->getResolvedEntities()[0]->getEntity()->getUuid());
            $this->assertCount(1, $scanned->getResolvedEntities()[0]->getActiveBlacklists());
            $this->assertRules(['NAMED_ENTITY_PERMANENTLY_BLACKLISTED' => ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED->getModifier()], $scanned, ['NAMED_ENTITY_BAD_REPUTATION']);
            $this->assertGreaterThanOrEqual(self::riskScore(ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED->getModifier()), $scanned->getRiskScore());

            // A blacklisted entity found in the content never blocks an author, there is none
            $this->assertContains($scanned->getSuggestedAction(), [SuggestedActionType::CAUTION, SuggestedActionType::BLOCK_CONTENT]);
        }

        public function testTemporarilyBlacklistedNamedEntity(): void
        {
            $host = $this->uniqueHost('temp-named');
            $entityUuid = $this->createEntity($host);
            $this->blacklist($entityUuid, IncidentType::SPAM, time() + 3600);

            $scanned = $this->client->scanContent("Visit $host " . self::unknownText());

            $activeBlacklists = $scanned->getResolvedEntities()[0]->getActiveBlacklists();
            $this->assertCount(1, $activeBlacklists);
            $this->assertNotNull($activeBlacklists[0]->getExpires());
            $this->assertRules(['NAMED_ENTITY_BLACKLISTED' => ScanningRules::NAMED_ENTITY_BLACKLISTED->getModifier()], $scanned, ['NAMED_ENTITY_BAD_REPUTATION']);
            $this->assertGreaterThanOrEqual(self::riskScore(ScanningRules::NAMED_ENTITY_BLACKLISTED->getModifier()), $scanned->getRiskScore());
        }

        public function testBlacklistedParentOfNamedEntity(): void
        {
            $parentHost = $this->uniqueHost('named-parent');
            $childHost = 'child.' . $parentHost;
            $parentUuid = $this->createEntity($parentHost);
            $childUuid = $this->createEntity($childHost);
            $this->client->setEntityRelationship($childUuid, $parentUuid, EntityRelationshipType::CHILD);
            $this->blacklist($parentUuid, IncidentType::MALWARE, null);

            $scanned = $this->client->scanContent("Visit $childHost " . self::unknownText());

            $this->assertCount(1, $scanned->getResolvedEntities());
            $resolved = $scanned->getResolvedEntities()[0];
            $this->assertSame($childUuid, $resolved->getEntity()->getUuid());
            $this->assertSame([], $resolved->getActiveBlacklists());
            $this->assertSame($parentUuid, $resolved->getParentEntity()->getEntity()->getUuid());
            $this->assertCount(1, $resolved->getParentEntity()->getActiveBlacklists());

            $this->assertRules(['NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED' => ScanningRules::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED->getModifier()], $scanned, ['NAMED_ENTITY_BAD_REPUTATION', 'NAMED_ENTITY_PARENT_BAD_REPUTATION']);
            $this->assertGreaterThanOrEqual(self::riskScore(ScanningRules::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED->getModifier()), $scanned->getRiskScore());
        }
    }
