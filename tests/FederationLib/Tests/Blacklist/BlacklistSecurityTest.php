<?php

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;

    class BlacklistSecurityTest extends ServerTestCase
    {
        public function testBlacklistEntityRequiresManagementPermissions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklist = fn(FederationClient $client) => fn() => $client->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() + 3600);

            $this->expectRequestFailure($blacklist(new FederationClient(getenv('SERVER_ENDPOINT'))), [HttpResponseCode::UNAUTHORIZED->value], 'An anonymous client should not blacklist entities');
            foreach ([
                'no' => $this->createLimitedOperator('bl_none'),
                'client' => $this->createLimitedOperator('bl_create_client', client: true),
                'operator' => $this->createLimitedOperator('bl_create_operator', operator: true),
            ] as $label => $operator)
            {
                $this->expectRequestFailure($blacklist($operator), [HttpResponseCode::FORBIDDEN->value], "An operator with $label permissions should not blacklist entities");
            }

            $this->assertSame([], $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, true));
        }

        public function testLiftExtendAndDeleteRequireManagementPermissions(): void
        {
            $blacklistUuid = $this->createSecurityBlacklist($this->createSecurityEntity());
            $original = $this->client->getBlacklistRecord($blacklistUuid);

            foreach (['client' => $this->createLimitedOperator('bl_client', client: true), 'operator' => $this->createLimitedOperator('bl_operator', operator: true)] as $label => $operator)
            {
                foreach ([
                    'lift' => fn() => $operator->liftBlacklistRecord($blacklistUuid),
                    'extend' => fn() => $operator->extendBlacklistRecord($blacklistUuid, 3600),
                    'delete' => fn() => $operator->deleteBlacklistRecord($blacklistUuid),
                ] as $action => $call)
                {
                    $this->expectRequestFailure($call, [HttpResponseCode::FORBIDDEN->value], "An operator with $label permissions should not $action blacklist records");
                }
            }

            $unchanged = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertFalse($unchanged->isLifted());
            $this->assertEquals($original->getExpires(), $unchanged->getExpires());

            $manager = $this->createLimitedOperator('bl_manager', management: true);
            $manager->liftBlacklistRecord($blacklistUuid);
            $manager->deleteBlacklistRecord($blacklistUuid);
            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);
            $this->expectRequestFailure(fn() => $this->client->getBlacklistRecord($blacklistUuid), [HttpResponseCode::NOT_FOUND->value]);
        }

        public function testBlacklistRejectsInvalidData(): void
        {
            $entityUuid = $this->createSecurityEntity();

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() - 1),
                [HttpResponseCode::BAD_REQUEST->value],
                'Blacklist with expiration in the past should fail'
            );
            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($this->randomUuid(), null, IncidentType::SPAM),
                [HttpResponseCode::NOT_FOUND->value],
                'Blacklist of non-existent entity should fail'
            );

            $this->assertSame([], $this->client->listEntityBlacklistRecords($entityUuid, 1, 100, true));
        }

        public function testLiftAndDeleteAreNotRepeatable(): void
        {
            $blacklistUuid = $this->createSecurityBlacklist($this->createSecurityEntity());

            $this->client->liftBlacklistRecord($blacklistUuid);
            $this->expectRequestFailure(fn() => $this->client->liftBlacklistRecord($blacklistUuid), [HttpResponseCode::BAD_REQUEST->value], 'Lifting an already-lifted blacklist should fail');

            $this->client->deleteBlacklistRecord($blacklistUuid);
            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);
            $this->expectRequestFailure(fn() => $this->client->deleteBlacklistRecord($blacklistUuid), [HttpResponseCode::NOT_FOUND->value], 'Deleting an already-deleted blacklist should fail');
        }

        public function testBlacklistRecordOwnerIsPreserved(): void
        {
            $owner = $this->createLimitedOperator('bl_owner', management: true);
            $ownerUuid = $owner->getSelf()->getUuid();
            $blacklistUuid = $this->createSecurityBlacklist($this->createSecurityEntity($owner), $owner);

            // A different manager extends and lifts the record of the owner
            $manager = $this->createLimitedOperator('bl_other_manager', management: true);
            $manager->extendBlacklistRecord($blacklistUuid, 3600);
            $this->assertEquals($ownerUuid, $this->client->getBlacklistRecord($blacklistUuid)->getOperatorUuid(), 'The owner should be preserved after an extension');

            $manager->liftBlacklistRecord($blacklistUuid);
            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($ownerUuid, $record->getOperatorUuid(), 'The owner should be preserved after a lift');
            $this->assertEquals($manager->getSelf()->getUuid(), $record->getLiftedBy(), 'The operator that lifted the record should be recorded');
        }
    }
