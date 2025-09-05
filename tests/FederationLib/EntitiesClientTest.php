<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class EntitiesClientTest extends TestCase
    {
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];

        protected function setUp(): void
        {
            // Note, authentication is not required for these tests.
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch (Exception)
                {
                    // Ignore exceptions during cleanup
                }
            }

            foreach ($this->createdEntities as $entityId)
            {
                try
                {
                    $this->client->deleteEntity($entityId);
                }
                catch (Exception)
                {
                    // Ignore exceptions during cleanup
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
        }

        public function testPushEntity(): void
        {
            // Push a user entity
            $userEntityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertNotNull($userEntityUuid);
            $this->assertNotEmpty($userEntityUuid);

            // Query the entity back by their UUID
            $userEntityRecordUuid = $this->client->getEntityRecord($userEntityUuid);
            $this->createdEntities[] = $userEntityUuid;
            $this->assertEquals($userEntityUuid, $userEntityRecordUuid->getUuid());
            $this->assertEquals('john123', $userEntityRecordUuid->getId());
            $this->assertEquals('example.com', $userEntityRecordUuid->getHost());

            // Query the entity back by their hash
            $userEntityRecordHash = $this->client->getEntityRecord(Utilities::hashEntity('example.com', 'john123'));
            $this->assertEquals($userEntityUuid, $userEntityRecordHash->getUuid());
            $this->assertEquals('john123', $userEntityRecordHash->getId());
            $this->assertEquals('example.com', $userEntityRecordHash->getHost());

            // Push a global entity
            $globalEntityUuid = $this->client->pushEntity('example.com');
            $this->createdEntities[] = $globalEntityUuid;
            $this->assertNotNull($globalEntityUuid);
            $this->assertNotEmpty($globalEntityUuid);

            // Query the global entity back by their UUID
            $globalEntityRecordUuid = $this->client->getEntityRecord($globalEntityUuid);
            $this->createdEntities[] = $globalEntityUuid;
            $this->assertEquals($globalEntityUuid, $globalEntityRecordUuid->getUuid());
            $this->assertEquals('example.com', $globalEntityRecordUuid->getHost());
            $this->assertNotNull($globalEntityRecordUuid->getHost());

            // Query the global entity back by their hash
            $globalEntityRecordHash = $this->client->getEntityRecord(Utilities::hashEntity('example.com'));
            $this->assertEquals($globalEntityUuid, $globalEntityRecordHash->getUuid());
            $this->assertEquals('example.com', $globalEntityRecordHash->getHost());
            $this->assertNotNull($globalEntityRecordHash->getHost());

            // Ensure that pushing the same entity again returns the same UUID
            $duplicateUserEntityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->createdEntities[] = $duplicateUserEntityUuid;
            $this->assertEquals($userEntityUuid, $duplicateUserEntityUuid);
            $duplicateGlobalEntityUuid = $this->client->pushEntity('example.com');
            $this->assertEquals($globalEntityUuid, $duplicateGlobalEntityUuid);

            // Push a IP entity
            $ipAddressEntityUuid = $this->client->pushEntity('127.0.0.1');
            $this->createdEntities[] = $ipAddressEntityUuid;
            $this->assertNotEmpty($ipAddressEntityUuid);
            $this->assertNotNull($ipAddressEntityUuid);

            // Fetch the IP Address entity record
            $ipAddressEntityRecord = $this->client->getEntityRecord($ipAddressEntityUuid);
            $this->assertNotNull($ipAddressEntityRecord);
            $this->assertEquals($ipAddressEntityUuid, $ipAddressEntityRecord->getUuid());
            $this->assertEquals('127.0.0.1', $ipAddressEntityRecord->getHost());
            $this->assertNull($ipAddressEntityRecord->getId());
        }

        public function testPushInvalidIpAddressEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->pushEntity("999.999.999.999 2");
        }

        public function testPushInvalidDomainEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->pushEntity("invalid_domain@");
        }

        public function testPushEntityMissingHost(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('');
        }

        public function testDeleteEntity(): void
        {
            // Push a user entity
            $entityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);
            $this->createdEntities[] = $entityUuid;

            // Ensure the entity exists
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());

            // Delete the entity
            $this->client->deleteEntity($entityUuid);

            // Ensure the entity no longer exists
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getEntityRecord($entityUuid);

            // Remove from cleanup tracking since it's already deleted
            array_pop($this->createdEntities);
        }

        public function testDeleteNonExistentEntity(): void
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteEntity(Uuid::v4()->toRfc4122());
        }

        public function testListEntities(): void
        {
            // Push multiple entities
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity('example.com', 'user' . $i);
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            // List entities page by page and verify
            $fetchedUuids = [];
            $page = 1;
            do
            {
                $entitiesPage = $this->client->listEntities($page, 2);
                foreach ($entitiesPage as $entity)
                {
                    $fetchedUuids[] = $entity->getUuid();
                }
                $page++;
            } while (count($entitiesPage) > 0);

            // Ensure all pushed entities are fetched
            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $fetchedUuids);
            }
        }

        public function testListEntitiesInvalidPage(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(page: -10000);
        }

        public function testListEntitiesInvalidLimit(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->listEntities(limit: -1);
        }

        public function testPushEmptyEntity(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('', '');
        }

        public function testPushEmptyEntityHost(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('', 'someid');
        }

        public function testPushEmptyEntityId(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->pushEntity('example.com', '');
        }

        public function testGetEntityAsAnonymousClient(): void
        {
            // Push a user entity
            $entityUuid = $this->client->pushEntity('example.com', 'john123');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);
            $this->createdEntities[] = $entityUuid;

            if(!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $entityRecord = $anonymousClient->getEntityRecord($entityUuid);
            $this->assertEquals($entityUuid, $entityRecord->getUuid());
            $this->assertEquals('john123', $entityRecord->getId());
            $this->assertEquals('example.com', $entityRecord->getHost());
        }

        public function testPushEntityAsAnonymousClient(): void
        {
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $anonymousClient->pushEntity('example.com', 'john123');
        }

        public function testListEntitiesAsAnonymousClient(): void
        {
            if(!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Skipping because server is configured to keep entities private from anonymous users');
            }

            // Push multiple entities as root operator
            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuid = $this->client->pushEntity('example.com', 'user' . $i);
                $this->createdEntities[] = $entityUuid;
                $entityUuids[] = $entityUuid;
            }

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $fetchedUuids = [];
            $page = 1;

            do
            {
                $entitiesPage = $anonymousClient->listEntities($page, 2);
                foreach ($entitiesPage as $entity)
                {
                    $fetchedUuids[] = $entity->getUuid();
                }
                $page++;
            } while (count($entitiesPage) > 0);

            // Ensure all pushed entities are fetched
            foreach ($entityUuids as $pushedUuid)
            {
                $this->assertContains($pushedUuid, $fetchedUuids);
            }
        }
    }