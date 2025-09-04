<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class OperatorsClientTest extends TestCase
    {
        private FederationClient $client;
        private array $createdOperators = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
            foreach($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch(Exception)
                {
                    // Ignore
                }
            }
        }

        public function testCreateOperatorNoPermissions(): void
        {
            // Create the operator
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());
            $this->assertNotNull($operatorRecord->getApiKey());
            $this->assertNotEmpty($operatorRecord->getApiKey());
        }

        public function testCreateOperatorWithManageBlacklistPermission(): void
        {
            // Create the operator
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            $this->client->setManageBlacklistPermission($operatorUuid, true);

            // Fetch the operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertTrue($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());
            $this->assertNotNull($operatorRecord->getApiKey());
            $this->assertNotEmpty($operatorRecord->getApiKey());
        }

        public function testCreateOperatorWithManageOperatorsPermission(): void
        {
            // Create the operator
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            $this->client->setManageOperatorsPermission($operatorUuid, true);

            // Fetch the operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertTrue($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());
            $this->assertNotNull($operatorRecord->getApiKey());
            $this->assertNotEmpty($operatorRecord->getApiKey());
        }

        public function testCreateOperatorAsClient(): void
        {
            // Create the operator
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            $this->client->setClientPermission($operatorUuid, true);

            // Fetch the operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertTrue($operatorRecord->isClient());
            $this->assertNotNull($operatorRecord->getApiKey());
            $this->assertNotEmpty($operatorRecord->getApiKey());
        }

        public function testCreateOperatorWithAllPermissions(): void
        {
            // Create the operator
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            $this->client->setManageBlacklistPermission($operatorUuid, true);
            $this->client->setManageOperatorsPermission($operatorUuid, true);
            $this->client->setClientPermission($operatorUuid, true);

            // Fetch the operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertTrue($operatorRecord->canManageBlacklist());
            $this->assertTrue($operatorRecord->canManageOperators());
            $this->assertTrue($operatorRecord->isClient());
            $this->assertNotNull($operatorRecord->getApiKey());
            $this->assertNotEmpty($operatorRecord->getApiKey());
        }

        public function testOperatorBlacklistPermissionAuthorized(): void
        {
            // First create an operator with the proper permissions
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->client->setManageBlacklistPermission($operatorUuid, true);
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertTrue($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());

            // Using the root operator, push an entity
            $entityUuid = $this->client->pushEntity(uniqid('john_doe_'), 'example.com');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Now create a client using that operator's API key
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());
            $this->assertNotNull($operatorClient);
            $this->assertNotEmpty($operatorClient);

            // Now try to submit evidence for that entity
            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'This is some test evidence', 'Test note', 'test_tag', false);
            $this->assertNotNull($evidenceUuid);
            $this->assertNotEmpty($evidenceUuid);

            // Fetch the evidence record
            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($evidenceUuid, $evidenceRecord->getUuid());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals('This is some test evidence', $evidenceRecord->getTextContent());
            $this->assertEquals('Test note', $evidenceRecord->getNote());
            $this->assertEquals('test_tag', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());
        }

        public function testOperatorBlacklistPermissionUnauthorized(): void
        {
            // First create an operator without the proper permissions
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());

            // Using the root operator, push an entity
            $entityUuid = $this->client->pushEntity(uniqid('john_doe_'), 'example.com');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);

            // Now create a client using that operator's API key
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());
            $this->assertNotNull($operatorClient);
            $this->assertNotEmpty($operatorClient);

            // Now try to submit evidence for that entity, which should fail
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->submitEvidence($entityUuid, 'This is some test evidence', 'Test note', 'test_tag', false);
        }


        public function testOperatorManageOperatorsPermissionAuthorized(): void
        {
            // First create an operator with the proper permissions
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->client->setManageOperatorsPermission($operatorUuid, true);
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertTrue($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());

            // Now create a client using that operator's API key
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());
            $this->assertNotNull($operatorClient);
            $this->assertNotEmpty($operatorClient);

            // Now try to create another operator using that operator's API key
            $managedOperatorName = uniqid('managed operator');
            $managedOperatorUuid = $operatorClient->createOperator($managedOperatorName);
            $this->createdOperators[] = $managedOperatorUuid;
            $this->assertNotNull($managedOperatorUuid);
            $this->assertNotEmpty($managedOperatorUuid);

            // Fetch the managed operator record
            $managedOperatorRecord = $this->client->getOperator($managedOperatorUuid);
            $this->assertNotNull($managedOperatorRecord);
            $this->assertEquals($managedOperatorName, $managedOperatorRecord->getName());
        }

        public function testOperatorManageOperatorPermissionUnauthorized(): void
        {
            // First create an operator without the proper permissions
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());

            // Now create a client using that operator's API key
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());
            $this->assertNotNull($operatorClient);
            $this->assertNotEmpty($operatorClient);

            // Now try to create another operator using that operator's API key, which should fail
            $managedOperatorName = uniqid('managed operator');
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->createOperator($managedOperatorName);
        }

        public function testOperatorClientPermissionAuthorized(): void
        {
            // First create an operator with the proper permissions
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermission($operatorUuid, true);
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertTrue($operatorRecord->isClient());

            // Now create a client using that operator's API key
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());
            $this->assertNotNull($operatorClient);
            $this->assertNotEmpty($operatorClient);

            // Now try to push an entity using that operator's API key
            $entityUuid = $operatorClient->pushEntity(uniqid('john_doe_'), 'example.com');
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);
        }

        public function testOperatorClientPermissionUnauthorized(): void
        {
            // First create an operator without the proper permissions
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->canManageBlacklist());
            $this->assertFalse($operatorRecord->canManageOperators());
            $this->assertFalse($operatorRecord->isClient());

            // Now create a client using that operator's API key
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());
            $this->assertNotNull($operatorClient);
            $this->assertNotEmpty($operatorClient);

            // Now try to push an entity using that operator's API key, which should fail
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->pushEntity(uniqid('john_doe_'), 'example.com');
        }

        public function testDeleteOperator(): void
        {
            // First create an operator
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Fetch the existing operator record
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertEquals($name, $operatorRecord->getName());

            // Now delete the operator
            $this->client->deleteOperator($operatorUuid);

            // Now try to fetch the operator record, which should fail
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getOperator($operatorUuid);
        }

        public function testCreateInvalidOperatorName(): void
        {
            // Try to create an operator with an invalid name (too long)
            $name = str_repeat('a', 256);
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->createOperator($name);
        }

        public function testDeleteNonExistentOperator(): void
        {
            // Try to delete a non-existent operator
            $nonExistentOperatorUuid = Uuid::v4()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteOperator($nonExistentOperatorUuid);
        }

        public function testGetNonExistentOperator(): void
        {
            // Try to get a non-existent operator
            $nonExistentOperatorUuid = Uuid::v4()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getOperator($nonExistentOperatorUuid);
        }

        public function testDisabledOperator(): void
        {
            // First create an operator and disable it
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Disable the operator
            $this->client->disableOperator($operatorUuid);

            // Get the existing operator
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertTrue($operatorRecord->isDisabled());

            // Create an operator client
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());

            // Attempt to preform a normal method that usually requires authentication
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->getSelf();
        }

        public function testEnabledDisabledOperator(): void
        {
            // First create an operator and disable it
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;
            $this->assertNotNull($operatorUuid);
            $this->assertNotEmpty($operatorUuid);

            // Disable the operator
            $this->client->disableOperator($operatorUuid);

            // Get the existing operator
            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertTrue($operatorRecord->isDisabled());

            // Create an operator client
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getApiKey());

            // Attempt to preform a normal method that usually requires authentication
            try
            {
                $operatorClient->getSelf();
                $this->fail('An exception was supposed to be thrown here');
            }
            catch(Exception $e)
            {
                $this->assertEquals(HttpResponseCode::FORBIDDEN->value, $e->getCode());
            }

            // Re-enable the operator from the root operator
            $this->client->enableOperator($operatorUuid);

            // Attempt to preform the same method again
            $selfOperator = $operatorClient->getSelf();
            $this->assertNotEmpty($selfOperator);
            $this->assertFalse($selfOperator->isDisabled());
        }

        public function testListOperators(): void
        {
            // Create 10 Operators
            $createdOperators = [];
            for($i = 0; $i < 10; $i++)
            {
                $name = uniqid('test operator');
                $operatorUuid = $this->client->createOperator($name);
                $this->createdOperators[] = $operatorUuid;
                $createdOperators []= $operatorUuid;
                $this->assertNotNull($operatorUuid);
                $this->assertNotEmpty($operatorUuid);
            }

            // Now list the operators
            $operators = $this->client->listOperators();
            $this->assertNotNull($operators);
            $this->assertGreaterThanOrEqual(10, count($operators));
            foreach($operators as $operator)
            {
                $this->assertNotNull($operator->getUuid());
                $this->assertNotEmpty($operator->getUuid());
                $this->assertNotNull($operator->getName());
                $this->assertNotEmpty($operator->getName());
            }

            $found = false;
            $currentPage = 1;
            while(true)
            {
                $currentPageResults = $this->client->listOperators(page: $currentPage);
                if(count($currentPageResults) === 0)
                {
                    break;
                }

                foreach($currentPageResults as $operator)
                {
                    foreach($createdOperators as $operatorUuid)
                    {
                        if($operatorUuid === $operator->getUuid())
                        {
                            $found = true;
                            break;
                        }

                        if($found)
                        {
                            break;
                        }
                    }
                }

                $currentPage += 1;
            }

            $this->assertTrue($found, 'No created operators was found in the database');
        }
    }