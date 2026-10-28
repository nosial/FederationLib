<?php

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\BlacklistRecord;
    use InvalidArgumentException;

    class BlacklistTest extends ServerTestCase
    {
        /**
         * Blacklists the entity as the root operator and registers the record for cleanup
         */
        private function blacklist(string $entityUuid, IncidentType $type = IncidentType::SPAM, ?int $expires = 7200, ?string $reportUuid = null): string
        {
            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $reportUuid, $type, $expires === null ? null : time() + $expires);
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

        public function testBlacklistEntity(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $reportUuid = $this->createReportForEntity($entityUuid);
            $expires = time() + 3600;

            $blacklistUuid = $this->client->blacklistEntity($entityUuid, $reportUuid, IncidentType::SPAM, $expires);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($blacklistUuid, $record->getUuid());
            $this->assertEquals($this->client->getSelf()->getUuid(), $record->getOperatorUuid());
            $this->assertEquals($entityUuid, $record->getEntityUuid());
            $this->assertEquals($reportUuid, $record->getReportUuid());
            $this->assertSame(IncidentType::SPAM, $record->getType());
            $this->assertEquals($expires, $record->getExpires());
            $this->assertFalse($record->isLifted());
            $this->assertNull($record->getLiftedBy());

            $this->assertSame([$blacklistUuid], self::uuids($this->client->listEntityBlacklistRecords($entityUuid)));
        }

        public function testBlacklistEntityPermanently(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $record = $this->client->getBlacklistRecord($this->blacklist($entityUuid, IncidentType::MALWARE, null));

            $this->assertEquals($entityUuid, $record->getEntityUuid());
            $this->assertSame(IncidentType::MALWARE, $record->getType());
            $this->assertNull($record->getExpires());
            $this->assertFalse($record->isLifted());
        }

        public function testClientValidatesArguments(): void
        {
            $uuid = $this->randomUuid();
            $calls = [
                'The entity identifier must not be empty' => fn() => $this->client->blacklistEntity('', $uuid, IncidentType::SPAM),
                'The report UUID must not be empty' => fn() => $this->client->blacklistEntity($uuid, '', IncidentType::SPAM),
                'The expires parameter must be a positive integer or null' => fn() => $this->client->blacklistEntity($uuid, $uuid, IncidentType::SPAM, -1),
                'Extension seconds must be positive' => fn() => $this->client->extendBlacklistRecord($uuid, 0),
                'Page must be greater than 0' => fn() => $this->client->listBlacklistRecords(0),
                'Limit must be greater than 0' => fn() => $this->client->listBlacklistRecords(1, 0),
            ];

            foreach ($calls as $message => $call)
            {
                try
                {
                    $call();
                    $this->fail("Expected the client to reject the arguments: $message");
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertStringContainsString($message, $e->getMessage());
                }
            }

            foreach ([fn() => $this->client->getBlacklistRecord(''), fn() => $this->client->deleteBlacklistRecord(''), fn() => $this->client->liftBlacklistRecord(''), fn() => $this->client->extendBlacklistRecord('', 3600)] as $call)
            {
                try
                {
                    $call();
                    $this->fail('Expected the client to reject an empty blacklist record UUID');
                }
                catch (InvalidArgumentException $e)
                {
                    $this->assertStringContainsString('Blacklist record UUID cannot be empty', $e->getMessage());
                }
            }

            $this->expectException(InvalidArgumentException::class);
            $this->client->extendBlacklistRecord($uuid, -1);
        }

        public function testUnknownBlacklistRecordIsNotFound(): void
        {
            $uuid = $this->randomUuid();

            foreach ([
                'get' => fn() => $this->client->getBlacklistRecord($uuid),
                'delete' => fn() => $this->client->deleteBlacklistRecord($uuid),
                'lift' => fn() => $this->client->liftBlacklistRecord($uuid),
                'extend' => fn() => $this->client->extendBlacklistRecord($uuid, 3600),
            ] as $operation => $call)
            {
                $this->expectRequestFailure($call, [HttpResponseCode::NOT_FOUND->value], "The $operation of an unknown blacklist record should not be found");
            }
        }

        public function testDeleteBlacklistRecord(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->blacklist($entityUuid);

            $this->client->deleteBlacklistRecord($blacklistUuid);
            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);

            $this->expectRequestFailure(fn() => $this->client->getBlacklistRecord($blacklistUuid), [HttpResponseCode::NOT_FOUND->value]);
            $this->assertSame([], $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, true));
        }

        public function testListBlacklistRecords(): void
        {
            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $created[] = $this->blacklist($this->createSecurityEntity());
            }

            $listed = self::uuids($this->client->listBlacklistRecords(1, 100));
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $listed);
            }

            $pageOne = self::uuids($this->client->listBlacklistRecords(1, 2));
            $pageTwo = self::uuids($this->client->listBlacklistRecords(2, 2));
            $this->assertCount(2, $pageOne);
            $this->assertNotEmpty($pageTwo);
            $this->assertSame([], array_intersect($pageOne, $pageTwo));
        }

        public function testListBlacklistRecordsExcludesLiftedUnlessRequested(): void
        {
            $activeUuid = $this->blacklist($this->createSecurityEntity());
            $liftedUuid = $this->blacklist($this->createSecurityEntity());
            $this->client->liftBlacklistRecord($liftedUuid);

            $withoutLifted = self::uuids($this->client->listBlacklistRecords(1, 100, false));
            $this->assertContains($activeUuid, $withoutLifted);
            $this->assertNotContains($liftedUuid, $withoutLifted);

            $withLifted = self::uuids($this->client->listBlacklistRecords(1, 100, true));
            $this->assertContains($activeUuid, $withLifted);
            $this->assertContains($liftedUuid, $withLifted);
        }

        public function testListBlacklistRecordsSorting(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            foreach ([IncidentType::SPAM, IncidentType::PHISHING, IncidentType::MALWARE] as $index => $type)
            {
                $created[] = $this->blacklist($entityUuid, $type, 3600 * ($index + 1));
            }

            $ascending = $this->client->listBlacklistRecords(1, 100, true, null, 'created', 'ASC');
            $this->assertSame($created, array_values(array_intersect(self::uuids($ascending), $created)));

            $byType = array_values(array_filter($this->client->listBlacklistRecords(1, 100, true, null, 'type', 'DESC'), fn(BlacklistRecord $record) => in_array($record->getUuid(), $created, true)));
            $this->assertSame([IncidentType::PHISHING, IncidentType::MALWARE, IncidentType::SPAM], array_map(fn(BlacklistRecord $record) => $record->getType(), $byType));
        }

        public function testExtendBlacklistRecord(): void
        {
            $blacklistUuid = $this->blacklist($this->createSecurityEntity());
            $original = $this->client->getBlacklistRecord($blacklistUuid);

            $this->client->extendBlacklistRecord($blacklistUuid, 1800);
            $this->assertEquals($original->getExpires() + 1800, $this->client->getBlacklistRecord($blacklistUuid)->getExpires());

            // Extensions accumulate and change nothing else
            $this->client->extendBlacklistRecord($blacklistUuid, 3600);
            $extended = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($original->getExpires() + 1800 + 3600, $extended->getExpires());
            $this->assertEquals($original->getEntityUuid(), $extended->getEntityUuid());
            $this->assertEquals($original->getOperatorUuid(), $extended->getOperatorUuid());
            $this->assertEquals($original->getReportUuid(), $extended->getReportUuid());
            $this->assertEquals($original->getType(), $extended->getType());
            $this->assertFalse($extended->isLifted());
            $this->assertNull($extended->getLiftedBy());
        }

        public function testListBlacklistByStateCategory(): void
        {
            $activeUuid = $this->blacklist($this->createSecurityEntity());
            $permanentUuid = $this->blacklist($this->createSecurityEntity(), expires: null);
            $liftedUuid = $this->blacklist($this->createSecurityEntity());
            $this->client->liftBlacklistRecord($liftedUuid);

            $active = $this->client->listBlacklistRecords(1, 100, true, 'ACTIVE');
            $this->assertContains($activeUuid, self::uuids($active));
            $this->assertContains($permanentUuid, self::uuids($active));
            $this->assertNotContains($liftedUuid, self::uuids($active));
            foreach ($active as $record)
            {
                $this->assertFalse($record->isLifted());
            }

            $lifted = $this->client->listBlacklistRecords(1, 100, true, 'LIFTED');
            $this->assertContains($liftedUuid, self::uuids($lifted));
            $this->assertNotContains($activeUuid, self::uuids($lifted));
            foreach ($lifted as $record)
            {
                $this->assertTrue($record->isLifted());
            }

            $permanent = $this->client->listBlacklistRecords(1, 100, true, 'PERMANENT');
            $this->assertContains($permanentUuid, self::uuids($permanent));
            $this->assertNotContains($activeUuid, self::uuids($permanent));
            foreach ($permanent as $record)
            {
                $this->assertNull($record->getExpires());
                $this->assertFalse($record->isLifted());
            }
        }

        public function testListBlacklistCategoryExpired(): void
        {
            $expiredUuid = $this->blacklist($this->createSecurityEntity(), expires: 1);
            $activeUuid = $this->blacklist($this->createSecurityEntity());
            sleep(2);

            $expired = $this->client->listBlacklistRecords(1, 100, true, 'EXPIRED');
            $this->assertContains($expiredUuid, self::uuids($expired));
            $this->assertNotContains($activeUuid, self::uuids($expired));
            foreach ($expired as $record)
            {
                $this->assertLessThan(time(), $record->getExpires());
            }

            // An expired record is no longer in effect
            $this->assertNotContains($expiredUuid, self::uuids($this->client->listBlacklistRecords(1, 100, true, 'ACTIVE')));
        }

        public function testListBlacklistCategoryWithSort(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $created = [];
            foreach ([IncidentType::MALWARE, IncidentType::SPAM, IncidentType::PHISHING] as $type)
            {
                $created[] = $this->blacklist($entityUuid, $type);
            }

            $records = array_values(array_filter($this->client->listBlacklistRecords(1, 100, true, 'ACTIVE', 'type', 'DESC'), fn(BlacklistRecord $record) => in_array($record->getUuid(), $created, true)));
            $this->assertSame([IncidentType::PHISHING, IncidentType::MALWARE, IncidentType::SPAM], array_map(fn(BlacklistRecord $record) => $record->getType(), $records));
        }

        public function testListBlacklistCategoryFallbackAndCase(): void
        {
            $this->blacklist($this->createSecurityEntity());

            $default = self::uuids($this->client->listBlacklistRecords(1, 10, true));
            $this->assertNotEmpty($default);
            $this->assertSame($default, self::uuids($this->client->listBlacklistRecords(1, 10, true, 'BOGUS_CATEGORY')));

            $upper = self::uuids($this->client->listBlacklistRecords(1, 10, true, 'ACTIVE'));
            $this->assertNotEmpty($upper);
            foreach (['active', 'Active'] as $category)
            {
                $this->assertSame($upper, self::uuids($this->client->listBlacklistRecords(1, 10, true, $category)), $category);
            }
        }
    }
