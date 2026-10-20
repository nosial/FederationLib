<?php

    namespace FederationLib\Tests\AuditLogs;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    class AuditLogsSecurityTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];

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
                    Logger::getLogger()->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
        }

        public function testSecurityUnauthenticatedAuditLogAccessIsPublic(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);

            // Audit logs are public by default; unauthenticated clients can list and view
            // public entries, so these calls succeed rather than fail.
            $logs = $unauthenticatedClient->listAuditLogs();
            $this->assertIsArray($logs);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $unauthenticatedClient->getAuditLogRecord('00000000-0000-0000-0000-000000000000');
        }

        public function testSecurityOperatorAuditLogsOfOthersFollowPublicAccess(): void
        {
            $actor = $this->createLimitedOperator('audit_actor', operator: true);
            $victim = $this->createLimitedOperator('audit_victim', management: true);
            $snooper = $this->createLimitedOperator('audit_snooper', client: true);
            $actorUuid = $actor->getSelf()->getUuid();

            // Generate an OPERATOR_UPDATED audit entry as the actor.
            $actor->setManagementPermissions($victim->getSelf()->getUuid(), false);

            // Audit logs are public by default, so another operator without operator permissions receives the public
            // entries of the actor, never more than an anonymous client and never entries of other operators
            $snooperEntries = $snooper->listOperatorAuditLogs($actorUuid);
            $anonymousEntries = new FederationClient(getenv('SERVER_ENDPOINT'))->listOperatorAuditLogs($actorUuid);

            $this->assertNotEmpty($snooperEntries);
            $this->assertEquals(
                array_map(fn($entry) => $entry->getUuid(), $anonymousEntries),
                array_map(fn($entry) => $entry->getUuid(), $snooperEntries)
            );
            foreach ($snooperEntries as $entry)
            {
                $this->assertEquals($actorUuid, $entry->getOperatorUuid());
            }
        }
    }
