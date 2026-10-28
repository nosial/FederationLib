<?php

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\BlacklistRecord;

    class BlacklistLogicTest extends ServerTestCase
    {
        /**
         * Blacklists the entity as the root operator and registers the record for cleanup
         */
        private function blacklist(string $entityUuid, IncidentType $type = IncidentType::SPAM, ?int $expires = 7200): string
        {
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, null, $type, $expires === null ? null : time() + $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            return $blacklistUuid;
        }

        /**
         * @param BlacklistRecord[] $records
         * @return string[]
         */
        private static function uuids(array $records): array
        {
            return array_map(fn(BlacklistRecord $record) => $record->getUuid(), $records);
        }

        public function testEveryIncidentTypeCanBeBlacklisted(): void
        {
            $entityUuid = $this->createSecurityEntity();

            $created = [];
            foreach (IncidentType::cases() as $type)
            {
                $blacklistUuid = $this->blacklist($entityUuid, $type);
                $created[] = $blacklistUuid;
                $this->assertSame($type, $this->client->getBlacklistRecord($blacklistUuid)->getType());
            }

            $this->assertEqualsCanonicalizing($created, self::uuids($this->client->listEntityBlacklistRecords($entityUuid)));
        }

        public function testSameTypeCanBeBlacklistedRepeatedly(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [$this->blacklist($entityUuid), $this->blacklist($entityUuid), $this->blacklist($entityUuid)];

            $this->assertCount(3, array_unique($created));
            $this->assertEqualsCanonicalizing($created, self::uuids($this->client->listEntityBlacklistRecords($entityUuid)));
        }

        public function testLiftKeepsTheRecord(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->blacklist($entityUuid);
            $original = $this->client->getBlacklistRecord($blacklistUuid);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $lifted = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertTrue($lifted->isLifted());
            $this->assertEquals($this->client->getSelf()->getUuid(), $lifted->getLiftedBy());
            $this->assertEquals($original->getEntityUuid(), $lifted->getEntityUuid());
            $this->assertEquals($original->getOperatorUuid(), $lifted->getOperatorUuid());
            $this->assertEquals($original->getReportUuid(), $lifted->getReportUuid());
            $this->assertEquals($original->getType(), $lifted->getType());
            $this->assertEquals($original->getExpires(), $lifted->getExpires());

            // A lifted record no longer counts against the entity, but remains listed on request
            $this->assertSame([], $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, false));
            $this->assertSame([$blacklistUuid], self::uuids($this->client->listEntityBlacklistRecords($entityUuid, 1, 100, true)));
        }

        public function testExpiredBlacklistRemainsRetrievable(): void
        {
            $expires = time() + 1;
            $blacklistUuid = $this->client->blacklistEntity($this->createSecurityEntity(), null, IncidentType::SPAM, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($expires, $record->getExpires());
            $this->assertFalse($record->isLifted());

            sleep(2);

            // An expired record is no longer in effect, it reports itself as lifted
            $expired = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($expires, $expired->getExpires());
            $this->assertTrue($expired->isLifted());
        }

        public function testOnlyActiveTemporaryRecordsCanBeExtended(): void
        {
            $permanentUuid = $this->blacklist($this->createSecurityEntity(), expires: null);
            $liftedUuid = $this->blacklist($this->createSecurityEntity());
            $this->client->liftBlacklistRecord($liftedUuid);
            $expiredUuid = $this->blacklist($this->createSecurityEntity(), expires: 1);
            sleep(2);

            foreach (['permanent' => $permanentUuid, 'lifted' => $liftedUuid, 'expired' => $expiredUuid] as $state => $blacklistUuid)
            {
                $before = $this->client->getBlacklistRecord($blacklistUuid)->getExpires();
                $this->expectRequestFailure(
                    fn() => $this->client->extendBlacklistRecord($blacklistUuid, 3600),
                    [HttpResponseCode::BAD_REQUEST->value],
                    "A $state blacklist record should not be extended"
                );
                $this->assertEquals($before, $this->client->getBlacklistRecord($blacklistUuid)->getExpires());
            }
        }

        public function testBlacklistReducesReputationOfEntityAndRelatedEntities(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $relatedUuid = $this->createSecurityEntity();
            $this->client->setEntityRelationship($relatedUuid, $entityUuid, EntityRelationshipType::ALTERNATIVE);

            $entityBefore = $this->client->getEntityRecord($entityUuid)->getReputation();
            $relatedBefore = $this->client->getEntityRecord($relatedUuid)->getReputation();

            $this->blacklist($entityUuid);

            // Default configuration: blacklisted entity -50, related entities -10
            $this->assertEquals(-50, $this->client->getEntityRecord($entityUuid)->getReputation() - $entityBefore);
            $this->assertEquals(-10, $this->client->getEntityRecord($relatedUuid)->getReputation() - $relatedBefore);
        }

        public function testBlacklistDoesNotAffectWhitelistedEntityReputation(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $this->client->setEntityWhitelist($entityUuid, true);
            $before = $this->client->getEntityRecord($entityUuid)->getReputation();

            // Whitelisting does not prevent an operator from blacklisting the entity (OFD "Whitelist")
            $blacklistUuid = $this->blacklist($entityUuid);
            $this->assertFalse($this->client->getBlacklistRecord($blacklistUuid)->isLifted());

            $this->assertEquals($before, $this->client->getEntityRecord($entityUuid)->getReputation(), 'Whitelisted entity reputation must not change when blacklisted');
        }

        public function testBlacklistDoesNotAffectWhitelistedRelatedEntityReputation(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $whitelistedChildUuid = $this->createSecurityEntity();
            $childUuid = $this->createSecurityEntity();
            $whitelistedParentUuid = $this->createSecurityEntity();

            // Related entities in both directions: entities referencing the blacklisted entity, and its own relationship target
            $this->client->setEntityRelationship($whitelistedChildUuid, $entityUuid, EntityRelationshipType::ALTERNATIVE);
            $this->client->setEntityRelationship($childUuid, $entityUuid, EntityRelationshipType::ALTERNATIVE);
            $this->client->setEntityRelationship($entityUuid, $whitelistedParentUuid, EntityRelationshipType::PROXY);
            $this->client->setEntityWhitelist($whitelistedChildUuid, true);
            $this->client->setEntityWhitelist($whitelistedParentUuid, true);

            $whitelistedChildBefore = $this->client->getEntityRecord($whitelistedChildUuid)->getReputation();
            $childBefore = $this->client->getEntityRecord($childUuid)->getReputation();
            $whitelistedParentBefore = $this->client->getEntityRecord($whitelistedParentUuid)->getReputation();

            $this->blacklist($entityUuid);

            $this->assertEquals($whitelistedChildBefore, $this->client->getEntityRecord($whitelistedChildUuid)->getReputation(), 'Whitelisted referencing entity reputation must not change');
            $this->assertEquals($whitelistedParentBefore, $this->client->getEntityRecord($whitelistedParentUuid)->getReputation(), 'Whitelisted relationship target reputation must not change');
            $this->assertEquals(-10, $this->client->getEntityRecord($childUuid)->getReputation() - $childBefore, 'Non-whitelisted related entity must still be affected');
        }
    }
