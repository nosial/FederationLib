<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Operator;
    use PHPUnit\Framework\TestCase;

    class OperatorsTest extends TestCase
    {
        private FederationClient $client;

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        public function testCreateOperator()
        {
            $uuid = $this->client->createOperator('test create-operator');
            $this->assertNotEmpty($uuid);
        }

        public function testGetOperators()
        {
            // Fetch existing operators
            $operators = $this->client->listOperators();
            $this->assertIsArray($operators);

            // Create 3 new operators
            $uuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuids[] = $this->client->createOperator("test get-operators $i");
            }

            $operators = $this->client->listOperators();
            $this->assertGreaterThanOrEqual(3, count($operators));
        }

        public function testDeleteOperator()
        {
            // Create an operator to delete
            $uuid = $this->client->createOperator('test delete-operator');
            $this->assertNotEmpty($uuid);

            // Delete the operator
            $this->client->deleteOperator($uuid);
        }

        public function testDisableOperator()
        {
            // Create an operator to disable
            $uuid = $this->client->createOperator('test disable-operator');
            $this->assertNotEmpty($uuid);

            // Disable the operator
            $this->client->disableOperator($uuid);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testEnableOperator()
        {
            // Create an operator to enable
            $uuid = $this->client->createOperator('test enable-operator');
            $this->assertNotEmpty($uuid);

            // First disable it
            $this->client->disableOperator($uuid);

            // Then enable it
            $this->client->enableOperator($uuid);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testGetOperator()
        {
            // Create an operator to retrieve
            $uuid = $this->client->createOperator('test get-operator');
            $this->assertNotEmpty($uuid);

            // Get the operator
            $operator = $this->client->getOperator($uuid);
            $this->assertInstanceOf(Operator::class, $operator);
            $this->assertEquals($uuid, $operator->getUuid());
            $this->assertEquals('test get-operator', $operator->getName());

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testGetSelf()
        {
            // Get the current operator (self)
            $operator = $this->client->getSelf();
            $this->assertInstanceOf(Operator::class, $operator);
            $this->assertNotEmpty($operator->getUuid());
            $this->assertNotEmpty($operator->getName());
        }

        public function testListOperatorAuditLogs()
        {
            // Create an operator to get audit logs for
            $uuid = $this->client->createOperator('test audit-logs');
            $this->assertNotEmpty($uuid);

            // Get audit logs for the operator
            $auditLogs = $this->client->listOperatorAuditLogs($uuid);
            $this->assertIsArray($auditLogs);

            // Test with pagination
            $auditLogsPage = $this->client->listOperatorAuditLogs($uuid, 1, 10);
            $this->assertIsArray($auditLogsPage);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testListOperatorEvidence()
        {
            // Create an operator to get evidence for
            $uuid = $this->client->createOperator('test evidence');
            $this->assertNotEmpty($uuid);

            // Get evidence records for the operator
            $evidence = $this->client->listOperatorEvidence($uuid);
            $this->assertIsArray($evidence);

            // Test with pagination
            $evidencePage = $this->client->listOperatorEvidence($uuid, 1, 10);
            $this->assertIsArray($evidencePage);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testListOperatorBlacklist()
        {
            // Create an operator to get blacklist for
            $uuid = $this->client->createOperator('test blacklist');
            $this->assertNotEmpty($uuid);

            // Get blacklist records for the operator
            $blacklist = $this->client->listOperatorBlacklist($uuid);
            $this->assertIsArray($blacklist);

            // Test with pagination
            $blacklistPage = $this->client->listOperatorBlacklist($uuid, 1, 10);
            $this->assertIsArray($blacklistPage);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testSetManageOperatorsPermission()
        {
            // Create an operator to set permissions for
            $uuid = $this->client->createOperator('test manage-operators-permission');
            $this->assertNotEmpty($uuid);

            // Enable the permission
            $this->client->setManageOperatorsPermission($uuid, true);

            // Disable the permission
            $this->client->setManageOperatorsPermission($uuid, false);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testSetClientPermission()
        {
            // Create an operator to set client permissions for
            $uuid = $this->client->createOperator('test client-permission');
            $this->assertNotEmpty($uuid);

            // Enable client permission
            $this->client->setClientPermission($uuid, true);

            // Disable client permission
            $this->client->setClientPermission($uuid, false);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testSetManageBlacklistPermission()
        {
            // Create an operator to set blacklist permissions for
            $uuid = $this->client->createOperator('test blacklist-permission');
            $this->assertNotEmpty($uuid);

            // Enable blacklist management permission
            $this->client->setManageBlacklistPermission($uuid, true);

            // Disable blacklist management permission
            $this->client->setManageBlacklistPermission($uuid, false);

            // Clean up
            $this->client->deleteOperator($uuid);
        }

        public function testListOperatorsWithPagination()
        {
            // Test pagination parameters
            $operatorsPage1 = $this->client->listOperators(1, 5);
            $this->assertIsArray($operatorsPage1);
            $this->assertLessThanOrEqual(5, count($operatorsPage1));

            $operatorsPage2 = $this->client->listOperators(2, 5);
            $this->assertIsArray($operatorsPage2);
            $this->assertLessThanOrEqual(5, count($operatorsPage2));
        }

        public function testCreateOperatorValidation()
        {
            // Test that empty operator name throws exception
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Operator name cannot be empty');
            $this->client->createOperator('');
        }

        public function testDeleteOperatorValidation()
        {
            // Test that empty UUID throws exception
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Operator UUID cannot be empty');
            $this->client->deleteOperator('');
        }

        public function testDisableOperatorValidation()
        {
            // Test that empty UUID throws exception
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Operator UUID cannot be empty');
            $this->client->disableOperator('');
        }

        public function testGetOperatorValidation()
        {
            // Test that empty UUID throws exception
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Operator UUID cannot be empty');
            $this->client->getOperator('');
        }

        public function testOperatorPermissions()
        {
            // Create a new operator for permission testing
            $operatorUuid = $this->client->createOperator('test-permissions-operator');
            $this->assertNotEmpty($operatorUuid);

            // Test setting and checking manage operators permission
            $this->client->setManageOperatorsPermission($operatorUuid, true);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->canManageOperators());

            $this->client->setManageOperatorsPermission($operatorUuid, false);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->canManageOperators());

            // Test setting and checking client permission
            $this->client->setClientPermission($operatorUuid, true);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->isClient());

            $this->client->setClientPermission($operatorUuid, false);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->isClient());

            // Test setting and checking blacklist management permission
            $this->client->setManageBlacklistPermission($operatorUuid, true);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->canManageBlacklist());

            $this->client->setManageBlacklistPermission($operatorUuid, false);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->canManageBlacklist());

            // Clean up
            $this->client->deleteOperator($operatorUuid);
        }

        public function testOperatorStateChanges()
        {
            // Create a new operator for state testing
            $operatorUuid = $this->client->createOperator('test-state-operator');
            $this->assertNotEmpty($operatorUuid);

            // Initially should be enabled
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->isDisabled());

            // Disable the operator
            $this->client->disableOperator($operatorUuid);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->isDisabled());

            // Re-enable the operator
            $this->client->enableOperator($operatorUuid);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->isDisabled());

            // Clean up
            $this->client->deleteOperator($operatorUuid);
        }

        public function testOperatorWithClientPermissions()
        {
            // Create an operator with client permissions
            $clientOperatorUuid = $this->client->createOperator('test-client-operator');
            $this->assertNotEmpty($clientOperatorUuid);

            // Grant client permissions
            $this->client->setClientPermission($clientOperatorUuid, true);

            // Verify the operator has client permissions
            $operator = $this->client->getOperator($clientOperatorUuid);
            $this->assertTrue($operator->isClient());
            $this->assertNotNull($operator->getApiKey());

            // Test that the operator can authenticate (if API key is available)
            if ($operator->getApiKey() !== null) {
                $clientWithNewOperator = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

                // Test basic operations with the new client
                $selfInfo = $clientWithNewOperator->getSelf();
                $this->assertEquals($clientOperatorUuid, $selfInfo->getUuid());
                $this->assertEquals('test-client-operator', $selfInfo->getName());
            }

            // Clean up
            $this->client->deleteOperator($clientOperatorUuid);
        }

        public function testOperatorWithManagementPermissions()
        {
            // Create an operator with management permissions
            $managerOperatorUuid = $this->client->createOperator('test-manager-operator');
            $this->assertNotEmpty($managerOperatorUuid);

            // Grant management permissions
            $this->client->setManageOperatorsPermission($managerOperatorUuid, true);
            $this->client->setClientPermission($managerOperatorUuid, true);

            // Verify the operator has management permissions
            $operator = $this->client->getOperator($managerOperatorUuid);
            $this->assertTrue($operator->canManageOperators());
            $this->assertTrue($operator->isClient());

            // Test that the operator can manage other operators (if API key is available)
            if ($operator->getApiKey() !== null) {
                $managerClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

                // Create a new operator using the manager client
                $testOperatorUuid = $managerClient->createOperator('test-managed-operator');
                $this->assertNotEmpty($testOperatorUuid);

                // Verify the created operator exists
                $createdOperator = $managerClient->getOperator($testOperatorUuid);
                $this->assertEquals('test-managed-operator', $createdOperator->getName());

                // Test permission management
                $managerClient->setClientPermission($testOperatorUuid, true);
                $updatedOperator = $managerClient->getOperator($testOperatorUuid);
                $this->assertTrue($updatedOperator->isClient());

                // Clean up the test operator
                $managerClient->deleteOperator($testOperatorUuid);
            }

            // Clean up
            $this->client->deleteOperator($managerOperatorUuid);
        }

        public function testOperatorWithoutPermissions()
        {
            // Create an operator without any special permissions
            $limitedOperatorUuid = $this->client->createOperator('test-limited-operator');
            $this->assertNotEmpty($limitedOperatorUuid);

            // Ensure it has no special permissions
            $this->client->setManageOperatorsPermission($limitedOperatorUuid, false);
            $this->client->setManageBlacklistPermission($limitedOperatorUuid, false);
            $this->client->setClientPermission($limitedOperatorUuid, true); // Still needs client access for testing

            $operator = $this->client->getOperator($limitedOperatorUuid);
            $this->assertFalse($operator->canManageOperators());
            $this->assertFalse($operator->canManageBlacklist());
            $this->assertTrue($operator->isClient());

            // Test that the operator cannot perform management operations (if API key is available)
            if ($operator->getApiKey() !== null) {
                $limitedClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getApiKey());

                // Should be able to get self info
                $selfInfo = $limitedClient->getSelf();
                $this->assertEquals($limitedOperatorUuid, $selfInfo->getUuid());

                // Shouldn't be able to list operators (expecting exception)
                $this->expectException(RequestException::class);
                $operators = $limitedClient->listOperators();
                $this->assertIsArray($operators);
            }

            // Clean up
            $this->client->deleteOperator($limitedOperatorUuid);
        }

        public function testMultipleOperatorInteractions()
        {
            // Create multiple operators for interaction testing
            $operator1Uuid = $this->client->createOperator('test-interaction-1');
            $operator2Uuid = $this->client->createOperator('test-interaction-2');
            $operator3Uuid = $this->client->createOperator('test-interaction-3');

            // Set different permissions for each
            $this->client->setClientPermission($operator1Uuid, true);
            $this->client->setManageOperatorsPermission($operator1Uuid, true);

            $this->client->setClientPermission($operator2Uuid, true);
            $this->client->setManageBlacklistPermission($operator2Uuid, true);

            $this->client->setClientPermission($operator3Uuid, true);

            // Test that operator1 can manage operator3
            $operator1 = $this->client->getOperator($operator1Uuid);
            if ($operator1->getApiKey() !== null) {
                $manager1Client = new FederationClient(getenv('SERVER_ENDPOINT'), $operator1->getApiKey());

                // Modify operator3's permissions
                $manager1Client->setManageBlacklistPermission($operator3Uuid, true);
                $updatedOperator3 = $manager1Client->getOperator($operator3Uuid);
                $this->assertTrue($updatedOperator3->canManageBlacklist());
            }

            // Clean up all operators
            $this->client->deleteOperator($operator1Uuid);
            $this->client->deleteOperator($operator2Uuid);
            $this->client->deleteOperator($operator3Uuid);
        }

        public function testOperatorValidationEdgeCases()
        {
            // Test invalid UUID formats
            $this->expectException(RequestException::class);
            $this->client->getOperator('invalid-uuid-format');
        }

        public function testOperatorNameValidation()
        {
            // Test operator name with special characters
            $uuid1 = $this->client->createOperator('test-operator-with-dashes');
            $this->assertNotEmpty($uuid1);

            $uuid2 = $this->client->createOperator('test operator with spaces');
            $this->assertNotEmpty($uuid2);

            $uuid3 = $this->client->createOperator('test_operator_with_underscores');
            $this->assertNotEmpty($uuid3);

            // Verify operators were created with correct names
            $operator1 = $this->client->getOperator($uuid1);
            $this->assertEquals('test-operator-with-dashes', $operator1->getName());

            $operator2 = $this->client->getOperator($uuid2);
            $this->assertEquals('test operator with spaces', $operator2->getName());

            $operator3 = $this->client->getOperator($uuid3);
            $this->assertEquals('test_operator_with_underscores', $operator3->getName());

            // Clean up
            $this->client->deleteOperator($uuid1);
            $this->client->deleteOperator($uuid2);
            $this->client->deleteOperator($uuid3);
        }

        public function testOperatorTimestamps()
        {
            // Create an operator and verify timestamps
            $operatorUuid = $this->client->createOperator('test-timestamps');
            $operator = $this->client->getOperator($operatorUuid);

            // Verify timestamps are reasonable (within last minute)
            $now = time();
            $this->assertGreaterThan($now - 60, $operator->getCreated());
            $this->assertLessThanOrEqual($now, $operator->getCreated());
            $this->assertGreaterThan($now - 60, $operator->getUpdated());
            $this->assertLessThanOrEqual($now, $operator->getUpdated());

            // Store original updated timestamp
            $originalUpdated = $operator->getUpdated();

            // Make a change and verify updated timestamp changes
            sleep(1); // Ensure time difference
            $this->client->setClientPermission($operatorUuid, true);
            $updatedOperator = $this->client->getOperator($operatorUuid);
            $this->assertGreaterThanOrEqual($originalUpdated, $updatedOperator->getUpdated());

            // Clean up
            $this->client->deleteOperator($operatorUuid);
        }

        public function testOperatorApiKeySecurity()
        {
            // Create an operator with client permissions
            $operatorUuid = $this->client->createOperator('test-api-key-security');
            $this->client->setClientPermission($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);

            // Verify API key is present for client operators
            $this->assertNotNull($operator->getApiKey());
            $this->assertNotEmpty($operator->getApiKey());

            // Test API key clearance
            $operator->clearApiKey();
            $this->assertNull($operator->getApiKey());

            // Clean up
            $this->client->deleteOperator($operatorUuid);
        }

        public function testBulkOperatorOperations()
        {
            // Create multiple operators for bulk testing
            $operatorUuids = [];
            for ($i = 1; $i <= 5; $i++) {
                $operatorUuids[] = $this->client->createOperator("bulk-test-operator-$i");
            }

            // Verify all operators were created
            $this->assertCount(5, $operatorUuids);
            foreach ($operatorUuids as $uuid) {
                $this->assertNotEmpty($uuid);
            }

            // Enable client permissions for all
            foreach ($operatorUuids as $uuid) {
                $this->client->setClientPermission($uuid, true);
            }

            // Verify all have client permissions
            foreach ($operatorUuids as $uuid) {
                $operator = $this->client->getOperator($uuid);
                $this->assertTrue($operator->isClient());
            }

            // Disable some operators
            for ($i = 0; $i < 3; $i++) {
                $this->client->disableOperator($operatorUuids[$i]);
            }

            // Verify disabled status
            for ($i = 0; $i < 3; $i++) {
                $operator = $this->client->getOperator($operatorUuids[$i]);
                $this->assertTrue($operator->isDisabled());
            }

            // Clean up all operators
            foreach ($operatorUuids as $uuid) {
                $this->client->deleteOperator($uuid);
            }
        }

        public function testRefreshToken()
        {
            $originalKey = $this->client->getApiKey();
            $this->client->refreshApiKey();
            $this->assertNotEquals($originalKey, $this->client->getApiKey());
        }

        public function testRefreshOperatorsToken()
        {
            // Create an operator to refresh token for
            $uuid = $this->client->createOperator('test refresh-token');
            $this->assertNotEmpty($uuid);
            $originalOperator = $this->client->getOperator($uuid);
            $originalApiKey = $originalOperator->getApiKey();
            $this->client->refreshOperatorApiKey($uuid);

            // Refersh the operator and verify API key has changed
            $refreshedOperator = $this->client->getOperator($uuid);
            $this->assertNotEquals($originalApiKey, $refreshedOperator->getApiKey());

            // Clean up
            $this->client->deleteOperator($uuid);
        }
    }
