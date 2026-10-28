<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\OperatorRecord;

    class OperatorsTest extends ServerTestCase
    {
        public function testCreateOperator(): void
        {
            $name = substr(uniqid('test_operator_'), 0, 32);
            $created = $this->client->createOperator($name);
            $this->createdOperators[] = $created->getUuid();

            // The access token is only returned when the operator is created, and authenticates it
            $this->assertSame(32, strlen($created->getAccessToken()));
            $this->assertEquals($created->getUuid(), new FederationClient(getenv('SERVER_ENDPOINT'), $created->getAccessToken())->getSelf()->getUuid());

            // A new operator has no permissions
            $record = $this->client->getOperator($created->getUuid());
            $this->assertEquals($name, $record->getName());
            $this->assertFalse($record->hasManagementPermissions());
            $this->assertFalse($record->hasOperatorPermissions());
            $this->assertFalse($record->hasClientPermissions());
            $this->assertFalse($record->isDisabled());
            $this->assertEmpty($record->getAccessToken());
        }

        public function testCreateOperatorRejectsInvalidName(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->createOperator(str_repeat('a', 256)),
                [HttpResponseCode::BAD_REQUEST->value],
                'An operator name that is too long should be rejected'
            );
        }

        public function testDeleteOperator(): void
        {
            $operatorUuid = $this->client->createOperator(substr(uniqid('deleted_'), 0, 32))->getUuid();
            $this->createdOperators[] = $operatorUuid;

            $this->client->deleteOperator($operatorUuid);
            $this->removeFromCleanup($this->createdOperators, $operatorUuid);

            $this->expectRequestFailure(fn() => $this->client->getOperator($operatorUuid), [HttpResponseCode::NOT_FOUND->value]);
        }

        public function testUnknownOperatorIsNotFound(): void
        {
            $uuid = $this->randomUuid();
            $this->expectRequestFailure(fn() => $this->client->getOperator($uuid), [HttpResponseCode::NOT_FOUND->value]);
            $this->expectRequestFailure(fn() => $this->client->deleteOperator($uuid), [HttpResponseCode::NOT_FOUND->value]);
        }

        public function testListOperators(): void
        {
            $created = [];
            for ($i = 0; $i < 3; $i++)
            {
                $created[] = $this->client->createOperator(substr(uniqid('listed_'), 0, 32))->getUuid();
                $this->createdOperators[] = end($created);
            }

            $listed = array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $this->client->listOperators(1, 1000));
            foreach ($created as $uuid)
            {
                $this->assertContains($uuid, $listed);
            }

            $pageOne = array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $this->client->listOperators(1, 2));
            $pageTwo = array_map(fn(OperatorRecord $operator) => $operator->getUuid(), $this->client->listOperators(2, 2));
            $this->assertCount(2, $pageOne);
            $this->assertNotEmpty($pageTwo);
            $this->assertSame([], array_intersect($pageOne, $pageTwo));
        }
    }
