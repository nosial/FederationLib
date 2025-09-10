<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class OperatorsClientTest extends TestCase
    {
        private FederationClient $client;
        private Logger $logger;
        private array $createdOperators = [];

        protected function setUp(): void
        {
            $this->logger = new Logger('tests');
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
                catch(RequestException $e)
                {
                    $this->logger->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage(), $e);
                }
                catch(Exception $e)
                {
                    $this->logger->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage(), $e);
                }
            }
        }

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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
            $entityUuid = $this->client->pushEntity('example.com', uniqid('john_doe_'));
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

        /**
         * @throws RequestException
         */
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
            $entityUuid = $this->client->pushEntity('example.com', uniqid('john_doe_'));
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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
            $entityUuid = $operatorClient->pushEntity('example.com', uniqid('john_doe_'));
            $this->assertNotNull($entityUuid);
            $this->assertNotEmpty($entityUuid);
        }

        /**
         * @throws RequestException
         */
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
            $operatorClient->pushEntity('example.com', uniqid('john_doe_'));
        }

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        /**
         * @throws RequestException
         */
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

        // DURABILITY TESTS

        /**
         * @throws RequestException
         */
        public function testOperatorLifecycleIntegrity(): void
        {
            // Test complete operator lifecycle: create, modify permissions, disable/enable, delete
            $operatorName = uniqid('lifecycle_operator_');
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            // Verify initial state
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($operatorName, $operator->getName());
            $this->assertFalse($operator->canManageBlacklist());
            $this->assertFalse($operator->canManageOperators());
            $this->assertFalse($operator->isClient());
            $this->assertFalse($operator->isDisabled());
            $this->assertNotNull($operator->getApiKey());

            // Set all permissions
            $this->client->setManageBlacklistPermission($operatorUuid, true);
            $this->client->setManageOperatorsPermission($operatorUuid, true);
            $this->client->setClientPermission($operatorUuid, true);

            // Verify permissions were set
            $updatedOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($updatedOperator->canManageBlacklist());
            $this->assertTrue($updatedOperator->canManageOperators());
            $this->assertTrue($updatedOperator->isClient());

            // Disable operator
            $this->client->disableOperator($operatorUuid);
            $disabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($disabledOperator->isDisabled());

            // Enable operator
            $this->client->enableOperator($operatorUuid);
            $enabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($enabledOperator->isDisabled());

            // Verify permissions persist through disable/enable cycle
            $this->assertTrue($enabledOperator->canManageBlacklist());
            $this->assertTrue($enabledOperator->canManageOperators());
            $this->assertTrue($enabledOperator->isClient());

            // Refresh API key
            $originalApiKey = $enabledOperator->getApiKey();
            $newApiKey = $this->client->refreshOperatorApiKey($operatorUuid);
            $this->assertNotEquals($originalApiKey, $newApiKey);

            $refreshedOperator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newApiKey, $refreshedOperator->getApiKey());

            // Delete operator
            $this->client->deleteOperator($operatorUuid);

            // Verify deletion
            try {
                $this->client->getOperator($operatorUuid);
                $this->fail("Expected RequestException for deleted operator");
            } catch (RequestException $e) {
                $this->assertEquals(404, $e->getCode());
            }

            // Remove from cleanup array since already deleted
            array_splice($this->createdOperators, array_search($operatorUuid, $this->createdOperators), 1);
        }

        /**
         * @throws RequestException
         */
        public function testOperatorPermissionConsistency(): void
        {
            // Test that permission changes are consistent and persistent
            $operatorUuid = $this->client->createOperator('permission_test_operator');
            $this->createdOperators[] = $operatorUuid;

            // Test each permission individually
            $permissions = [
                'ManageBlacklist' => [$this->client, 'setManageBlacklistPermission'],
                'ManageOperators' => [$this->client, 'setManageOperatorsPermission'],
                'Client' => [$this->client, 'setClientPermission']
            ];

            foreach ($permissions as $permissionName => $setterCallback) {
                // Set permission to true
                $setterCallback($operatorUuid, true);
                $operator = $this->client->getOperator($operatorUuid);
                
                switch ($permissionName) {
                    case 'ManageBlacklist':
                        $this->assertTrue($operator->canManageBlacklist());
                        break;
                    case 'ManageOperators':
                        $this->assertTrue($operator->canManageOperators());
                        break;
                    case 'Client':
                        $this->assertTrue($operator->isClient());
                        break;
                }

                // Set permission to false
                $setterCallback($operatorUuid, false);
                $operator = $this->client->getOperator($operatorUuid);
                
                switch ($permissionName) {
                    case 'ManageBlacklist':
                        $this->assertFalse($operator->canManageBlacklist());
                        break;
                    case 'ManageOperators':
                        $this->assertFalse($operator->canManageOperators());
                        break;
                    case 'Client':
                        $this->assertFalse($operator->isClient());
                        break;
                }
            }

            // Test multiple rapid permission changes
            for ($i = 0; $i < 5; $i++) {
                $this->client->setManageBlacklistPermission($operatorUuid, $i % 2 === 0);
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertEquals($i % 2 === 0, $operator->canManageBlacklist());
            }
        }

        /**
         * @throws RequestException
         */
        public function testHighVolumeOperatorOperations(): void
        {
            // Test creating and managing multiple operators
            $batchSize = 10;
            $operatorUuids = [];

            // Create operators in batch
            for ($i = 0; $i < $batchSize; $i++) {
                $operatorName = "batch_operator_$i";
                $operatorUuid = $this->client->createOperator($operatorName);
                $this->createdOperators[] = $operatorUuid;
                $operatorUuids[] = $operatorUuid;

                // Set varied permissions
                $this->client->setManageBlacklistPermission($operatorUuid, $i % 2 === 0);
                $this->client->setManageOperatorsPermission($operatorUuid, $i % 3 === 0);
                $this->client->setClientPermission($operatorUuid, $i % 4 === 0);
            }

            // Verify all operators exist with correct permissions
            foreach ($operatorUuids as $index => $operatorUuid) {
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertEquals("batch_operator_$index", $operator->getName());
                $this->assertEquals($index % 2 === 0, $operator->canManageBlacklist());
                $this->assertEquals($index % 3 === 0, $operator->canManageOperators());
                $this->assertEquals($index % 4 === 0, $operator->isClient());
            }

            // Test pagination through operators - use large page size to capture recent records
            $allOperators = $this->client->listOperators(1, 100); // Get first 100 operators
            
            // Since operators are ordered by creation, our newly created operators should be findable
            $this->assertGreaterThanOrEqual($batchSize, count($allOperators));

            // Verify our operators are in the results
            $foundUuids = array_map(fn($operator) => $operator->getUuid(), $allOperators);
            foreach ($operatorUuids as $uuid) {
                $this->assertContains($uuid, $foundUuids);
            }

            // Test batch disable/enable
            foreach ($operatorUuids as $operatorUuid) {
                $this->client->disableOperator($operatorUuid);
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertTrue($operator->isDisabled());
            }

            foreach ($operatorUuids as $operatorUuid) {
                $this->client->enableOperator($operatorUuid);
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertFalse($operator->isDisabled());
            }
        }

        /**
         * @throws RequestException
         */
        public function testOperatorApiKeyIntegrity(): void
        {
            // Test API key generation and refresh functionality
            $operatorUuid = $this->client->createOperator('api_key_test_operator');
            $this->createdOperators[] = $operatorUuid;

            // Get initial API key
            $operator = $this->client->getOperator($operatorUuid);
            $originalApiKey = $operator->getApiKey();
            $this->assertNotNull($originalApiKey);
            $this->assertNotEmpty($originalApiKey);

            // Test that the API key works
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $originalApiKey);
            $selfOperator = $operatorClient->getSelf();
            $this->assertEquals($operatorUuid, $selfOperator->getUuid());

            // Refresh API key multiple times
            $previousKey = $originalApiKey;
            for ($i = 0; $i < 3; $i++) {
                $newApiKey = $this->client->refreshOperatorApiKey($operatorUuid);
                $this->assertNotEquals($previousKey, $newApiKey);
                $this->assertNotNull($newApiKey);
                $this->assertNotEmpty($newApiKey);

                // Verify new key works
                $newOperatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newApiKey);
                $newSelfOperator = $newOperatorClient->getSelf();
                $this->assertEquals($operatorUuid, $newSelfOperator->getUuid());

                // Verify old key no longer works
                try {
                    $oldOperatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $previousKey);
                    $oldOperatorClient->getSelf();
                    $this->fail("Expected RequestException for old API key");
                } catch (RequestException $e) {
                    $this->assertEquals(401, $e->getCode());
                }

                $previousKey = $newApiKey;
            }

            // Verify operator record has the latest key
            $finalOperator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($previousKey, $finalOperator->getApiKey());
        }

        /**
         * @throws RequestException
         */
        public function testOperatorStateTransitionIntegrity(): void
        {
            // Test various operator state transitions
            $operatorUuid = $this->client->createOperator('state_test_operator');
            $this->createdOperators[] = $operatorUuid;

            // Test state transitions: enabled -> disabled -> enabled
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->isDisabled());

            // Disable while having permissions
            $this->client->setManageBlacklistPermission($operatorUuid, true);
            $this->client->disableOperator($operatorUuid);
            
            $disabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($disabledOperator->isDisabled());
            $this->assertTrue($disabledOperator->canManageBlacklist()); // Permissions should persist

            // Enable and verify permissions are intact
            $this->client->enableOperator($operatorUuid);
            $enabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($enabledOperator->isDisabled());
            $this->assertTrue($enabledOperator->canManageBlacklist());

            // Test permission changes while disabled
            $this->client->disableOperator($operatorUuid);
            $this->client->setManageOperatorsPermission($operatorUuid, true);
            
            $modifiedDisabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($modifiedDisabledOperator->isDisabled());
            $this->assertTrue($modifiedDisabledOperator->canManageOperators());

            // Enable and verify all changes persist
            $this->client->enableOperator($operatorUuid);
            $finalOperator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($finalOperator->isDisabled());
            $this->assertTrue($finalOperator->canManageBlacklist());
            $this->assertTrue($finalOperator->canManageOperators());
        }

        /**
         * @throws RequestException
         */
        public function testOperatorCascadingOperations(): void
        {
            // Test operations involving operator hierarchies and permissions
            $parentOperatorUuid = $this->client->createOperator('parent_operator');
            $this->createdOperators[] = $parentOperatorUuid;
            
            // Give parent operator permission to manage other operators
            $this->client->setManageOperatorsPermission($parentOperatorUuid, true);
            $parentOperator = $this->client->getOperator($parentOperatorUuid);
            
            // Create client using parent operator
            $parentClient = new FederationClient(getenv('SERVER_ENDPOINT'), $parentOperator->getApiKey());
            
            // Parent creates child operator
            $childOperatorUuid = $parentClient->createOperator('child_operator');
            $this->createdOperators[] = $childOperatorUuid;
            
            // Verify child operator exists
            $childOperator = $this->client->getOperator($childOperatorUuid);
            $this->assertEquals('child_operator', $childOperator->getName());
            
            // Parent modifies child permissions
            $parentClient->setClientPermission($childOperatorUuid, true);
            $modifiedChild = $this->client->getOperator($childOperatorUuid);
            $this->assertTrue($modifiedChild->isClient());
            
            // Parent disables child
            $parentClient->disableOperator($childOperatorUuid);
            $disabledChild = $this->client->getOperator($childOperatorUuid);
            $this->assertTrue($disabledChild->isDisabled());
            
            // Parent enables child
            $parentClient->enableOperator($childOperatorUuid);
            $enabledChild = $this->client->getOperator($childOperatorUuid);
            $this->assertFalse($enabledChild->isDisabled());
            
            // Parent deletes child
            $parentClient->deleteOperator($childOperatorUuid);
            
            // Verify child is deleted
            try {
                $this->client->getOperator($childOperatorUuid);
                $this->fail("Expected RequestException for deleted child operator");
            } catch (RequestException $e) {
                $this->assertEquals(404, $e->getCode());
            }
            
            // Remove from cleanup array since already deleted
            array_splice($this->createdOperators, array_search($childOperatorUuid, $this->createdOperators), 1);
        }
    }