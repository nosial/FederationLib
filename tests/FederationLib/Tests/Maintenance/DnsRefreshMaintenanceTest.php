<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Maintenance;

    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    /**
     * The DNS details maintenance keeps in the metadata of domain entities, with the default configuration (refreshed
     * every 90 days). Maintenance is run through the TestPlugin. The hosts of the test entities do not exist, so their
     * lookups are expected to fail or find no records, which is still recorded.
     */
    class DnsRefreshMaintenanceTest extends TestCase
    {
        use TestHelpers;

        private const array STATUSES = ['RESOLVED', 'NO_RECORDS', 'FAILED'];

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
        }

        protected function tearDown(): void
        {
            $this->cleanupRecords();
        }

        private function runMaintenance(): string
        {
            [$code, $body] = $this->jsonRequest('POST', '/test-plugin/run-maintenance', $this->rootToken());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(0, $body['exit_code'], $body['output']);
            return $body['output'];
        }

        private function createEntity(string $host, ?string $id = null, ?array $metadata = null): string
        {
            $entityUuid = $this->client->pushEntity($host, $id, $metadata);
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        public function testDomainEntityReceivesDnsDetails(): void
        {
            $entityUuid = $this->createEntity($this->uniqueHost('dns'), null, ['note' => 'kept']);
            $before = $this->client->getEntityRecord($entityUuid);
            $startedAt = time();

            $output = $this->runMaintenance();
            $this->assertStringContainsString('Refreshed the DNS details of', $output);

            $after = $this->client->getEntityRecord($entityUuid);
            $metadata = $after->getMetadata();

            $this->assertIsArray($metadata);
            $this->assertSame('kept', $metadata['note'], 'The other metadata keys are kept');
            $this->assertIsInt($metadata['dns_resolved']);
            $this->assertGreaterThanOrEqual($startedAt, $metadata['dns_resolved']);
            $this->assertContains($metadata['dns_status'], self::STATUSES);
            foreach(array_keys($metadata) as $key)
            {
                $this->assertContains($key, ['note', 'dns_resolved', 'dns_status', 'dns_a', 'dns_aaaa', 'dns_cname', 'dns_mx', 'dns_ns'], $key);
            }

            // The details are maintained by the server, the entity is not considered changed
            $this->assertSame($before->getUpdated(), $after->getUpdated());
        }

        public function testDetailsAreNotRefreshedWithinTheInterval(): void
        {
            $entityUuid = $this->createEntity($this->uniqueHost('dns'));

            $this->runMaintenance();
            $first = $this->client->getEntityRecord($entityUuid)->getMetadata()['dns_resolved'];

            sleep(1);
            $this->runMaintenance();

            $this->assertSame($first, $this->client->getEntityRecord($entityUuid)->getMetadata()['dns_resolved']);
        }

        public function testReplacedMetadataIsResolvedAgain(): void
        {
            $entityUuid = $this->createEntity($this->uniqueHost('dns'));
            $this->runMaintenance();

            // A client replacing the metadata removes the details, the next run restores them
            $this->client->updateEntity($entityUuid, ['note' => 'replaced']);
            $this->assertArrayNotHasKey('dns_resolved', $this->client->getEntityRecord($entityUuid)->getMetadata());

            $this->runMaintenance();

            $metadata = $this->client->getEntityRecord($entityUuid)->getMetadata();
            $this->assertSame('replaced', $metadata['note']);
            $this->assertArrayHasKey('dns_resolved', $metadata);
        }

        public function testFutureResolutionTimeIsRefreshed(): void
        {
            // A resolution time a client asserted in the future does not keep the server from resolving the name
            $entityUuid = $this->createEntity($this->uniqueHost('dns'), null, ['dns_resolved' => time() + 86400 * 365, 'dns_status' => 'RESOLVED', 'dns_a' => '192.0.2.1']);

            $this->runMaintenance();

            $metadata = $this->client->getEntityRecord($entityUuid)->getMetadata();
            $this->assertLessThanOrEqual(time(), $metadata['dns_resolved']);
        }

        public function testOnlyDomainEntitiesAreResolved(): void
        {
            $host = $this->uniqueHost('dns');
            $entities = [
                'user entity' => $this->createEntity($host, 'someone'),
                'IPv4 entity' => $this->createEntity('192.0.2.' . random_int(1, 254)),
                'IPv6 entity' => $this->createEntity('2001:db8::' . dechex(random_int(1, 65535))),
                'telephone entity' => $this->createEntity('e164.arpa', $this->uniqueTelephoneNumber()),
            ];

            $this->runMaintenance();

            foreach($entities as $name => $entityUuid)
            {
                $metadata = $this->client->getEntityRecord($entityUuid)->getMetadata() ?? [];
                $this->assertArrayNotHasKey('dns_resolved', $metadata, $name);
            }
        }

        public function testDetailsAreHiddenFromAnonymousRequests(): void
        {
            $entityUuid = $this->createEntity($this->uniqueHost('dns'));
            $this->runMaintenance();

            // Entity metadata is not public by default
            [$code, $body] = $this->jsonRequest('GET', '/entities/' . $entityUuid);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertNull($body['metadata']);
        }
    }
