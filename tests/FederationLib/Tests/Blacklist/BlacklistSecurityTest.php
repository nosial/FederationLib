<?php

    namespace FederationLib\Tests\Blacklist;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class BlacklistSecurityTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdBlacklistRecords = [];
        private array $createdEvidenceRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdBlacklistRecords as $blacklistRecordUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistRecordUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete blacklist record $blacklistRecordUuid: " . $e->getMessage());
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

            foreach ($this->createdEvidenceRecords as $evidenceUuid)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdBlacklistRecords = [];
            $this->createdEntities = [];
            $this->createdOperators = [];
            $this->createdEvidenceRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testBlacklistEntityUnauthorized(): void
        {
            $basicOperatorUuid = $this->client->createOperator(uniqid('test_operator_'));
            $this->createdOperators[] = $basicOperatorUuid;

            $this->client->setManagementPermissions($basicOperatorUuid, false);
            $this->client->setOperatorPermissions($basicOperatorUuid, false);
            $this->client->setClientPermissions($basicOperatorUuid, false);

            $basicOperator = $this->client->getOperator($basicOperatorUuid);
            $basicClient = new FederationClient(getenv('SERVER_ENDPOINT'), $basicOperator->getAccessToken());

            $entityUuid = $this->client->pushEntity('unauthorized-test.com', 'unauthorized_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $this->client->submitEvidence($entityUuid, 'Test content', 'Test note', 'test');

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $basicClient->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM);
        }

        public function testSecurityBlacklistLiftAndDeleteRestrictions(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $clientOnly = $this->createLimitedOperator('bl_client', client: true);
            $operatorOnly = $this->createLimitedOperator('bl_operator', operator: true);

            $unauthorizedActions = [
                'clientLift' => fn() => $clientOnly->liftBlacklistRecord($blacklistUuid),
                'clientDelete' => fn() => $clientOnly->deleteBlacklistRecord($blacklistUuid),
                'operatorLift' => fn() => $operatorOnly->liftBlacklistRecord($blacklistUuid),
                'operatorDelete' => fn() => $operatorOnly->deleteBlacklistRecord($blacklistUuid),
            ];

            foreach ($unauthorizedActions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Unauthorized operator should not $name");
            }

            $manager = $this->createLimitedOperator('bl_manager', management: true);
            $manager->liftBlacklistRecord($blacklistUuid);
            $manager->deleteBlacklistRecord($blacklistUuid);

            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);
        }

        public function testSecurityBlacklistWithInvalidOrExpiredData(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($entityUuid, 'not-a-uuid', IncidentType::SPAM),
                [HttpResponseCode::BAD_REQUEST->value],
                'Blacklist with invalid evidence UUID format should fail'
            );

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() - 1),
                [HttpResponseCode::BAD_REQUEST->value],
                'Blacklist with expiration in the past should fail'
            );

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($this->randomUuid(), $evidenceUuid, IncidentType::SPAM),
                [HttpResponseCode::NOT_FOUND->value],
                'Blacklist of non-existent entity should fail'
            );
        }

        public function testSecurityBlacklistRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $clientOnly = $this->createLimitedOperator('bl_create_client', client: true);
            $operatorOnly = $this->createLimitedOperator('bl_create_operator', operator: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not create blacklists'
            );

            $this->expectRequestFailure(
                fn() => $operatorOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not create blacklists'
            );
        }

        public function testSecurityLiftAlreadyLiftedBlacklist(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->liftBlacklistRecord($blacklistUuid);

            $this->expectRequestFailure(
                fn() => $this->client->liftBlacklistRecord($blacklistUuid),
                [HttpResponseCode::BAD_REQUEST->value],
                'Lifting an already-lifted blacklist should fail'
            );
        }

        public function testSecurityDeleteAlreadyDeletedBlacklist(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $this->client->deleteBlacklistRecord($blacklistUuid);
            $this->removeFromCleanup($this->createdBlacklistRecords, $blacklistUuid);

            $this->expectRequestFailure(
                fn() => $this->client->deleteBlacklistRecord($blacklistUuid),
                [HttpResponseCode::NOT_FOUND->value],
                'Deleting an already-deleted blacklist should fail'
            );
        }

        public function testSecurityBlacklistRecordOwnerPreservedAfterLift(): void
        {
            $owner = $this->createLimitedOperator('bl_owner_preserve', management: true, client: true);
            $ownerUuid = $owner->getSelf()->getUuid();

            $entityUuid = $owner->pushEntity('bl-owner-preserve.com', 'bl_owner_preserve');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $owner->submitEvidence($entityUuid, 'Owner evidence', 'Note', 'bl_owner');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $owner->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $owner->liftBlacklistRecord($blacklistUuid);

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($ownerUuid, $record->getOperatorUuid(), 'Blacklist record operator UUID should be preserved after lift');
            $this->assertEquals($ownerUuid, $record->getLiftedBy(), 'Lifted-by UUID should match the operator who lifted');
        }

        public function testSecurityBlacklistRecordOwnerPreservedAfterExtend(): void
        {
            $owner = $this->createLimitedOperator('bl_owner_ext', management: true, client: true);
            $ownerUuid = $owner->getSelf()->getUuid();

            $entityUuid = $owner->pushEntity('bl-owner-ext.com', 'bl_owner_ext');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $owner->submitEvidence($entityUuid, 'Owner ext evidence', 'Note', 'bl_owner_ext');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $blacklistUuid = $owner->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            $owner->extendBlacklistRecord($blacklistUuid, 3600);

            $record = $this->client->getBlacklistRecord($blacklistUuid);
            $this->assertEquals($ownerUuid, $record->getOperatorUuid(), 'Blacklist record operator UUID should be preserved after extend');
        }

        public function testSecurityBlacklistWithEvidenceFromDifferentEntity(): void
        {
            $entityA = $this->createSecurityEntity();
            $entityB = $this->createSecurityEntity();
            $evidenceForB = $this->createSecurityEvidence($entityB);

            $this->expectRequestFailure(
                fn() => $this->client->blacklistEntity($entityA, $evidenceForB, IncidentType::SPAM, time() + 3600),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::NOT_FOUND->value],
                'Blacklisting entity A with evidence belonging to entity B should be rejected'
            );
        }

        public function testSecurityExtendBlacklistRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);

            $clientOnly = $this->createLimitedOperator('ext_client', client: true);
            $operatorOnly = $this->createLimitedOperator('ext_operator', operator: true);

            $unauthorized = [
                fn() => $clientOnly->extendBlacklistRecord($blacklistUuid, 3600),
                fn() => $operatorOnly->extendBlacklistRecord($blacklistUuid, 3600),
            ];

            foreach ($unauthorized as $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], 'Non-management operator should not extend blacklists');
            }
        }

    }
