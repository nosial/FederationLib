<?php

    namespace FederationLib\Tests\AuditLogs;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\AuditLog;

    class AuditLogsLogicTest extends ServerTestCase
    {
        private const array OPERATOR_EVENTS = [
            AuditLogType::OPERATOR_CREATED,
            AuditLogType::OPERATOR_DELETED,
            AuditLogType::OPERATOR_DISABLED,
            AuditLogType::OPERATOR_ENABLED,
            AuditLogType::OPERATOR_UPDATED,
        ];

        private const array ENTITY_EVENTS = [
            AuditLogType::ENTITY_DELETED,
            AuditLogType::ENTITY_BLACKLISTED,
            AuditLogType::ENTITY_PUSHED,
            AuditLogType::ENTITY_UPDATED,
        ];

        private const array EVIDENCE_EVENTS = [
            AuditLogType::EVIDENCE_SUBMITTED,
            AuditLogType::EVIDENCE_UPDATED,
            AuditLogType::EVIDENCE_DELETED,
        ];

        /**
         * @param AuditLog[] $logs
         * @return string[]
         */
        private static function uuids(array $logs): array
        {
            return array_map(fn(AuditLog $log) => $log->getUuid(), $logs);
        }

        /**
         * Returns the entries of the given type that match, in the order listed
         *
         * @param AuditLog[] $logs
         * @return AuditLog[]
         */
        private static function entries(array $logs, AuditLogType $type, callable $matches): array
        {
            return array_values(array_filter($logs, fn(AuditLog $log) => $log->getType() === $type && $matches($log)));
        }

        public function testEntityPushIsRecordedWithItsActor(): void
        {
            $operator = $this->createLimitedOperator('audit_push', client: true);
            $operatorUuid = $operator->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity($operator);

            $pushes = self::entries($this->client->listOperatorAuditLogs($operatorUuid), AuditLogType::ENTITY_PUSHED, fn(AuditLog $log) => $log->getEntityUuid() === $entityUuid);
            $this->assertCount(1, $pushes);
            $this->assertEquals($operatorUuid, $pushes[0]->getOperatorUuid());

            // The operator's audit log only lists the operator's own entries
            foreach ($this->client->listOperatorAuditLogs($operatorUuid) as $log)
            {
                $this->assertEquals($operatorUuid, $log->getOperatorUuid());
            }
        }

        public function testEvidenceAndBlacklistActionsAreRecorded(): void
        {
            $operator = $this->createLimitedOperator('audit_actions', management: true);
            $operatorUuid = $operator->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity($operator);
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $operator);

            $blacklistUuid = $operator->blacklistEntity($entityUuid, null, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            $operator->liftBlacklistRecord($blacklistUuid);

            $logs = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100);

            $this->assertCount(1, self::entries($logs, AuditLogType::EVIDENCE_SUBMITTED, fn(AuditLog $log) => $log->getEvidenceUuid() === $evidenceUuid && $log->getEntityUuid() === $entityUuid));
            $this->assertCount(1, self::entries($logs, AuditLogType::ENTITY_BLACKLISTED, fn(AuditLog $log) => $log->getBlacklistUuid() === $blacklistUuid && $log->getEntityUuid() === $entityUuid));
            $this->assertCount(1, self::entries($logs, AuditLogType::BLACKLIST_LIFTED, fn(AuditLog $log) => $log->getBlacklistUuid() === $blacklistUuid && $log->getEntityUuid() === $entityUuid));
        }

        public function testAuditLogRemainsAfterEntityDeletion(): void
        {
            $entityUuid = $this->createSecurityEntity();
            $logsBefore = $this->client->listEntityAuditLogs($entityUuid);
            $this->assertNotEmpty($logsBefore);

            $this->client->deleteEntity($entityUuid);
            $this->removeFromCleanup($this->createdEntities, $entityUuid);

            // The entries are kept, the database no longer refers to the deleted entity
            foreach ($logsBefore as $log)
            {
                $retrieved = $this->client->getAuditLogRecord($log->getUuid());
                $this->assertEquals($log->getType(), $retrieved->getType());
                $this->assertEquals($log->getMessage(), $retrieved->getMessage());
                $this->assertNull($retrieved->getEntityUuid());
            }
        }

        public function testOperatorAuditLogsSortByTimestamp(): void
        {
            $operator = $this->createLimitedOperator('audit_sort', client: true);
            $operatorUuid = $operator->getSelf()->getUuid();
            for ($i = 0; $i < 3; $i++)
            {
                $this->createSecurityEntity($operator);
            }

            $ascending = self::uuids($this->client->listOperatorAuditLogs($operatorUuid, 1, 100, null, 'timestamp', 'ASC'));
            $descending = self::uuids($this->client->listOperatorAuditLogs($operatorUuid, 1, 100, null, 'timestamp', 'DESC'));

            $this->assertGreaterThanOrEqual(3, count($ascending));
            $this->assertSame(array_reverse($ascending), $descending);

            // Sorting by an unknown field falls back to the default order
            $this->assertSame(
                self::uuids($this->client->listOperatorAuditLogs($operatorUuid, 1, 100)),
                self::uuids($this->client->listOperatorAuditLogs($operatorUuid, 1, 100, null, 'bogus_field'))
            );
        }

        public function testListAuditLogsByCategory(): void
        {
            $operator = $this->createLimitedOperator('audit_category', client: true);
            $operatorUuid = $operator->getSelf()->getUuid();
            $entityUuid = $this->createSecurityEntity($operator);

            $operatorEvents = $this->client->listAuditLogs(1, 100, 'OPERATOR_EVENTS');
            $this->assertNotEmpty(self::entries($operatorEvents, AuditLogType::OPERATOR_CREATED, fn(AuditLog $log) => str_contains($log->getMessage(), $operatorUuid)));
            foreach ($operatorEvents as $log)
            {
                $this->assertContains($log->getType(), self::OPERATOR_EVENTS);
            }

            $entityEvents = $this->client->listAuditLogs(1, 100, 'ENTITY_EVENTS');
            $this->assertCount(1, self::entries($entityEvents, AuditLogType::ENTITY_PUSHED, fn(AuditLog $log) => $log->getEntityUuid() === $entityUuid));
            foreach ($entityEvents as $log)
            {
                $this->assertContains($log->getType(), self::ENTITY_EVENTS);
            }
        }

        public function testListAuditLogsCategoryWithSort(): void
        {
            $operator = $this->createLimitedOperator('audit_category_sort', client: true);
            $operatorUuid = $operator->getSelf()->getUuid();
            for ($i = 0; $i < 3; $i++)
            {
                $this->createSecurityEntity($operator);
            }

            $ascending = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100, 'ENTITY_EVENTS', 'timestamp', 'ASC');
            $descending = $this->client->listOperatorAuditLogs($operatorUuid, 1, 100, 'ENTITY_EVENTS', 'timestamp', 'DESC');

            $this->assertCount(3, $ascending);
            $this->assertSame(array_reverse(self::uuids($ascending)), self::uuids($descending));
        }

        public function testListAuditLogsCategoryInvalidFallsBackAndIsCaseInsensitive(): void
        {
            $this->createSecurityEntity($this->createLimitedOperator('audit_category_ci', client: true));

            $default = self::uuids($this->client->listAuditLogs(1, 10));
            $this->assertNotEmpty($default);
            $this->assertSame($default, self::uuids($this->client->listAuditLogs(1, 10, 'BOGUS_CATEGORY')));

            $upper = self::uuids($this->client->listAuditLogs(1, 10, 'OPERATOR_EVENTS'));
            $this->assertNotEmpty($upper);
            foreach (['operator_events', 'Operator_Events'] as $category)
            {
                $this->assertSame($upper, self::uuids($this->client->listAuditLogs(1, 10, $category)), $category);
            }
        }

        public function testListEntityAuditLogsByCategory(): void
        {
            $operator = $this->createLimitedOperator('audit_entity_category', management: true);
            $entityUuid = $this->createSecurityEntity($operator);
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $operator);

            $entityEvents = $this->client->listEntityAuditLogs($entityUuid, 1, 100, 'ENTITY_EVENTS');
            $this->assertCount(1, self::entries($entityEvents, AuditLogType::ENTITY_PUSHED, fn() => true));
            foreach ($entityEvents as $log)
            {
                $this->assertContains($log->getType(), self::ENTITY_EVENTS);
                $this->assertEquals($entityUuid, $log->getEntityUuid());
            }

            $evidenceEvents = $this->client->listEntityAuditLogs($entityUuid, 1, 100, 'EVIDENCE_EVENTS');
            $this->assertCount(1, self::entries($evidenceEvents, AuditLogType::EVIDENCE_SUBMITTED, fn(AuditLog $log) => $log->getEvidenceUuid() === $evidenceUuid));
            foreach ($evidenceEvents as $log)
            {
                $this->assertContains($log->getType(), self::EVIDENCE_EVENTS);
            }
        }

        public function testListEntityAuditLogsSortCategoryFallbackAndCase(): void
        {
            $operator = $this->createLimitedOperator('audit_entity_sort', management: true);
            $entityUuid = $this->createSecurityEntity($operator);
            $this->createSecurityEvidence($entityUuid, client: $operator);

            $ascending = self::uuids($this->client->listEntityAuditLogs($entityUuid, 1, 100, null, 'timestamp', 'ASC'));
            $descending = self::uuids($this->client->listEntityAuditLogs($entityUuid, 1, 100, null, 'timestamp', 'DESC'));
            $this->assertGreaterThanOrEqual(2, count($ascending));
            $this->assertSame(array_reverse($ascending), $descending);

            $default = self::uuids($this->client->listEntityAuditLogs($entityUuid, 1, 10));
            $this->assertSame($default, self::uuids($this->client->listEntityAuditLogs($entityUuid, 1, 10, 'BOGUS_CATEGORY')));

            $upper = self::uuids($this->client->listEntityAuditLogs($entityUuid, 1, 10, 'ENTITY_EVENTS'));
            $this->assertNotEmpty($upper);
            foreach (['entity_events', 'Entity_Events'] as $category)
            {
                $this->assertSame($upper, self::uuids($this->client->listEntityAuditLogs($entityUuid, 1, 10, $category)), $category);
            }
        }
    }
