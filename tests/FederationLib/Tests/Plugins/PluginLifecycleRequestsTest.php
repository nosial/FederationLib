<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Plugins;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    /**
     * The STARTUP and MAINTENANCE events in the test environment: the STARTUP event handler of the TestPlugin is
     * executed once by `federationlib init` when the container starts, the MAINTENANCE event handler on every
     * maintenance run, which the TestPlugin runs within a request so the test units do not wait for the crontab.
     */
    class PluginLifecycleRequestsTest extends TestCase
    {
        use TestHelpers;

        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $this->jsonRequest('DELETE', '/test-plugin/lifecycle-events', $this->rootToken());
        }

        protected function tearDown(): void
        {
            $this->jsonRequest('DELETE', '/test-plugin/lifecycle-events', $this->rootToken());
            $this->cleanupRecords();
        }

        private function getEvents(string $event): array
        {
            [$code, $body] = $this->jsonRequest('GET', '/test-plugin/lifecycle-events', $this->rootToken());
            $this->assertSame(200, $code, json_encode($body));
            return array_values(array_filter($body, fn(array $recorded) => $recorded['event'] === $event));
        }

        public function testStartupWasDispatchedWhenTheServerStarted(): void
        {
            $startups = $this->getEvents('STARTUP');

            $this->assertNotEmpty($startups, 'The STARTUP event handler should have been executed when the container started');
            $latest = end($startups);
            $this->assertSame(DatabaseConnection::SCHEMA_VERSION, $latest['schema_version']);
            $this->assertIsBool($latest['database_created']);
            $this->assertLessThanOrEqual(time(), $latest['started_at']);
            // The database was either created by this start, or migrated from an earlier version, or already current
            if($latest['database_created'])
            {
                $this->assertNull($latest['migrated_from']);
            }
        }

        public function testStartupIsNotDispatchedByRequests(): void
        {
            $before = count($this->getEvents('STARTUP'));

            $this->client->getServerInformation();
            $this->client->listEntities(1, 1);

            $this->assertSame($before, count($this->getEvents('STARTUP')));
        }

        public function testMaintenanceRunDispatchesMaintenance(): void
        {
            $this->assertSame([], $this->getEvents('MAINTENANCE'));

            $before = time();
            [$code, $body] = $this->jsonRequest('POST', '/test-plugin/run-maintenance', $this->rootToken());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(0, $body['exit_code'], $body['output']);

            $runs = $this->getEvents('MAINTENANCE');
            $this->assertCount(1, $runs);
            $this->assertGreaterThanOrEqual($before, $runs[0]['started_at']);
            // Maintenance is enabled by default
            $this->assertTrue($runs[0]['cleaning_enabled']);
            $this->assertNull($runs[0]['archive_path']);
            $this->assertIsArray($runs[0]['removed']);

            // The number of removed records of every task that ran; entities are kept forever by default
            $this->assertArrayNotHasKey('entity', $runs[0]['removed']);
            foreach($runs[0]['removed'] as $task => $removed)
            {
                $this->assertIsString($task);
                $this->assertIsInt($removed);
            }
        }

        public function testEveryMaintenanceRunIsDispatched(): void
        {
            for($i = 0; $i < 3; $i++)
            {
                [$code, $body] = $this->jsonRequest('POST', '/test-plugin/run-maintenance', $this->rootToken());
                $this->assertSame(200, $code, json_encode($body));
            }

            $this->assertCount(3, $this->getEvents('MAINTENANCE'));
        }

        public function testResettingKeepsTheStartup(): void
        {
            $startups = count($this->getEvents('STARTUP'));
            $this->jsonRequest('POST', '/test-plugin/run-maintenance', $this->rootToken());

            $this->jsonRequest('DELETE', '/test-plugin/lifecycle-events', $this->rootToken());

            $this->assertSame([], $this->getEvents('MAINTENANCE'));
            $this->assertCount($startups, $this->getEvents('STARTUP'));
        }

        public function testMaintenanceKeepsRecentRecords(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            [$code, $body] = $this->jsonRequest('POST', '/test-plugin/run-maintenance', $this->rootToken());
            $this->assertSame(200, $code, json_encode($body));

            $this->assertSame($entityUuid, $this->client->getEntityRecord($entityUuid)->getUuid());
            $this->assertSame($evidenceUuid, $this->client->getEvidenceRecord($evidenceUuid)->getUuid());
        }

        public function testRunMaintenanceRouteRequiresPermissions(): void
        {
            $management = $this->createLimitedOperator('maintenance_management', management: true);
            [$code] = $this->rawRequest('POST', '/test-plugin/run-maintenance', $management->getAccessToken());
            $this->assertSame(403, $code);

            [$code] = $this->rawRequest('POST', '/test-plugin/run-maintenance');
            $this->assertSame(401, $code);

            $this->assertSame([], $this->getEvents('MAINTENANCE'));
        }
    }
