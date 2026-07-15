<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\SecurityTestHelpers;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class OperatorsClientTest extends TestCase
    {
        use SecurityTestHelpers;
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

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testCreateOperatorNoPermissions(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->assertNotEmpty($operatorUuid);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->hasManagementPermissions());
            $this->assertFalse($operatorRecord->hasOperatorPermissions());
            $this->assertFalse($operatorRecord->hasClientPermissions());
            $this->assertNotEmpty($operatorRecord->getAccessToken());
        }

        public function testCreateOperatorWithManagementPermission(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->hasManagementPermissions());
            $this->assertFalse($operatorRecord->hasOperatorPermissions());
            $this->assertFalse($operatorRecord->hasClientPermissions());
        }

        public function testCreateOperatorWithOperatorPermission(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setOperatorPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->hasManagementPermissions());
            $this->assertTrue($operatorRecord->hasOperatorPermissions());
            $this->assertFalse($operatorRecord->hasClientPermissions());
        }

        public function testCreateOperatorWithClientPermission(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setClientPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->hasManagementPermissions());
            $this->assertFalse($operatorRecord->hasOperatorPermissions());
            $this->assertTrue($operatorRecord->hasClientPermissions());
        }

        public function testCreateOperatorWithAllPermissions(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setClientPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->hasManagementPermissions());
            $this->assertTrue($operatorRecord->hasOperatorPermissions());
            $this->assertTrue($operatorRecord->hasClientPermissions());
        }

        public function testOperatorClientPermissionAuthorized(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('test operator'));
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->hasClientPermissions());

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('example.com', uniqid('john_doe_'));
            $this->assertNotEmpty($entityUuid);

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'This is some test evidence', 'Test note', 'test_tag', false);
            $this->assertNotEmpty($evidenceUuid);

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($evidenceUuid, $evidenceRecord->getUuid());
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());
            $this->assertEquals('This is some test evidence', $evidenceRecord->getTextContent());
            $this->assertEquals('Test note', $evidenceRecord->getNote());
            $this->assertEquals('test_tag', $evidenceRecord->getTag());
            $this->assertFalse($evidenceRecord->isConfidential());

            $this->client->deleteEvidence($evidenceUuid);
            $this->client->deleteEntity($entityUuid);
        }

        public function testOperatorClientPermissionUnauthorized(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('test operator'));
            $this->createdOperators[] = $operatorUuid;

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->hasClientPermissions());

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getAccessToken());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->pushEntity('example.com', uniqid('john_doe_'));
        }

        public function testOperatorManageOperatorsPermissionAuthorized(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('test operator'));
            $this->createdOperators[] = $operatorUuid;
            $this->client->setOperatorPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->hasOperatorPermissions());

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getAccessToken());

            $managedOperatorName = uniqid('managed operator');
            $managedOperatorUuid = $operatorClient->createOperator($managedOperatorName);
            $this->createdOperators[] = $managedOperatorUuid;

            $this->assertNotEmpty($managedOperatorUuid);

            $managedOperatorRecord = $this->client->getOperator($managedOperatorUuid);
            $this->assertNotNull($managedOperatorRecord);
            $this->assertEquals($managedOperatorName, $managedOperatorRecord->getName());
        }

        public function testOperatorManageOperatorPermissionUnauthorized(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('test operator'));
            $this->createdOperators[] = $operatorUuid;

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->hasOperatorPermissions());

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getAccessToken());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->createOperator(uniqid('managed operator'));
        }

        public function testDeleteOperator(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->assertNotEmpty($operatorUuid);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertEquals($name, $operatorRecord->getName());

            $this->client->deleteOperator($operatorUuid);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getOperator($operatorUuid);
        }

        public function testCreateInvalidOperatorName(): void
        {
            $name = str_repeat('a', 256);
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->createOperator($name);
        }

        public function testDeleteNonExistentOperator(): void
        {
            $nonExistentOperatorUuid = Uuid::v7()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteOperator($nonExistentOperatorUuid);
        }

        public function testGetNonExistentOperator(): void
        {
            $nonExistentOperatorUuid = Uuid::v7()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getOperator($nonExistentOperatorUuid);
        }

        public function testDisabledOperator(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('test operator'));
            $this->createdOperators[] = $operatorUuid;

            $this->client->disableOperator($operatorUuid);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->isDisabled());

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getAccessToken());

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::FORBIDDEN->value);
            $operatorClient->getSelf();
        }

        public function testEnableDisabledOperator(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('test operator'));
            $this->createdOperators[] = $operatorUuid;

            $this->client->disableOperator($operatorUuid);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->isDisabled());

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operatorRecord->getAccessToken());

            try
            {
                $operatorClient->getSelf();
                $this->fail('Expected exception for disabled operator');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(HttpResponseCode::FORBIDDEN->value, $e->getCode());
            }

            $this->client->enableOperator($operatorUuid);

            $selfOperator = $operatorClient->getSelf();
            $this->assertNotEmpty($selfOperator);
            $this->assertFalse($selfOperator->isDisabled());
        }

        public function testListOperators(): void
        {
            $createdOperators = [];
            for ($i = 0; $i < 10; $i++)
            {
                $name = uniqid('test operator');
                $operatorUuid = $this->client->createOperator($name);
                $this->createdOperators[] = $operatorUuid;
                $createdOperators[] = $operatorUuid;
            }

            $operators = $this->client->listOperators();
            $this->assertNotNull($operators);
            $this->assertGreaterThanOrEqual(10, count($operators));

            foreach ($operators as $operator)
            {
                $this->assertNotEmpty($operator->getUuid());
                $this->assertNotEmpty($operator->getName());
            }

            $found = false;
            $currentPage = 1;
            while (true)
            {
                $currentPageResults = $this->client->listOperators(page: $currentPage);
                if (count($currentPageResults) === 0)
                {
                    break;
                }

                foreach ($currentPageResults as $operator)
                {
                    if (in_array($operator->getUuid(), $createdOperators, true))
                    {
                        $found = true;
                        break 2;
                    }
                }

                $currentPage++;
            }

            $this->assertTrue($found, 'No created operators were found in the database');
        }

        public function testOperatorLifecycleIntegrity(): void
        {
            $operatorName = uniqid('lifecycle_operator_');
            $operatorUuid = $this->client->createOperator($operatorName);
            $this->createdOperators[] = $operatorUuid;

            $operator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($operatorName, $operator->getName());
            $this->assertFalse($operator->hasManagementPermissions());
            $this->assertFalse($operator->hasOperatorPermissions());
            $this->assertFalse($operator->hasClientPermissions());
            $this->assertFalse($operator->isDisabled());
            $this->assertNotNull($operator->getAccessToken());

            $this->client->setManagementPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setClientPermissions($operatorUuid, true);

            $updatedOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($updatedOperator->hasManagementPermissions());
            $this->assertTrue($updatedOperator->hasOperatorPermissions());
            $this->assertTrue($updatedOperator->hasClientPermissions());

            $this->client->disableOperator($operatorUuid);
            $disabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($disabledOperator->isDisabled());

            $this->client->enableOperator($operatorUuid);
            $enabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($enabledOperator->isDisabled());
            $this->assertTrue($enabledOperator->hasManagementPermissions());
            $this->assertTrue($enabledOperator->hasOperatorPermissions());
            $this->assertTrue($enabledOperator->hasClientPermissions());

            $originalAccessToken = $enabledOperator->getAccessToken();
            $newAccessToken = $this->client->generateOperatorAccessToken($operatorUuid);
            $this->assertNotEquals($originalAccessToken, $newAccessToken);

            $refreshedOperator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newAccessToken, $refreshedOperator->getAccessToken());

            $this->client->deleteOperator($operatorUuid);

            try
            {
                $this->client->getOperator($operatorUuid);
                $this->fail('Expected RequestException for deleted operator');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(404, $e->getCode());
            }

            array_splice($this->createdOperators, array_search($operatorUuid, $this->createdOperators), 1);
        }

        public function testOperatorPermissionConsistency(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('prm_test_operator_'));
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, true);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->hasManagementPermissions());

            $this->client->setManagementPermissions($operatorUuid, false);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->hasManagementPermissions());

            $this->client->setOperatorPermissions($operatorUuid, true);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->hasOperatorPermissions());

            $this->client->setOperatorPermissions($operatorUuid, false);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->hasOperatorPermissions());

            $this->client->setClientPermissions($operatorUuid, true);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operator->hasClientPermissions());

            $this->client->setClientPermissions($operatorUuid, false);
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->hasClientPermissions());

            for ($i = 0; $i < 5; $i++)
            {
                $this->client->setManagementPermissions($operatorUuid, $i % 2 === 0);
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertEquals($i % 2 === 0, $operator->hasManagementPermissions());
            }
        }

        public function testHighVolumeOperatorOperations(): void
        {
            $batchSize = 10;
            $operatorUuids = [];

            for ($i = 0; $i < $batchSize; $i++)
            {
                $operatorName = "batch_operator_$i";
                $operatorUuid = $this->client->createOperator($operatorName);
                $this->createdOperators[] = $operatorUuid;
                $operatorUuids[] = $operatorUuid;

                $this->client->setManagementPermissions($operatorUuid, $i % 2 === 0);
                $this->client->setOperatorPermissions($operatorUuid, $i % 3 === 0);
                $this->client->setClientPermissions($operatorUuid, $i % 4 === 0);
            }

            foreach ($operatorUuids as $index => $operatorUuid)
            {
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertEquals("batch_operator_$index", $operator->getName());
                $this->assertEquals($index % 2 === 0, $operator->hasManagementPermissions());
                $this->assertEquals($index % 3 === 0, $operator->hasOperatorPermissions());
                $this->assertEquals($index % 4 === 0, $operator->hasClientPermissions());
            }

            $allOperators = $this->client->listOperators(1, 100);
            $this->assertGreaterThanOrEqual($batchSize, count($allOperators));

            $foundUuids = array_map(fn($operator) => $operator->getUuid(), $allOperators);
            foreach ($operatorUuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }

            foreach ($operatorUuids as $operatorUuid)
            {
                $this->client->disableOperator($operatorUuid);
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertTrue($operator->isDisabled());
            }

            foreach ($operatorUuids as $operatorUuid)
            {
                $this->client->enableOperator($operatorUuid);
                $operator = $this->client->getOperator($operatorUuid);
                $this->assertFalse($operator->isDisabled());
            }
        }

        public function testOperatorAccessTokenIntegrity(): void
        {
            $operatorUuid = $this->client->createOperator('access_token_test_operator');
            $this->createdOperators[] = $operatorUuid;

            $operator = $this->client->getOperator($operatorUuid);
            $originalAccessToken = $operator->getAccessToken();
            $this->assertNotEmpty($originalAccessToken);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $originalAccessToken);
            $selfOperator = $operatorClient->getSelf();
            $this->assertEquals($operatorUuid, $selfOperator->getUuid());

            $previousKey = $originalAccessToken;
            for ($i = 0; $i < 3; $i++)
            {
                $newAccessToken = $this->client->generateOperatorAccessToken($operatorUuid);
                $this->assertNotEquals($previousKey, $newAccessToken);
                $this->assertNotEmpty($newAccessToken);

                $newOperatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newAccessToken);
                $newSelfOperator = $newOperatorClient->getSelf();
                $this->assertEquals($operatorUuid, $newSelfOperator->getUuid());

                try
                {
                    $oldOperatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $previousKey);
                    $oldOperatorClient->getSelf();
                    $this->fail('Expected RequestException for old Access Token');
                }
                catch (RequestException $e)
                {
                    $this->assertEquals(401, $e->getCode());
                }

                $previousKey = $newAccessToken;
            }

            $finalOperator = $this->client->getOperator($operatorUuid);
            $this->assertEquals($previousKey, $finalOperator->getAccessToken());
        }

        public function testOperatorStateTransitionIntegrity(): void
        {
            $operatorUuid = $this->client->createOperator('state_test_operator');
            $this->createdOperators[] = $operatorUuid;

            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->isDisabled());

            $this->client->setManagementPermissions($operatorUuid, true);
            $this->client->disableOperator($operatorUuid);

            $disabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($disabledOperator->isDisabled());
            $this->assertTrue($disabledOperator->hasManagementPermissions());

            $this->client->enableOperator($operatorUuid);
            $enabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($enabledOperator->isDisabled());
            $this->assertTrue($enabledOperator->hasManagementPermissions());

            $this->client->disableOperator($operatorUuid);
            $this->client->setOperatorPermissions($operatorUuid, true);

            $modifiedDisabledOperator = $this->client->getOperator($operatorUuid);
            $this->assertTrue($modifiedDisabledOperator->isDisabled());
            $this->assertTrue($modifiedDisabledOperator->hasOperatorPermissions());

            $this->client->enableOperator($operatorUuid);
            $finalOperator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($finalOperator->isDisabled());
            $this->assertTrue($finalOperator->hasManagementPermissions());
            $this->assertTrue($finalOperator->hasOperatorPermissions());
        }

        public function testOperatorCascadingOperations(): void
        {
            $parentOperatorUuid = $this->client->createOperator('parent_operator');
            $this->createdOperators[] = $parentOperatorUuid;

            $this->client->setOperatorPermissions($parentOperatorUuid, true);
            $parentOperator = $this->client->getOperator($parentOperatorUuid);

            $parentClient = new FederationClient(getenv('SERVER_ENDPOINT'), $parentOperator->getAccessToken());

            $childOperatorUuid = $parentClient->createOperator('child_operator');
            $this->createdOperators[] = $childOperatorUuid;

            $childOperator = $this->client->getOperator($childOperatorUuid);
            $this->assertEquals('child_operator', $childOperator->getName());

            $parentClient->setClientPermissions($childOperatorUuid, true);
            $modifiedChild = $this->client->getOperator($childOperatorUuid);
            $this->assertTrue($modifiedChild->hasClientPermissions());

            $parentClient->disableOperator($childOperatorUuid);
            $disabledChild = $this->client->getOperator($childOperatorUuid);
            $this->assertTrue($disabledChild->isDisabled());

            $parentClient->enableOperator($childOperatorUuid);
            $enabledChild = $this->client->getOperator($childOperatorUuid);
            $this->assertFalse($enabledChild->isDisabled());

            $parentClient->deleteOperator($childOperatorUuid);

            try
            {
                $this->client->getOperator($childOperatorUuid);
                $this->fail('Expected RequestException for deleted child operator');
            }
            catch (RequestException $e)
            {
                $this->assertEquals(404, $e->getCode());
            }

            array_splice($this->createdOperators, array_search($childOperatorUuid, $this->createdOperators), 1);
        }

        public function testSecurityUnauthenticatedRequestsAreRejected(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);
            $fakeUuid = '00000000-0000-0000-0000-000000000000';

            $operations = [
                'getSelf' => fn() => $unauthenticatedClient->getSelf(),
                'createOperator' => fn() => $unauthenticatedClient->createOperator('unauthorized'),
                'disableOperator' => fn() => $unauthenticatedClient->disableOperator($fakeUuid),
                'deleteOperator' => fn() => $unauthenticatedClient->deleteOperator($fakeUuid),
                'setOperatorPermissions' => fn() => $unauthenticatedClient->setOperatorPermissions($fakeUuid, true),
                'setManagementPermissions' => fn() => $unauthenticatedClient->setManagementPermissions($fakeUuid, true),
                'setClientPermissions' => fn() => $unauthenticatedClient->setClientPermissions($fakeUuid, true),
                'generateOperatorAccessToken' => fn() => $unauthenticatedClient->generateOperatorAccessToken($fakeUuid),
                'listOperators' => fn() => $unauthenticatedClient->listOperators(),
            ];

            foreach ($operations as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value], "Unauthenticated $name should be rejected");
            }
        }

        public function testSecurityMalformedAccessTokensAreRejected(): void
        {
            $shortTokenClient = new FederationClient(getenv('SERVER_ENDPOINT'), 'short');
            $this->expectRequestFailure(
                fn() => $shortTokenClient->getSelf(),
                [HttpResponseCode::BAD_REQUEST->value],
                'Too-short access token should be rejected'
            );

            $fake32CharToken = bin2hex(random_bytes(16));
            $nonExistentTokenClient = new FederationClient(getenv('SERVER_ENDPOINT'), $fake32CharToken);
            $this->expectRequestFailure(
                fn() => $nonExistentTokenClient->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value],
                'Non-existent 32-character access token should be rejected'
            );
        }

        public function testSecurityClientOnlyOperatorCannotPerformPrivilegedActions(): void
        {
            $clientOnly = $this->createLimitedOperator('client_only', client: true);
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $blacklistUuid = $this->createSecurityBlacklist($entityUuid);
            $report = $this->createSecurityReport();

            $privilegedOperations = [
                'createOperator' => fn() => $clientOnly->createOperator('child'),
                'blacklistEntity' => fn() => $clientOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM),
                'deleteReport' => fn() => $clientOnly->deleteReport($report['report']),
                'assignOperatorToReport' => fn() => $clientOnly->assignOperatorToReport($report['report'], $this->client->getSelf()->getUuid()),
                'closeReport' => fn() => $clientOnly->closeReport($report['report']),
                'deleteEntity' => fn() => $clientOnly->deleteEntity($entityUuid),
                'clearEntityReputation' => fn() => $clientOnly->clearEntityReputation($entityUuid),
                'deleteBlacklistRecord' => fn() => $clientOnly->deleteBlacklistRecord($blacklistUuid),
                'liftBlacklistRecord' => fn() => $clientOnly->liftBlacklistRecord($blacklistUuid),
                'listAttachments' => fn() => $clientOnly->listAttachments(),
            ];

            foreach ($privilegedOperations as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Client-only operator should not be able to $name");
            }
        }

        public function testSecurityOperatorOnlyOperatorCannotPerformClientActions(): void
        {
            $operatorOnly = $this->createLimitedOperator('operator_only', operator: true);
            $entityUuid = $this->createSecurityEntity();

            $clientActions = [
                'pushEntity' => fn() => $operatorOnly->pushEntity('example.com', 'user'),
                'submitEvidence' => fn() => $operatorOnly->submitEvidence($entityUuid, 'text', 'note', 'tag'),
                'submitReport' => fn() => $operatorOnly->submitReport($entityUuid, 'content', IncidentType::SPAM),
                'uploadNoteAttachment' => fn() => $operatorOnly->uploadNoteAttachment('00000000-0000-0000-0000-000000000000', 'note.txt', 'content'),
            ];

            foreach ($clientActions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Operator-only account should not be able to $name");
            }
        }

        public function testSecurityManagementOnlyOperatorCanManageRecordsButNotClientOrOperatorActions(): void
        {
            $managementOnly = $this->createLimitedOperator('management_only', management: true);
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);

            $blacklistUuid = $managementOnly->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            $this->assertNotEmpty($blacklistUuid);

            $report = $this->createSecurityReport();
            $managementOnly->assignOperatorToReport($report['report'], $managementOnly->getSelf()->getUuid());
            $managementOnly->closeReport($report['report']);
            $closedReport = $this->client->getReport($report['report']);
            $this->assertFalse($closedReport->isOpened());

            $forbiddenActions = [
                'createOperator' => fn() => $managementOnly->createOperator('child'),
                'pushEntity' => fn() => $managementOnly->pushEntity('example.com', 'user'),
                'submitEvidence' => fn() => $managementOnly->submitEvidence($entityUuid, 'text', 'note', 'tag'),
            ];

            foreach ($forbiddenActions as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Management-only operator should not be able to $name");
            }
        }

        public function testSecurityConfidentialEvidenceRequiresManagementPermission(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $confidentialEvidenceUuid = $this->createSecurityEvidence($entityUuid, true);

            $clientOnly = $this->createLimitedOperator('confidential_client', client: true);
            $operatorOnly = $this->createLimitedOperator('confidential_operator', operator: true);
            $managementOnly = $this->createLimitedOperator('confidential_management', management: true);

            $this->expectRequestFailure(
                fn() => $clientOnly->getEvidenceRecord($confidentialEvidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not view confidential evidence'
            );

            $this->expectRequestFailure(
                fn() => $operatorOnly->getEvidenceRecord($confidentialEvidenceUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'Operator-only account should not view confidential evidence'
            );

            $evidenceRecord = $managementOnly->getEvidenceRecord($confidentialEvidenceUuid);
            $this->assertEquals($confidentialEvidenceUuid, $evidenceRecord->getUuid());
            $this->assertTrue($evidenceRecord->isConfidential());
        }

        public function testSecurityRootOperatorCannotBeModified(): void
        {
            $root = $this->client->getSelf();
            $attacker = $this->createLimitedOperator('root_attacker', operator: true, management: true, client: true);

            $attacks = [
                'disableOperator' => fn() => $attacker->disableOperator($root->getUuid()),
                'deleteOperator' => fn() => $attacker->deleteOperator($root->getUuid()),
                'setOperatorPermissions' => fn() => $attacker->setOperatorPermissions($root->getUuid(), false),
                'setManagementPermissions' => fn() => $attacker->setManagementPermissions($root->getUuid(), false),
                'setClientPermissions' => fn() => $attacker->setClientPermissions($root->getUuid(), false),
                'generateOperatorAccessToken' => fn() => $attacker->generateOperatorAccessToken($root->getUuid()),
            ];

            foreach ($attacks as $name => $callback)
            {
                $this->expectRequestFailure($callback, [HttpResponseCode::FORBIDDEN->value], "Root operator should be protected from $name");
            }
        }

        public function testSecurityReservedOperatorNamesAreRejected(): void
        {
            $reservedNames = ['root', 'system', 'ROOT', 'System'];

            foreach ($reservedNames as $name)
            {
                $this->expectRequestFailure(
                    fn() => $this->client->createOperator($name),
                    [HttpResponseCode::BAD_REQUEST->value],
                    "Reserved operator name '$name' should be rejected"
                );
            }
        }

        public function testSecurityPermissionRevocationIsEffective(): void
        {
            $clientOperator = $this->createLimitedOperator('revocation_test', client: true);
            $operatorUuid = $clientOperator->getSelf()->getUuid();

            $firstEntityUuid = $clientOperator->pushEntity('revocation-test.com', 'user1');
            $this->createdEntities[] = $firstEntityUuid;
            $this->assertNotEmpty($firstEntityUuid);

            $this->client->setClientPermissions($operatorUuid, false);

            $this->expectRequestFailure(
                fn() => $clientOperator->pushEntity('revocation-test2.com', 'user2'),
                [HttpResponseCode::FORBIDDEN->value],
                'Revoked client permission should prevent pushing entities'
            );

            $this->client->setClientPermissions($operatorUuid, true);

            $secondEntityUuid = $clientOperator->pushEntity('revocation-test3.com', 'user3');
            $this->createdEntities[] = $secondEntityUuid;
            $this->assertNotEmpty($secondEntityUuid);
        }

        public function testSecurityDisabledOperatorCannotAuthenticate(): void
        {
            $operatorUuid = $this->client->createOperator('disabled_auth_test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $this->client->getOperator($operatorUuid)->getAccessToken());
            $this->assertNotEmpty($operatorClient->getSelf()->getUuid());

            $this->client->disableOperator($operatorUuid);

            $this->expectRequestFailure(
                fn() => $operatorClient->getSelf(),
                [HttpResponseCode::FORBIDDEN->value],
                'Disabled operator should not be able to call getSelf'
            );
        }

        public function testAccessTokenRedactedForNonManagers(): void
        {
            $operatorUuid = $this->client->createOperator('token_redaction_test');
            $this->createdOperators[] = $operatorUuid;

            $manager = $this->createLimitedOperator('token_viewer_manager', management: true, operator: true);
            $clientOnly = $this->createLimitedOperator('token_no_viewer', client: true);

            $fullRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotEmpty($fullRecord->getAccessToken());

            $managerRecord = $manager->getOperator($operatorUuid);
            $this->assertNotEmpty($managerRecord->getAccessToken());

            $clientRecord = $clientOnly->getOperator($operatorUuid);
            $this->assertEmpty($clientRecord->getAccessToken());
        }

        public function testTokenRotationInvalidatesOldTokenImmediately(): void
        {
            $operatorUuid = $this->client->createOperator('rotation_invalidation_test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $originalToken = $this->client->getOperator($operatorUuid)->getAccessToken();
            $originalClient = new FederationClient(getenv('SERVER_ENDPOINT'), $originalToken);
            $this->assertEquals($operatorUuid, $originalClient->getSelf()->getUuid());

            $newToken = $this->client->generateOperatorAccessToken($operatorUuid);
            $this->assertNotEquals($originalToken, $newToken);

            // The old token must be rejected immediately (tests Redis / DB cache invalidation).
            $this->expectRequestFailure(
                fn() => $originalClient->getSelf(),
                [HttpResponseCode::UNAUTHORIZED->value],
                'Old access token should be invalid immediately after rotation'
            );

            $newClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newToken);
            $this->assertEquals($operatorUuid, $newClient->getSelf()->getUuid());
        }

        public function testConcurrentPermissionModificationsAreConsistent(): void
        {
            $operatorUuid = $this->client->createOperator('concurrent_perm_test');
            $this->createdOperators[] = $operatorUuid;

            $managerA = $this->createLimitedOperator('concurrent_manager_a', management: true, operator: true);
            $managerB = $this->createLimitedOperator('concurrent_manager_b', management: true, operator: true);

            $managerA->setClientPermissions($operatorUuid, true);
            $managerB->setManagementPermissions($operatorUuid, true);
            $managerA->setOperatorPermissions($operatorUuid, true);

            $finalRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($finalRecord->hasClientPermissions());
            $this->assertTrue($finalRecord->hasManagementPermissions());
            $this->assertTrue($finalRecord->hasOperatorPermissions());
        }

        public function testOperatorDeleteDoesNotPreventReadingRelatedRecords(): void
        {
            $operatorUuid = $this->client->createOperator('delete_related_test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operator = $this->client->getOperator($operatorUuid);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $operator->getAccessToken());

            $entityUuid = $operatorClient->pushEntity('operator-delete-related.com', 'related_user');
            $this->createdEntities[] = $entityUuid;

            $evidenceUuid = $operatorClient->submitEvidence($entityUuid, 'Related evidence', 'Note', 'related');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            // Fetch records before operator deletion (they may be cascade-deleted when the operator is removed).
            $entityRecord = $this->client->getEntityRecord($entityUuid);
            $this->assertNotNull($entityRecord);

            $evidenceRecord = $this->client->getEvidenceRecord($evidenceUuid);
            $this->assertNotNull($evidenceRecord);
            $this->assertEquals($entityUuid, $evidenceRecord->getEntityUuid());

            $this->client->deleteOperator($operatorUuid);
            array_splice($this->createdOperators, array_search($operatorUuid, $this->createdOperators), 1);
        }

        public function testOperatorCannotDisableOrDeleteSelf(): void
        {
            $manager = $this->createLimitedOperator('self_protection_manager', management: true, operator: true);
            $selfUuid = $manager->getSelf()->getUuid();

            $this->expectRequestFailure(
                fn() => $manager->disableOperator($selfUuid),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::FORBIDDEN->value],
                'Operator should not be able to disable themselves'
            );

            $this->expectRequestFailure(
                fn() => $manager->deleteOperator($selfUuid),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::FORBIDDEN->value, HttpResponseCode::INTERNAL_SERVER_ERROR->value],
                'Operator should not be able to delete themselves'
            );
        }

        public function testPermissionChangeIsEffectiveAcrossMultipleClients(): void
        {
            $operatorUuid = $this->client->createOperator('cross_client_perm_test');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            $token = $this->client->getOperator($operatorUuid)->getAccessToken();
            $clientA = new FederationClient(getenv('SERVER_ENDPOINT'), $token);
            $clientB = new FederationClient(getenv('SERVER_ENDPOINT'), $token);

            $entityUuid = $clientA->pushEntity('cross-client-perm.com', 'user');
            $this->createdEntities[] = $entityUuid;

            $this->client->setClientPermissions($operatorUuid, false);

            $this->expectRequestFailure(
                fn() => $clientB->pushEntity('cross-client-perm-denied.com', 'user2'),
                [HttpResponseCode::FORBIDDEN->value],
                'Revoked permission should be visible to a second client instance'
            );

            // Re-enable to keep cleanup possible if an earlier assertion fails.
            $this->client->setClientPermissions($operatorUuid, true);
        }

        public function testMasterAccessTokenResolvesToRootOperator(): void
        {
            $masterClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $root = $masterClient->getSelf();

            $this->assertEquals('root', $root->getName());
            $this->assertTrue($root->hasManagementPermissions());
            $this->assertTrue($root->hasOperatorPermissions());
            $this->assertTrue($root->hasClientPermissions());
            $this->assertFalse($root->isDisabled());
        }

        public function testAuthorizationHeaderVariationsAreHandled(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');

            // Missing Bearer prefix should be rejected.
            [$noBearerCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: ' . $token]);
            $this->assertContains($noBearerCode, [400, 401], 'Token without Bearer prefix should be rejected');

            // Wrong scheme should be rejected.
            [$basicCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Basic ' . base64_encode('user:pass')]);
            $this->assertContains($basicCode, [400, 401], 'Basic auth should be rejected');

            // Empty Bearer token should be treated as unauthenticated.
            [$emptyCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ']);
            $this->assertContains($emptyCode, [400, 401], 'Empty Bearer token should be rejected');

            // Valid Bearer token should succeed.
            [$validCode] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ' . $token]);
            $this->assertEquals(200, $validCode, 'Valid Bearer token should succeed');
        }

        public function testInvalidAccessTokenFormatsAreRejected(): void
        {
            $invalidTokens = [
                'short',
                'exactly31characterslong1234567',
                'exactly33characterslong12345678',
                str_repeat('a', 32) . ' ',
                str_repeat('a', 32) . "\n",
                ' ' . str_repeat('a', 32),
            ];

            foreach ($invalidTokens as $token)
            {
                // Bypass client-side validation so the server itself rejects the malformed token.
                [$code] = $this->rawRequest('GET', 'operators/self', null, null, ['Authorization: Bearer ' . $token]);
                $this->assertContains(
                    $code,
                    [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                    "Invalid token format '$token' should be rejected by server"
                );
            }
        }

        public function testOperatorPermissionMatrix(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid);
            $report = $this->createSecurityReport();

            $combinations = [
                ['name' => 'none', 'management' => false, 'operator' => false, 'client' => false],
                ['name' => 'client_only', 'management' => false, 'operator' => false, 'client' => true],
                ['name' => 'operator_only', 'management' => false, 'operator' => true, 'client' => false],
                ['name' => 'management_only', 'management' => true, 'operator' => false, 'client' => false],
                ['name' => 'client_operator', 'management' => false, 'operator' => true, 'client' => true],
                ['name' => 'client_management', 'management' => true, 'operator' => false, 'client' => true],
                ['name' => 'operator_management', 'management' => true, 'operator' => true, 'client' => false],
                ['name' => 'all', 'management' => true, 'operator' => true, 'client' => true],
            ];

            foreach ($combinations as $combo)
            {
                $limited = $this->createLimitedOperator(
                    'matrix_' . $combo['name'],
                    management: $combo['management'],
                    operator: $combo['operator'],
                    client: $combo['client']
                );

                $self = $limited->getSelf();
                $this->assertEquals($combo['management'], $self->hasManagementPermissions(), $combo['name'] . ' management mismatch');
                $this->assertEquals($combo['operator'], $self->hasOperatorPermissions(), $combo['name'] . ' operator mismatch');
                $this->assertEquals($combo['client'], $self->hasClientPermissions(), $combo['name'] . ' client mismatch');

                // Client-only actions
                $domain = str_replace('_', '-', "matrix-{$combo['name']}.com");
                if ($combo['client'])
                {
                    $testEntity = $limited->pushEntity($domain, 'user');
                    $this->createdEntities[] = $testEntity;
                }
                else
                {
                    $this->expectRequestFailure(
                        fn() => $limited->pushEntity($domain, 'user'),
                        [HttpResponseCode::FORBIDDEN->value],
                        $combo['name'] . ' should not push entities'
                    );
                }

                // Operator-only actions
                if ($combo['operator'])
                {
                    $limited->updateEvidenceTag($evidenceUuid, 'matrix_tag');
                }
                else
                {
                    $this->expectRequestFailure(
                        fn() => $limited->updateEvidenceTag($evidenceUuid, 'matrix_tag'),
                        [HttpResponseCode::FORBIDDEN->value],
                        $combo['name'] . ' should not update evidence tag'
                    );
                }

                // Management-only actions
                if ($combo['management'])
                {
                    $limited->assignOperatorToReport($report['report'], $limited->getSelf()->getUuid());
                }
                else
                {
                    $this->expectRequestFailure(
                        fn() => $limited->assignOperatorToReport($report['report'], $limited->getSelf()->getUuid()),
                        [HttpResponseCode::FORBIDDEN->value],
                        $combo['name'] . ' should not assign reports'
                    );
                }
            }
        }

        public function testOperatorCannotEscalatePermissionsViaSelfModification(): void
        {
            $operator = $this->createLimitedOperator('escalation_attempt', client: true);

            $this->expectRequestFailure(
                fn() => $operator->setManagementPermissions($operator->getSelf()->getUuid(), true),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not grant themselves management permissions'
            );

            $this->expectRequestFailure(
                fn() => $operator->setOperatorPermissions($operator->getSelf()->getUuid(), true),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not grant themselves operator permissions'
            );
        }

        public function testDisabledOperatorTokenRejectedAcrossAllEndpoints(): void
        {
            $operatorUuid = $this->client->createOperator('disabled_all_endpoints');
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $this->client->getOperator($operatorUuid)->getAccessToken());
            $this->assertNotEmpty($operatorClient->getSelf()->getUuid());

            $this->client->disableOperator($operatorUuid);

            $endpoints = [
                'getSelf' => fn() => $operatorClient->getSelf(),
                'pushEntity' => fn() => $operatorClient->pushEntity('disabled-endpoints.com', 'user'),
                'listOperators' => fn() => $operatorClient->listOperators(),
                'listEvidence' => fn() => $operatorClient->listEvidence(),
                'listReports' => fn() => $operatorClient->listReports(),
                'listBlacklist' => fn() => $operatorClient->listBlacklistRecords(),
                'listAttachments' => fn() => $operatorClient->listAttachments(),
            ];

            foreach ($endpoints as $name => $callback)
            {
                $this->expectRequestFailure(
                    $callback,
                    [HttpResponseCode::FORBIDDEN->value],
                    "Disabled operator should be rejected for $name"
                );
            }
        }

        public function testSystemOperatorCannotAuthenticate(): void
        {
            // The system operator has token 'none' and cannot authenticate.
            $systemClient = new FederationClient(getenv('SERVER_ENDPOINT'), 'none');
            $this->expectRequestFailure(
                fn() => $systemClient->getSelf(),
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                'System operator token should not authenticate'
            );
        }

        public function testSecurityListOperatorsDoesNotExposeOtherOperatorTokens(): void
        {
            $manager = $this->createLimitedOperator('token_leak_manager', operator: true);
            $this->createLimitedOperator('token_leak_victim', client: true);

            $operators = $manager->listOperators(1, 1000);
            $managerUuid = $manager->getSelf()->getUuid();

            foreach ($operators as $operator)
            {
                if ($operator->getUuid() === $managerUuid)
                {
                    continue;
                }

                $this->assertEmpty(
                    $operator->getAccessToken(),
                    sprintf('Operator list should not expose access tokens for operator %s', $operator->getName())
                );
            }
        }

        public function testUpdateOperatorName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('rename_test_'));
            $this->createdOperators[] = $operatorUuid;

            $newName = uniqid('renamed_operator_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newName, $operatorRecord->getName());
        }

        public function testUpdateOperatorNameEmptyName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('empty_name_test_'));
            $this->createdOperators[] = $operatorUuid;

            $this->expectException(\InvalidArgumentException::class);
            $this->client->updateOperatorName($operatorUuid, '');
        }

        public function testUpdateOperatorNameTooLongName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('long_name_test_'));
            $this->createdOperators[] = $operatorUuid;

            $longName = str_repeat('a', 33);
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->updateOperatorName($operatorUuid, $longName);
        }

        public function testUpdateOperatorNameEmptyUuid(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->client->updateOperatorName('', 'new-name');
        }

        public function testUpdateOperatorNameNonExistentOperator(): void
        {
            $nonExistentUuid = Uuid::v7()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->updateOperatorName($nonExistentUuid, 'new-name');
        }

        public function testUpdateOperatorNameDuplicateName(): void
        {
            $nameA = uniqid('dup_name_a_');
            $nameB = uniqid('dup_name_b_');
            $operatorAUuid = $this->client->createOperator($nameA);
            $this->createdOperators[] = $operatorAUuid;
            $operatorBUuid = $this->client->createOperator($nameB);
            $this->createdOperators[] = $operatorBUuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::CONFLICT->value);
            $this->client->updateOperatorName($operatorBUuid, $nameA);
        }

        public function testUpdateOperatorNameRequiresOperatorPermission(): void
        {
            $clientOnly = $this->createLimitedOperator('update_name_client', client: true);
            $targetUuid = $this->client->createOperator(uniqid('update_name_target_'));
            $this->createdOperators[] = $targetUuid;

            $this->expectRequestFailure(
                fn() => $clientOnly->updateOperatorName($targetUuid, 'new-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not update operator names'
            );
        }

        public function testUpdateOperatorNameRootOperatorProtected(): void
        {
            $root = $this->client->getSelf();
            $manager = $this->createLimitedOperator('root_name_attacker', operator: true, management: true);

            $this->expectRequestFailure(
                fn() => $manager->updateOperatorName($root->getUuid(), 'new-root-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Root operator name should be protected from modification'
            );
        }

        public function testUpdateOperatorNameSystemOperatorProtected(): void
        {
            $manager = $this->createLimitedOperator('system_name_attacker', operator: true, management: true);

            $this->expectRequestFailure(
                fn() => $manager->updateOperatorName('00000000-0000-0000-0000-000000000000', 'new-system-name'),
                [HttpResponseCode::FORBIDDEN->value, HttpResponseCode::NOT_FOUND->value],
                'System operator name should be protected from modification'
            );
        }

        public function testUpdateOperatorNameDisabledOperatorCannotUpdateName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('dis_upd_name_'));
            $this->createdOperators[] = $operatorUuid;
            $this->client->setOperatorPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $this->client->getOperator($operatorUuid)->getAccessToken());
            $this->client->disableOperator($operatorUuid);

            $this->expectRequestFailure(
                fn() => $operatorClient->updateOperatorName($operatorUuid, 'new-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Disabled operator should not be able to update names'
            );
        }

        public function testUpdateOperatorNameSelfModification(): void
        {
            $operator = $this->createLimitedOperator('self_rename', operator: true);
            $selfUuid = $operator->getSelf()->getUuid();
            $newName = uniqid('self_renamed_');

            $operator->updateOperatorName($selfUuid, $newName);

            $updatedRecord = $this->client->getOperator($selfUuid);
            $this->assertEquals($newName, $updatedRecord->getName());
        }

        public function testUpdateOperatorNameCrossOperatorModification(): void
        {
            $manager = $this->createLimitedOperator('cross_rename', operator: true);
            $targetUuid = $this->client->createOperator(uniqid('cross_tgt_'));
            $this->createdOperators[] = $targetUuid;

            $newName = uniqid('cross_renamed_');
            $manager->updateOperatorName($targetUuid, $newName);

            $updatedRecord = $this->client->getOperator($targetUuid);
            $this->assertEquals($newName, $updatedRecord->getName());
        }

        public function testUpdateOperatorNameUnauthenticatedRejected(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);
            $fakeUuid = '00000000-0000-0000-0000-000000000000';

            $this->expectRequestFailure(
                fn() => $unauthenticatedClient->updateOperatorName($fakeUuid, 'new-name'),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'Unauthenticated updateOperatorName should be rejected'
            );
        }

        public function testUpdateOperatorNameNameUniqueness(): void
        {
            $nameA = uniqid('unique_name_a_');
            $nameB = uniqid('unique_name_b_');
            $operatorAUuid = $this->client->createOperator($nameA);
            $this->createdOperators[] = $operatorAUuid;
            $operatorBUuid = $this->client->createOperator($nameB);
            $this->createdOperators[] = $operatorBUuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::CONFLICT->value);
            $this->client->updateOperatorName($operatorBUuid, $nameA);
        }

        public function testUpdateOperatorNameNamePreservedAfterFailedAttempt(): void
        {
            $nameA = uniqid('preserve_name_a_');
            $nameB = uniqid('preserve_name_b_');
            $operatorAUuid = $this->client->createOperator($nameA);
            $this->createdOperators[] = $operatorAUuid;
            $operatorBUuid = $this->client->createOperator($nameB);
            $this->createdOperators[] = $operatorBUuid;

            try
            {
                $this->client->updateOperatorName($operatorBUuid, $nameA);
            }
            catch (RequestException)
            {
            }

            $operatorBRecord = $this->client->getOperator($operatorBUuid);
            $this->assertEquals($nameB, $operatorBRecord->getName(), 'Name should be preserved after failed duplicate update');
        }

        public function testUpdateOperatorNameMalformedTokenRejected(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('mal_tok_ren_'));
            $this->createdOperators[] = $operatorUuid;

            [$code] = $this->rawRequest(
                'PATCH',
                'operators/' . $operatorUuid . '/update-name',
                'invalid_token',
                json_encode(['name' => 'new-name'])
            );

            $this->assertContains(
                $code,
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                'Malformed token should be rejected for updateOperatorName'
            );
        }

        public function testUpdateOperatorNameNameChangeReflectedInList(): void
        {
            $originalName = uniqid('list_orig_');
            $operatorUuid = $this->client->createOperator($originalName);
            $this->createdOperators[] = $operatorUuid;

            $newName = uniqid('list_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $operators = $this->client->listOperators(1, 1000);
            $found = false;
            foreach ($operators as $op)
            {
                if ($op->getUuid() === $operatorUuid)
                {
                    $this->assertEquals($newName, $op->getName());
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Updated operator should appear in operator list with new name');
        }

        public function testUpdateOperatorNameNameChangePersistsAcrossSessions(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('persist_rename_'));
            $this->createdOperators[] = $operatorUuid;

            $newName = uniqid('persist_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $freshClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operatorRecord = $freshClient->getOperator($operatorUuid);
            $this->assertEquals($newName, $operatorRecord->getName());
        }

        public function testUpdateOperatorNameManagementOnlyCannotUpdateName(): void
        {
            $managementOnly = $this->createLimitedOperator('mgmt_only_rename', management: true);
            $targetUuid = $this->client->createOperator(uniqid('mgmt_rename_target_'));
            $this->createdOperators[] = $targetUuid;

            $this->expectRequestFailure(
                fn() => $managementOnly->updateOperatorName($targetUuid, 'new-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Management-only operator should not update operator names'
            );
        }

        public function testUpdateOperatorNameNameChangeUpdatesTimestamp(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('timestamp_rename_'));
            $this->createdOperators[] = $operatorUuid;

            $before = $this->client->getOperator($operatorUuid);
            $originalUpdated = $before->getUpdated();

            sleep(1);

            $newName = uniqid('ts_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $after = $this->client->getOperator($operatorUuid);
            $this->assertGreaterThan($originalUpdated, $after->getUpdated(), 'Updated timestamp should increase after name change');
        }

        public function testUpdateOperatorNameNameChangeDoesNotAffectPermissions(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('perm_preserve_'));
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $before = $this->client->getOperator($operatorUuid);
            $newName = uniqid('perm_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $after = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newName, $after->getName());
            $this->assertEquals($before->hasClientPermissions(), $after->hasClientPermissions());
            $this->assertEquals($before->hasOperatorPermissions(), $after->hasOperatorPermissions());
            $this->assertEquals($before->hasManagementPermissions(), $after->hasManagementPermissions());
            $this->assertEquals($before->isDisabled(), $after->isDisabled());
            $this->assertEquals($before->getAccessToken(), $after->getAccessToken());
        }

        public function testUpdateOperatorNameNameChangeInvalidatesCache(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('cache_inval_'));
            $this->createdOperators[] = $operatorUuid;

            $this->client->getOperator($operatorUuid);

            $newName = uniqid('cache_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $freshClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operatorRecord = $freshClient->getOperator($operatorUuid);
            $this->assertEquals($newName, $operatorRecord->getName());
        }
    }
