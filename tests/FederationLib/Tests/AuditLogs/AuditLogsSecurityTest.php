<?php

    namespace FederationLib\Tests\AuditLogs;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\AuditLog;

    class AuditLogsSecurityTest extends ServerTestCase
    {
        public function testAnonymousClientsOnlySeePublicEntries(): void
        {
            // OPERATOR_CREATED is public by default, OPERATOR_UPDATED (a change of permissions) is not
            $operatorName = substr(uniqid('audit_public_'), 0, 32);
            $operatorUuid = $this->client->createOperator($operatorName)->getUuid();
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);

            // Both entries name the operator
            $mentionsOperator = fn(AuditLog $log) => str_contains($log->getMessage(), $operatorName);
            $rootEntries = array_filter($this->client->listAuditLogs(1, 100), $mentionsOperator);
            $created = array_values(array_filter($rootEntries, fn(AuditLog $log) => $log->getType() === AuditLogType::OPERATOR_CREATED));
            $updated = array_values(array_filter($rootEntries, fn(AuditLog $log) => $log->getType() === AuditLogType::OPERATOR_UPDATED));
            $this->assertCount(1, $created);
            $this->assertNotEmpty($updated);

            $anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
            $anonymousEntries = array_filter($anonymous->listAuditLogs(1, 100), $mentionsOperator);
            $this->assertSame([$created[0]->getUuid()], array_values(array_map(fn(AuditLog $log) => $log->getUuid(), $anonymousEntries)));
            $this->assertEquals($created[0]->getMessage(), $anonymous->getAuditLogRecord($created[0]->getUuid())->getMessage());

            // A private entry is not disclosed, not even its existence
            $this->expectRequestFailure(
                fn() => $anonymous->getAuditLogRecord($updated[0]->getUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'A private audit log entry should not be found by an anonymous client'
            );
            $this->expectRequestFailure(
                fn() => $anonymous->getAuditLogRecord($this->randomUuid()),
                [HttpResponseCode::NOT_FOUND->value],
                'An unknown audit log entry should not be found'
            );
        }

        public function testSecurityOperatorAuditLogsOfOthersFollowPublicAccess(): void
        {
            $actor = $this->createLimitedOperator('audit_actor', operator: true);
            $victim = $this->createLimitedOperator('audit_victim', management: true);
            $snooper = $this->createLimitedOperator('audit_snooper', client: true);
            $actorUuid = $actor->getSelf()->getUuid();

            $actor->setManagementPermissions($victim->getSelf()->getUuid(), false);
            $this->createdOperators[] = $actor->createOperator(substr(uniqid('audit_created_'), 0, 32))->getUuid();

            $snooperEntries = $snooper->listOperatorAuditLogs($actorUuid);
            $anonymousEntries = new FederationClient(getenv('SERVER_ENDPOINT'))->listOperatorAuditLogs($actorUuid);

            $snooperTypes = array_map(fn($entry) => $entry->getType(), $snooperEntries);
            $this->assertContains(AuditLogType::OPERATOR_CREATED, $snooperTypes);
            $this->assertNotContains(AuditLogType::OPERATOR_UPDATED, $snooperTypes, 'Private entries of another operator must not be listed');
            $this->assertEquals(
                array_map(fn($entry) => $entry->getUuid(), $anonymousEntries),
                array_map(fn($entry) => $entry->getUuid(), $snooperEntries)
            );
            foreach ($snooperEntries as $entry)
            {
                $this->assertEquals($actorUuid, $entry->getOperatorUuid());
            }

            // The actor itself receives all of its entries
            $actorTypes = array_map(fn($entry) => $entry->getType(), $actor->listOperatorAuditLogs($actorUuid));
            $this->assertContains(AuditLogType::OPERATOR_UPDATED, $actorTypes);
            $this->assertContains(AuditLogType::OPERATOR_CREATED, $actorTypes);
        }
    }
