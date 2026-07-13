<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use PHPUnit\Framework\TestCase;

    class EntityQueryTest extends TestCase
    {
        private FederationClient $client;
        private array $createdEntities = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
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

            $this->createdEntities = [];
        }

        public function testQueryEntityByHash(): void
        {
            $host = 'query-test.com';
            $id = 'query_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $hash = Utilities::hashEntity($host, $id);
            $this->assertNotEmpty($hash);

            $entityRecord = $this->client->getEntityRecord($hash);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals($host, $entityRecord->getHost());
            $this->assertEquals($id, $entityRecord->getId());
        }

        public function testQueryEntityByHashGlobal(): void
        {
            $host = 'global-query-test.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $hash = Utilities::hashEntity($host);
            $this->assertNotEmpty($hash);

            $entityRecord = $this->client->getEntityRecord($hash);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals($host, $entityRecord->getHost());
            $this->assertNull($entityRecord->getId());
        }

        public function testQueryEntityByUuid(): void
        {
            $host = 'uuid-query-test.com';
            $id = 'uuid_query_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entityRecord);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals($host, $entityRecord->getHost());
            $this->assertEquals($id, $entityRecord->getId());
        }

        public function testQueryNonExistentEntity(): void
        {
            $fakeUuid = 'bc1d8716-df05-4551-935a-007192550f17';

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getEntityRecord($fakeUuid);
        }

        public function testQueryWithInvalidUuid(): void
        {
            $this->expectException(RequestException::class);
            $this->client->getEntityRecord('invalid-uuid-format');
        }

        public function testHashConsistencyForSameEntity(): void
        {
            $host = 'consistency-test.com';
            $id = 'consistency_user';

            $hash1 = Utilities::hashEntity($host, $id);
            $hash2 = Utilities::hashEntity($host, $id);
            $hash3 = Utilities::hashEntity($host, $id);

            $this->assertEquals($hash1, $hash2);
            $this->assertEquals($hash1, $hash3);
        }

        public function testHashUniquenessForDifferentEntities(): void
        {
            $hash1 = Utilities::hashEntity('test1.com', 'user1');
            $hash2 = Utilities::hashEntity('test1.com', 'user2');
            $hash3 = Utilities::hashEntity('test2.com', 'user1');
            $hash4 = Utilities::hashEntity('test1.com');

            $this->assertNotEquals($hash1, $hash2);
            $this->assertNotEquals($hash1, $hash3);
            $this->assertNotEquals($hash1, $hash4);
            $this->assertNotEquals($hash2, $hash3);
            $this->assertNotEquals($hash2, $hash4);
            $this->assertNotEquals($hash3, $hash4);
        }

        public function testHashFormatAndLength(): void
        {
            $hash = Utilities::hashEntity('format-test.com', 'format_user');
            $this->assertNotEmpty($hash);
            $this->assertIsString($hash);
            $this->assertGreaterThan(10, strlen($hash));
        }

        public function testQueryResultStructure(): void
        {
            $host = 'structure-test.com';
            $id = 'structure_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);

            $this->assertIsString($entityRecord->getUuid());
            $this->assertIsString($entityRecord->getHost());
            $this->assertIsString($entityRecord->getId());
            $this->assertIsInt($entityRecord->getCreated());

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $entityRecord->getUuid()
            );

            $now = time();
            $this->assertLessThanOrEqual($now, $entityRecord->getCreated());
            $this->assertGreaterThan($now - 3600, $entityRecord->getCreated());
        }

        public function testQueryGlobalEntityStructure(): void
        {
            $host = 'global-structure-test.com';
            $entityUuid = $this->client->pushEntity($host);
            $this->createdEntities[] = $entityUuid;

            $entityRecord = $this->client->getEntityRecord($entityUuid);

            $this->assertIsString($entityRecord->getUuid());
            $this->assertIsString($entityRecord->getHost());
            $this->assertNull($entityRecord->getId());
            $this->assertIsInt($entityRecord->getCreated());
        }

        public function testQueryEntitiesWithSpecialCharacters(): void
        {
            $testCases = [
                ['host' => 'special-chars.com', 'id' => 'user_with_underscore'],
                ['host' => 'test-domain.org', 'id' => 'user-with-hyphens'],
                ['host' => 'numbers123.net', 'id' => 'user123'],
                ['host' => 'subdomain.example.co.uk', 'id' => 'user.with.dots'],
            ];

            foreach ($testCases as $testCase)
            {
                $entityUuid = $this->client->pushEntity($testCase['host'], $testCase['id']);
                $this->createdEntities[] = $entityUuid;

                $entityByUuid = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($testCase['host'], $entityByUuid->getHost());
                $this->assertEquals($testCase['id'], $entityByUuid->getId());

                $hash = Utilities::hashEntity($testCase['host'], $testCase['id']);
                $entityByHash = $this->client->getEntityRecord($hash);
                $this->assertEquals($entityUuid, $entityByHash->getUuid());
                $this->assertEquals($testCase['host'], $entityByHash->getHost());
                $this->assertEquals($testCase['id'], $entityByHash->getId());
            }
        }

        public function testQueryIpAddressEntities(): void
        {
            $ipAddresses = [
                '192.168.1.1',
                '10.0.0.1',
                '172.16.0.1',
                '127.0.0.1',
                '8.8.8.8'
            ];

            foreach ($ipAddresses as $ip)
            {
                $entityUuid = $this->client->pushEntity($ip);
                $this->createdEntities[] = $entityUuid;

                $entityByUuid = $this->client->getEntityRecord($entityUuid);
                $this->assertEquals($ip, $entityByUuid->getHost());
                $this->assertNull($entityByUuid->getId());

                $hash = Utilities::hashEntity($ip);
                $entityByHash = $this->client->getEntityRecord($hash);
                $this->assertEquals($entityUuid, $entityByHash->getUuid());
                $this->assertEquals($ip, $entityByHash->getHost());
                $this->assertNull($entityByHash->getId());
            }
        }

        public function testQueryConsistencyAfterMultipleCreations(): void
        {
            $host = 'consistency-multi.com';
            $id = 'consistency_user';

            $uuid1 = $this->client->pushEntity($host, $id);
            $uuid2 = $this->client->pushEntity($host, $id);
            $uuid3 = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $uuid1;

            $this->assertEquals($uuid1, $uuid2);
            $this->assertEquals($uuid1, $uuid3);

            $hash = Utilities::hashEntity($host, $id);

            for ($i = 0; $i < 5; $i++)
            {
                $resultByUuid = $this->client->getEntityRecord($uuid1);
                $resultByHash = $this->client->getEntityRecord($hash);

                $this->assertEquals($uuid1, $resultByUuid->getUuid());
                $this->assertEquals($uuid1, $resultByHash->getUuid());
                $this->assertEquals($host, $resultByUuid->getHost());
                $this->assertEquals($host, $resultByHash->getHost());
                $this->assertEquals($id, $resultByUuid->getId());
                $this->assertEquals($id, $resultByHash->getId());
            }
        }

        public function testQueryEntityAsAnonymousClient(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            $host = 'anonymous-query.com';
            $id = 'anonymous_user';
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));

            $entityByUuid = $anonymousClient->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityByUuid->getUuid());
            $this->assertEquals($host, $entityByUuid->getHost());
            $this->assertEquals($id, $entityByUuid->getId());

            $hash = Utilities::hashEntity($host, $id);
            $entityByHash = $anonymousClient->getEntityRecord($hash);
            $this->assertEquals($entityUuid, $entityByHash->getUuid());
            $this->assertEquals($host, $entityByHash->getHost());
            $this->assertEquals($id, $entityByHash->getId());
        }
    }
