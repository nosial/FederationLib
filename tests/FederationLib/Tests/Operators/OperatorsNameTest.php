<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\OperatorRecord;
    use InvalidArgumentException;

    /**
     * Renaming operators (Update Operator Name)
     */
    class OperatorsNameTest extends ServerTestCase
    {
        private function createOperator(string $prefix): string
        {
            $operatorUuid = $this->client->createOperator(self::uniqueName($prefix))->getUuid();
            $this->createdOperators[] = $operatorUuid;
            return $operatorUuid;
        }

        private static function uniqueName(string $prefix): string
        {
            return substr(uniqid($prefix . '_'), 0, 32);
        }

        public function testRenameOperator(): void
        {
            $operatorUuid = $this->createOperator('rename');
            $this->client->setManagementPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $before = $this->client->getOperator($operatorUuid);

            sleep(1);
            $newName = self::uniqueName('renamed');
            $this->client->updateOperatorName($operatorUuid, $newName);

            // A new client reads the renamed record, a cached record of the old name is not served
            $after = new FederationClient(getenv('SERVER_ENDPOINT'), $this->rootToken())->getOperator($operatorUuid);
            $this->assertEquals($newName, $after->getName());
            $this->assertGreaterThan($before->getUpdated(), $after->getUpdated(), 'The updated timestamp should advance');
            $this->assertSame($before->hasClientPermissions(), $after->hasClientPermissions());
            $this->assertSame($before->hasOperatorPermissions(), $after->hasOperatorPermissions());
            $this->assertSame($before->hasManagementPermissions(), $after->hasManagementPermissions());
            $this->assertSame($before->isDisabled(), $after->isDisabled());

            $listed = array_values(array_filter($this->client->listOperators(1, 1000), fn(OperatorRecord $operator) => $operator->getUuid() === $operatorUuid));
            $this->assertCount(1, $listed);
            $this->assertEquals($newName, $listed[0]->getName());
        }

        public function testRenamingRequiresOperatorPermissions(): void
        {
            $targetUuid = $this->createOperator('rename_target');

            $this->expectRequestFailure(fn() => new FederationClient(getenv('SERVER_ENDPOINT'))->updateOperatorName($targetUuid, 'new-name'), [HttpResponseCode::UNAUTHORIZED->value], 'An anonymous client should not rename operators');
            $this->expectRequestFailure(fn() => $this->createLimitedOperator('rename_client', client: true)->updateOperatorName($targetUuid, 'new-name'), [HttpResponseCode::FORBIDDEN->value], 'A client should not rename operators');
            $this->expectRequestFailure(fn() => $this->createLimitedOperator('rename_management', management: true)->updateOperatorName($targetUuid, 'new-name'), [HttpResponseCode::FORBIDDEN->value], 'A manager without operator permissions should not rename operators');

            $disabled = $this->createLimitedOperator('rename_disabled', operator: true);
            $this->client->disableOperator($disabled->getSelf()->getUuid());
            $this->expectRequestFailure(fn() => $disabled->updateOperatorName($targetUuid, 'new-name'), [HttpResponseCode::FORBIDDEN->value], 'A disabled operator should not rename operators');

            // Operator permissions allow renaming another operator, and the operator itself
            $operator = $this->createLimitedOperator('rename_operator', operator: true);
            $otherName = self::uniqueName('cross_renamed');
            $operator->updateOperatorName($targetUuid, $otherName);
            $this->assertEquals($otherName, $this->client->getOperator($targetUuid)->getName());

            $selfUuid = $operator->getSelf()->getUuid();
            $selfName = self::uniqueName('self_renamed');
            $operator->updateOperatorName($selfUuid, $selfName);
            $this->assertEquals($selfName, $this->client->getOperator($selfUuid)->getName());
        }

        public function testBuiltinOperatorsCannotBeRenamed(): void
        {
            $operators = $this->client->listOperators(1, 1000);
            $builtin = array_values(array_filter($operators, fn(OperatorRecord $operator) => in_array($operator->getName(), ['root', 'system'], true)));
            $this->assertCount(2, $builtin, 'The server should have the root and the system operator');

            $manager = $this->createLimitedOperator('builtin_rename', operator: true, management: true);
            foreach ($builtin as $operator)
            {
                $this->expectRequestFailure(
                    fn() => $manager->updateOperatorName($operator->getUuid(), self::uniqueName('builtin')),
                    [HttpResponseCode::FORBIDDEN->value],
                    'The ' . $operator->getName() . ' operator should not be renamed'
                );
                $this->assertEquals($operator->getName(), $this->client->getOperator($operator->getUuid())->getName());
            }
        }

        public function testRenameToTakenNameConflicts(): void
        {
            $nameA = self::uniqueName('taken_a');
            $this->createdOperators[] = $this->client->createOperator($nameA)->getUuid();
            $operatorBUuid = $this->createOperator('taken_b');
            $nameB = $this->client->getOperator($operatorBUuid)->getName();

            $this->expectRequestFailure(fn() => $this->client->updateOperatorName($operatorBUuid, $nameA), [HttpResponseCode::CONFLICT->value], 'A name in use should not be taken');
            $this->assertEquals($nameB, $this->client->getOperator($operatorBUuid)->getName(), 'The name should be preserved after a failed update');
        }

        public function testRenameValidation(): void
        {
            $operatorUuid = $this->createOperator('rename_validation');

            foreach ([fn() => $this->client->updateOperatorName($operatorUuid, ''), fn() => $this->client->updateOperatorName('', 'new-name')] as $index => $call)
            {
                try
                {
                    $call();
                    $this->fail("Call $index should have been rejected before reaching the server");
                }
                catch (InvalidArgumentException)
                {
                    $this->addToAssertionCount(1);
                }
            }

            $this->expectRequestFailure(fn() => $this->client->updateOperatorName($operatorUuid, str_repeat('a', 33)), [HttpResponseCode::BAD_REQUEST->value], 'A name longer than 32 characters should be rejected');
            $this->expectRequestFailure(fn() => $this->client->updateOperatorName($this->randomUuid(), 'new-name'), [HttpResponseCode::NOT_FOUND->value], 'An unknown operator should not be found');

            [$code] = $this->rawRequest('PATCH', 'operators/' . $operatorUuid . '/update-name', 'invalid_token', json_encode(['name' => 'new-name']));
            $this->assertSame(HttpResponseCode::BAD_REQUEST->value, $code, 'A malformed access token should be rejected');
        }
    }
