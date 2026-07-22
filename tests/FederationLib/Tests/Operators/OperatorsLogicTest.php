<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class OperatorsLogicTest extends TestCase
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

        public function testListOperatorsCategoryEnabled(): void
        {
            $uuids = [];
            for ($i = 0; $i < 2; $i++)
            {
                $uuid = $this->client->createOperator('op_cat_enabled_' . uniqid());
                $this->createdOperators[] = $uuid;
                $uuids[] = $uuid;
            }

            $operators = $this->client->listOperators(1, 100, 'ENABLED');
            $foundUuids = array_map(fn($o) => $o->getUuid(), $operators);
            foreach ($uuids as $uuid)
            {
                $this->assertContains($uuid, $foundUuids);
            }
            foreach ($operators as $o)
            {
                $this->assertFalse($o->isDisabled());
            }
        }

        public function testListOperatorsCategoryDisabled(): void
        {
            $enabledUuid = $this->client->createOperator('op_cat_dis_ena_' . uniqid());
            $this->createdOperators[] = $enabledUuid;

            $disabledUuid = $this->client->createOperator('op_cat_dis_' . uniqid());
            $this->createdOperators[] = $disabledUuid;
            $this->client->disableOperator($disabledUuid);

            $operators = $this->client->listOperators(1, 100, 'DISABLED');
            $foundUuids = array_map(fn($o) => $o->getUuid(), $operators);

            $this->assertContains($disabledUuid, $foundUuids);
            $this->assertNotContains($enabledUuid, $foundUuids);
            foreach ($operators as $o)
            {
                $this->assertTrue($o->isDisabled());
            }
        }

        public function testListOperatorsCategoryWithSort(): void
        {
            $names = ['z-op-cat-sort', 'a-op-cat-sort'];
            $uuids = [];
            foreach ($names as $name)
            {
                $uuid = $this->client->createOperator($name . '_' . uniqid());
                $this->createdOperators[] = $uuid;
                $uuids[] = $uuid;
            }

            $operators = $this->client->listOperators(1, 100, 'ENABLED', 'name', 'ASC');
            $filtered = array_values(array_filter($operators, fn($o) => in_array($o->getUuid(), $uuids, true)));

            $this->assertCount(2, $filtered);
            $this->assertStringStartsWith('a-op-cat-sort', $filtered[0]->getName());
            $this->assertStringStartsWith('z-op-cat-sort', $filtered[1]->getName());
        }

        public function testListOperatorsCategoryInvalidFallsBack(): void
        {
            $uuid = $this->client->createOperator('op_cat_inv_' . uniqid());
            $this->createdOperators[] = $uuid;

            $resultDefault = $this->client->listOperators(1, 10);
            $resultInvalid = $this->client->listOperators(1, 10, 'NONEXISTENT_CATEGORY');

            $defaultUuids = array_map(fn($o) => $o->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($o) => $o->getUuid(), $resultInvalid);

            $this->assertNotEmpty($resultInvalid);
            $this->assertSame($defaultUuids, $invalidUuids);
        }

        public function testListOperatorsCategoryCaseInsensitive(): void
        {
            $uuid = $this->client->createOperator('op_cat_ci_' . uniqid());
            $this->createdOperators[] = $uuid;

            $resultUpper = $this->client->listOperators(1, 10, 'ENABLED');
            $resultLower = $this->client->listOperators(1, 10, 'enabled');
            $resultMixed = $this->client->listOperators(1, 10, 'Enabled');

            $upperUuids = array_map(fn($o) => $o->getUuid(), $resultUpper);
            $lowerUuids = array_map(fn($o) => $o->getUuid(), $resultLower);
            $mixedUuids = array_map(fn($o) => $o->getUuid(), $resultMixed);

            $this->assertNotEmpty($resultUpper);
            $this->assertSame($upperUuids, $lowerUuids);
            $this->assertSame($upperUuids, $mixedUuids);
        }

        public function testListOperatorsSortByNameAscending(): void
        {
            $names = ['z-operator-sort', 'a-operator-sort', 'm-operator-sort'];
            $created = [];
            foreach ($names as $name)
            {
                $uuid = $this->client->createOperator($name . '_' . uniqid());
                $this->createdOperators[] = $uuid;
                $created[] = $uuid;
            }

            $operators = $this->client->listOperators(1, 100, null, 'name', 'ASC');
            $filtered = array_values(array_filter($operators, fn($o) => in_array($o->getUuid(), $created, true)));

            $this->assertCount(3, $filtered);
            $this->assertStringStartsWith('a-operator-sort', $filtered[0]->getName());
            $this->assertStringStartsWith('m-operator-sort', $filtered[1]->getName());
            $this->assertStringStartsWith('z-operator-sort', $filtered[2]->getName());
        }

        public function testListOperatorsSortByCreatedDescending(): void
        {
            $uuids = [];
            for ($i = 0; $i < 3; $i++)
            {
                $uuid = $this->client->createOperator('op-srt-crt-' . uniqid());
                $this->createdOperators[] = $uuid;
                $uuids[] = $uuid;
            }

            $operators = $this->client->listOperators(1, 100, null, 'created', 'DESC');
            $filtered = array_values(array_filter($operators, fn($o) => in_array($o->getUuid(), $uuids, true)));

            $this->assertCount(3, $filtered);
            $this->assertEquals($uuids[2], $filtered[0]->getUuid());
            $this->assertEquals($uuids[1], $filtered[1]->getUuid());
            $this->assertEquals($uuids[0], $filtered[2]->getUuid());
        }

        public function testListOperatorsSortInvalidByFallsBack(): void
        {
            $name = 'op-inv-by-' . uniqid();
            $uuid = $this->client->createOperator($name);
            $this->createdOperators[] = $uuid;

            $resultDefault = $this->client->listOperators(1, 10);
            $resultInvalid = $this->client->listOperators(1, 10, null, 'nonexistent_column');

            $defaultUuids = array_map(fn($o) => $o->getUuid(), $resultDefault);
            $invalidUuids = array_map(fn($o) => $o->getUuid(), $resultInvalid);

            $this->assertSame($defaultUuids, $invalidUuids);
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
    }
