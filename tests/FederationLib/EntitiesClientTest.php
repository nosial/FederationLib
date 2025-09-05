<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Classes\Utilities;
    use PHPUnit\Framework\TestCase;

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
            $this->createdEntities[] = $userEntityUuid; // Track for cleanup
            $this->assertNotNull($userEntityUuid);
            $this->assertNotEmpty($userEntityUuid);

            // Query the entity back by their UUID
            $userEntityRecordUuid = $this->client->getEntityRecord($userEntityUuid);
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
            $this->createdEntities[] = $globalEntityUuid; // Track for cleanup
            $this->assertNotNull($globalEntityUuid);
            $this->assertNotEmpty($globalEntityUuid);

            // Query the global entity back by their UUID
            $globalEntityRecordUuid = $this->client->getEntityRecord($globalEntityUuid);
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
            $this->assertEquals($userEntityUuid, $duplicateUserEntityUuid);
            $duplicateGlobalEntityUuid = $this->client->pushEntity('example.com');
            $this->assertEquals($globalEntityUuid, $duplicateGlobalEntityUuid);
        }
    }