<?php

    namespace FederationLib\Tests\AuditLogs;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\AuditLog;
    use InvalidArgumentException;

    class AuditLogsTest extends ServerTestCase
    {
        /**
         * Creates an operator with client permissions that pushes the given number of entities
         *
         * @return array{0: string, 1: string[]} The UUID of the operator and the UUIDs of the entities it pushed
         */
        private function createActivity(int $entities = 1): array
        {
            $operator = $this->createLimitedOperator('audit_actor', client: true);
            $entityUuids = [];
            for ($i = 0; $i < $entities; $i++)
            {
                $entityUuids[] = $this->createSecurityEntity($operator);
            }

            return [$operator->getSelf()->getUuid(), $entityUuids];
        }

        /**
         * @param AuditLog[] $logs
         * @return string[]
         */
        private static function uuids(array $logs): array
        {
            return array_map(fn(AuditLog $log) => $log->getUuid(), $logs);
        }

        public function testListAuditLogsIncludesNewEntries(): void
        {
            $before = time();
            [$operatorUuid, [$entityUuid]] = $this->createActivity();

            $entries = array_values(array_filter(
                $this->client->listAuditLogs(1, 100),
                fn(AuditLog $log) => $log->getType() === AuditLogType::ENTITY_PUSHED && $log->getEntityUuid() === $entityUuid
            ));

            $this->assertCount(1, $entries, 'The push of the entity should be recorded once');
            $this->assertEquals($operatorUuid, $entries[0]->getOperatorUuid());
            $this->assertNotEmpty($entries[0]->getMessage());
            $this->assertGreaterThanOrEqual($before, $entries[0]->getTimestamp());
            $this->assertLessThanOrEqual(time(), $entries[0]->getTimestamp());
        }

        public function testAuditLogEntryRetrievableByUuid(): void
        {
            [$operatorUuid] = $this->createActivity();

            $logs = $this->client->listOperatorAuditLogs($operatorUuid);
            $this->assertNotEmpty($logs);

            foreach ($logs as $log)
            {
                $retrieved = $this->client->getAuditLogRecord($log->getUuid());
                $this->assertEquals($log->getUuid(), $retrieved->getUuid());
                $this->assertEquals($log->getType(), $retrieved->getType());
                $this->assertEquals($log->getMessage(), $retrieved->getMessage());
                $this->assertEquals($log->getTimestamp(), $retrieved->getTimestamp());
                $this->assertEquals($log->getOperatorUuid(), $retrieved->getOperatorUuid());
                $this->assertEquals($log->getEntityUuid(), $retrieved->getEntityUuid());
            }
        }

        public function testListAuditLogsPagination(): void
        {
            [$operatorUuid] = $this->createActivity(4);

            foreach ([
                'all' => fn(int $page) => $this->client->listAuditLogs($page, 2),
                'operator' => fn(int $page) => $this->client->listOperatorAuditLogs($operatorUuid, $page, 2),
            ] as $listing => $list)
            {
                $pageOne = self::uuids($list(1));
                $pageTwo = self::uuids($list(2));

                $this->assertCount(2, $pageOne, $listing);
                $this->assertCount(2, $pageTwo, $listing);
                $this->assertSame([], array_intersect($pageOne, $pageTwo), "The pages of the $listing listing should not overlap");
            }
        }

        public function testClientValidatesArguments(): void
        {
            $operatorUuid = $this->randomUuid();
            $calls = [
                fn() => $this->client->listAuditLogs(-1),
                fn() => $this->client->listAuditLogs(1, -1),
                fn() => $this->client->getAuditLogRecord(''),
                fn() => $this->client->listOperatorAuditLogs(''),
                fn() => $this->client->listOperatorAuditLogs($operatorUuid, -1),
                fn() => $this->client->listOperatorAuditLogs($operatorUuid, 1, -1),
            ];

            foreach ($calls as $index => $call)
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
        }

        public function testUnknownRecordsAreNotFound(): void
        {
            $this->expectRequestFailure(
                fn() => $this->client->getAuditLogRecord($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'An unknown audit log entry should not be found'
            );
            $this->expectRequestFailure(
                fn() => $this->client->listOperatorAuditLogs($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'The audit log of an unknown operator should not be found'
            );
        }
    }
