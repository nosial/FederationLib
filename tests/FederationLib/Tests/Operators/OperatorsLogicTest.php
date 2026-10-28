<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\OperatorRecord;

    class OperatorsLogicTest extends ServerTestCase
    {
        /**
         * Creates an operator as the root operator and returns its UUID
         */
        private function createOperator(string $prefix): string
        {
            $operatorUuid = $this->client->createOperator(substr(uniqid($prefix . '_'), 0, 32))->getUuid();
            $this->createdOperators[] = $operatorUuid;
            return $operatorUuid;
        }

        /**
         * @param OperatorRecord[] $operators
         * @return string[]
         */
        private static function uuids(array $operators): array
        {
            return array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $operators);
        }

        public function testMasterAccessTokenResolvesToRootOperator(): void
        {
            $root = $this->client->getSelf();
            $this->assertEquals('root', $root->getName());
            $this->assertTrue($root->hasManagementPermissions());
            $this->assertTrue($root->hasOperatorPermissions());
            $this->assertTrue($root->hasClientPermissions());
            $this->assertFalse($root->isDisabled());
        }

        public function testPermissionsCanBeGrantedAndRevoked(): void
        {
            $operatorUuid = $this->createOperator('permissions');
            $operator = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operator->hasManagementPermissions());
            $this->assertFalse($operator->hasOperatorPermissions());
            $this->assertFalse($operator->hasClientPermissions());

            $setters = [
                'hasManagementPermissions' => fn(bool $value) => $this->client->setManagementPermissions($operatorUuid, $value),
                'hasOperatorPermissions' => fn(bool $value) => $this->client->setOperatorPermissions($operatorUuid, $value),
                'hasClientPermissions' => fn(bool $value) => $this->client->setClientPermissions($operatorUuid, $value),
            ];

            foreach ($setters as $getter => $set)
            {
                foreach ([true, false, true, false] as $value)
                {
                    $set($value);
                    $this->assertSame($value, $this->client->getOperator($operatorUuid)->$getter(), "$getter after setting it to " . json_encode($value));
                }
            }
        }

        public function testOperatorPermissionsManageOtherOperators(): void
        {
            $manager = $this->createLimitedOperator('operator_manager', operator: true);

            $childName = substr(uniqid('child_'), 0, 32);
            $childUuid = $manager->createOperator($childName)->getUuid();
            $this->createdOperators[] = $childUuid;
            $this->assertEquals($childName, $this->client->getOperator($childUuid)->getName());

            $manager->setClientPermissions($childUuid, true);
            $this->assertTrue($this->client->getOperator($childUuid)->hasClientPermissions());

            $manager->disableOperator($childUuid);
            $this->assertTrue($this->client->getOperator($childUuid)->isDisabled());
            $manager->enableOperator($childUuid);
            $this->assertFalse($this->client->getOperator($childUuid)->isDisabled());

            // An operator that created no records is deleted without management permissions
            $manager->deleteOperator($childUuid);
            $this->removeFromCleanup($this->createdOperators, $childUuid);
            $this->expectRequestFailure(fn() => $this->client->getOperator($childUuid), [HttpResponseCode::NOT_FOUND->value]);
        }

        public function testDisablingAnOperatorSuspendsItsToken(): void
        {
            $created = $this->client->createOperator(substr(uniqid('disable_'), 0, 32));
            $operatorUuid = $created->getUuid();
            $this->createdOperators[] = $operatorUuid;
            $this->client->setManagementPermissions($operatorUuid, true);
            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $created->getAccessToken());

            $this->client->disableOperator($operatorUuid);
            $this->assertTrue($this->client->getOperator($operatorUuid)->isDisabled());
            $this->expectRequestFailure(fn() => $operatorClient->getSelf(), [HttpResponseCode::FORBIDDEN->value], 'A disabled operator should not authenticate');

            // Permissions are kept, and can be changed, while the operator is disabled
            $this->client->setOperatorPermissions($operatorUuid, true);
            $disabled = $this->client->getOperator($operatorUuid);
            $this->assertTrue($disabled->hasManagementPermissions());
            $this->assertTrue($disabled->hasOperatorPermissions());

            $this->client->enableOperator($operatorUuid);
            $self = $operatorClient->getSelf();
            $this->assertFalse($self->isDisabled());
            $this->assertTrue($self->hasManagementPermissions());
            $this->assertTrue($self->hasOperatorPermissions());
        }

        public function testAccessTokenRotation(): void
        {
            $created = $this->client->createOperator(substr(uniqid('rotation_'), 0, 32));
            $operatorUuid = $created->getUuid();
            $this->createdOperators[] = $operatorUuid;

            $previousToken = $created->getAccessToken();
            $previousClient = new FederationClient(getenv('SERVER_ENDPOINT'), $previousToken);
            $this->assertEquals($operatorUuid, $previousClient->getSelf()->getUuid());

            for ($i = 0; $i < 3; $i++)
            {
                $newToken = $this->client->generateOperatorAccessToken($operatorUuid);
                $this->assertNotEquals($previousToken, $newToken);
                $this->assertSame(32, strlen($newToken));

                // The previous token is rejected immediately, including by a client that used it before
                $this->expectRequestFailure(fn() => $previousClient->getSelf(), [HttpResponseCode::UNAUTHORIZED->value], 'A replaced access token should no longer authenticate');

                $previousClient = new FederationClient(getenv('SERVER_ENDPOINT'), $newToken);
                $this->assertEquals($operatorUuid, $previousClient->getSelf()->getUuid());
                $previousToken = $newToken;
            }
        }

        public function testOperatorCannotDisableOrDeleteItself(): void
        {
            $manager = $this->createLimitedOperator('self_protection_manager', management: true, operator: true);
            $selfUuid = $manager->getSelf()->getUuid();

            // OFD "Delete Operator": a host MAY reject the deletion of the operator itself with a client error
            $this->expectRequestFailure(fn() => $manager->disableOperator($selfUuid), [HttpResponseCode::BAD_REQUEST->value], 'An operator should not disable itself');
            $this->expectRequestFailure(fn() => $manager->deleteOperator($selfUuid), [HttpResponseCode::BAD_REQUEST->value], 'An operator should not delete itself');
            $this->assertFalse($this->client->getOperator($selfUuid)->isDisabled());
        }

        public function testOperatorCannotEscalateItsOwnPermissions(): void
        {
            $operator = $this->createLimitedOperator('escalation_attempt', client: true);
            $selfUuid = $operator->getSelf()->getUuid();

            $this->expectRequestFailure(fn() => $operator->setManagementPermissions($selfUuid, true), [HttpResponseCode::FORBIDDEN->value]);
            $this->expectRequestFailure(fn() => $operator->setOperatorPermissions($selfUuid, true), [HttpResponseCode::FORBIDDEN->value]);

            $self = $this->client->getOperator($selfUuid);
            $this->assertFalse($self->hasManagementPermissions());
            $this->assertFalse($self->hasOperatorPermissions());
        }

        public function testDeletingAnOperatorDeletesItsRecords(): void
        {
            // OFD "Delete Operator": a host MAY delete the records an operator created along with it, and then requires
            // management permissions to delete an operator that created any
            $owner = $this->createLimitedOperator('record_owner', management: true);
            $ownerUuid = $owner->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity($owner);
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $owner);
            $reportUuid = $this->createReportForEntity($entityUuid, $owner);
            $blacklistUuid = $owner->blacklistEntity($entityUuid, $reportUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;

            // A report the owner is assigned is kept and unassigned
            $assigned = $this->createSecurityReport();
            $this->client->assignOperatorToReport($assigned['report'], $ownerUuid);

            $this->expectRequestFailure(
                fn() => $this->createLimitedOperator('record_deleter', operator: true)->deleteOperator($ownerUuid),
                [HttpResponseCode::FORBIDDEN->value],
                'An operator without management permissions should not delete an operator that created records'
            );
            $this->assertEquals($evidenceUuid, $this->client->getEvidenceRecord($evidenceUuid)->getUuid());

            $this->createLimitedOperator('record_manager', management: true, operator: true)->deleteOperator($ownerUuid);
            $this->removeFromCleanup($this->createdOperators, $ownerUuid);

            $this->expectRequestFailure(fn() => $this->client->getOperator($ownerUuid), [HttpResponseCode::NOT_FOUND->value]);
            $this->expectRequestFailure(fn() => $this->client->getEvidenceRecord($evidenceUuid), [HttpResponseCode::NOT_FOUND->value], 'The evidence of the operator should be deleted with it');
            $this->expectRequestFailure(fn() => $this->client->getReport($reportUuid), [HttpResponseCode::NOT_FOUND->value], 'The reports the operator submitted should be deleted with it');
            $this->expectRequestFailure(fn() => $this->client->getBlacklistRecord($blacklistUuid), [HttpResponseCode::NOT_FOUND->value], 'The blacklist records of the operator should be deleted with it');
            $this->assertNull($this->client->getReport($assigned['report'])->getAssignedOperator());

            // Entities are not owned by the operator that pushed them
            $this->assertEquals($entityUuid, $this->client->getEntityRecord($entityUuid)->getUuid());
        }

        public function testListOperatorsByCategory(): void
        {
            $enabledUuid = $this->createOperator('op_cat_enabled');
            $disabledUuid = $this->createOperator('op_cat_disabled');
            $this->client->disableOperator($disabledUuid);

            $enabled = $this->client->listOperators(1, 1000, 'ENABLED');
            $this->assertContains($enabledUuid, self::uuids($enabled));
            $this->assertNotContains($disabledUuid, self::uuids($enabled));
            foreach ($enabled as $operator)
            {
                $this->assertFalse($operator->isDisabled());
            }

            $disabled = $this->client->listOperators(1, 1000, 'DISABLED');
            $this->assertContains($disabledUuid, self::uuids($disabled));
            $this->assertNotContains($enabledUuid, self::uuids($disabled));
            foreach ($disabled as $operator)
            {
                $this->assertTrue($operator->isDisabled());
            }
        }

        public function testListOperatorsSorting(): void
        {
            $created = [];
            foreach (['z-operator-sort', 'a-operator-sort', 'm-operator-sort'] as $prefix)
            {
                $created[] = $this->createOperator($prefix);
            }

            $byName = array_values(array_filter($this->client->listOperators(1, 1000, null, 'name', 'ASC'), fn(OperatorRecord $operator) => in_array($operator->getUuid(), $created, true)));
            $this->assertSame([$created[1], $created[2], $created[0]], self::uuids($byName));

            $enabledByName = array_values(array_filter($this->client->listOperators(1, 1000, 'ENABLED', 'name', 'ASC'), fn(OperatorRecord $operator) => in_array($operator->getUuid(), $created, true)));
            $this->assertSame([$created[1], $created[2], $created[0]], self::uuids($enabledByName));

            $byCreated = array_values(array_intersect(self::uuids($this->client->listOperators(1, 1000, null, 'created', 'DESC')), $created));
            $this->assertSame(array_reverse($created), $byCreated);
        }

        public function testListOperatorsFallbacksAndCase(): void
        {
            $this->createOperator('op_fallback');

            $default = self::uuids($this->client->listOperators(1, 10));
            $this->assertNotEmpty($default);
            $this->assertSame($default, self::uuids($this->client->listOperators(1, 10, 'NONEXISTENT_CATEGORY')), 'An invalid category falls back to no category');
            $this->assertSame($default, self::uuids($this->client->listOperators(1, 10, null, 'nonexistent_column')), 'An invalid sort field falls back to the default order');

            $upper = self::uuids($this->client->listOperators(1, 10, 'ENABLED'));
            foreach (['enabled', 'Enabled'] as $category)
            {
                $this->assertSame($upper, self::uuids($this->client->listOperators(1, 10, $category)), $category);
            }
        }
    }
