<?php

    namespace FederationLib\Tests\AuditLogs;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\Categories\AuditLogCategory;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\ServerTestCase;
    use FederationLib\Objects\AuditLog;
    use InvalidArgumentException;

    /**
     * Client to server tests of the audit log search
     */
    class AuditLogsSearchTest extends ServerTestCase
    {
        /**
         * Creates an operator with a unique name and returns the name
         */
        private function createNamedOperator(string $name): string
        {
            $this->createdOperators[] = $this->client->createOperator($name)->getUuid();
            return $name;
        }

        /**
         * Returns the entry of the given type from the results, or null if there is none
         *
         * @param AuditLog[] $results
         */
        private function findEntry(array $results, AuditLogType $type, callable $matches): ?AuditLog
        {
            foreach ($results as $result)
            {
                if ($result->getType() === $type && $matches($result))
                {
                    return $result;
                }
            }

            return null;
        }

        public function testSearchAuditLogsFindsOperatorCreation(): void
        {
            $operatorName = $this->createNamedOperator('auditsearch_' . uniqid());

            $results = $this->client->searchAuditLogs($operatorName);
            $this->assertNotEmpty($results);
            $this->assertContainsOnlyInstancesOf(AuditLog::class, $results);

            $entry = $this->findEntry($results, AuditLogType::OPERATOR_CREATED, fn(AuditLog $log) => str_contains($log->getMessage(), $operatorName));
            $this->assertNotNull($entry, 'The creation of the operator should be found by its name');

            // The entry found by the search is the same entry the server returns by its UUID
            $record = $this->client->getAuditLogRecord($entry->getUuid());
            $this->assertEquals($entry->getMessage(), $record->getMessage());
            $this->assertEquals($entry->getType(), $record->getType());
            $this->assertEquals($entry->getTimestamp(), $record->getTimestamp());
        }

        public function testSearchAuditLogsIsPublicByDefault(): void
        {
            $operatorName = $this->createNamedOperator('auditpublic_' . uniqid());

            $anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
            $results = $anonymous->searchAuditLogs($operatorName);
            $this->assertNotNull(
                $this->findEntry($results, AuditLogType::OPERATOR_CREATED, fn(AuditLog $log) => str_contains($log->getMessage(), $operatorName)),
                'Audit logs are public by default, an anonymous client should find the entry by its message'
            );
        }

        public function testSearchAuditLogsByEntryUuid(): void
        {
            $operatorName = $this->createNamedOperator('audituuid_' . uniqid());
            $entry = $this->findEntry($this->client->searchAuditLogs($operatorName), AuditLogType::OPERATOR_CREATED, fn() => true);
            $this->assertNotNull($entry);

            $results = $this->client->searchAuditLogs($entry->getUuid());
            $this->assertContains($entry->getUuid(), array_map(fn(AuditLog $log) => $log->getUuid(), $results));
        }

        public function testExtendedSearchMatchesRelatedUuidsOnlyWhenAuthenticated(): void
        {
            $submitter = $this->createLimitedOperator('audit_extended', client: true);
            $entityUuid = $this->createSecurityEntity();
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, client: $submitter);

            // The submission entry does not mention the evidence UUID in its message, only in its evidence column
            $authenticatedEntry = $this->findEntry(
                $this->client->searchAuditLogs($evidenceUuid, 1, 50),
                AuditLogType::EVIDENCE_SUBMITTED,
                fn(AuditLog $log) => $log->getEvidenceUuid() === $evidenceUuid
            );
            $this->assertNotNull($authenticatedEntry, 'An authenticated operator should find the entry by its related evidence UUID');
            $this->assertEquals($submitter->getSelf()->getUuid(), $authenticatedEntry->getOperatorUuid());
            $this->assertEquals($entityUuid, $authenticatedEntry->getEntityUuid());
            $this->assertStringNotContainsString($evidenceUuid, $authenticatedEntry->getMessage());

            $anonymous = new FederationClient(getenv('SERVER_ENDPOINT'));
            $anonymousUuids = array_map(fn(AuditLog $log) => $log->getUuid(), $anonymous->searchAuditLogs($evidenceUuid, 1, 50));
            $this->assertNotContains($authenticatedEntry->getUuid(), $anonymousUuids, 'The extended search should not apply to anonymous clients');
        }

        public function testSearchAuditLogsCategoryFilter(): void
        {
            $operatorName = $this->createNamedOperator('auditcat_' . uniqid());

            $operatorEvents = $this->client->searchAuditLogs($operatorName, category: AuditLogCategory::OPERATOR_EVENTS->value);
            $this->assertNotNull($this->findEntry($operatorEvents, AuditLogType::OPERATOR_CREATED, fn() => true));
            foreach ($operatorEvents as $entry)
            {
                $this->assertSame(AuditLogCategory::OPERATOR_EVENTS, $entry->getType()->getCategory());
            }

            // The category is case-insensitive
            $lowercaseEvents = $this->client->searchAuditLogs($operatorName, category: strtolower(AuditLogCategory::OPERATOR_EVENTS->value));
            $this->assertEqualsCanonicalizing(
                array_map(fn(AuditLog $log) => $log->getUuid(), $operatorEvents),
                array_map(fn(AuditLog $log) => $log->getUuid(), $lowercaseEvents)
            );

            $evidenceEvents = $this->client->searchAuditLogs($operatorName, category: AuditLogCategory::EVIDENCE_EVENTS->value);
            $this->assertNull($this->findEntry($evidenceEvents, AuditLogType::OPERATOR_CREATED, fn() => true));
        }

        public function testSearchAuditLogsPagination(): void
        {
            $marker = 'auditpage' . uniqid();
            for ($i = 0; $i < 3; $i++)
            {
                $this->createNamedOperator($marker . '_' . $i);
            }

            $allResults = $this->client->searchAuditLogs($marker, 1, 50, AuditLogCategory::OPERATOR_EVENTS->value);
            $this->assertCount(3, $allResults);

            $firstPage = array_map(fn(AuditLog $log) => $log->getUuid(), $this->client->searchAuditLogs($marker, 1, 2, AuditLogCategory::OPERATOR_EVENTS->value));
            $secondPage = array_map(fn(AuditLog $log) => $log->getUuid(), $this->client->searchAuditLogs($marker, 2, 2, AuditLogCategory::OPERATOR_EVENTS->value));
            $thirdPage = $this->client->searchAuditLogs($marker, 3, 2, AuditLogCategory::OPERATOR_EVENTS->value);

            $this->assertCount(2, $firstPage);
            $this->assertCount(1, $secondPage);
            $this->assertEmpty($thirdPage);
            $this->assertEmpty(array_intersect($firstPage, $secondPage), 'Pages must not repeat entries');
            $this->assertEqualsCanonicalizing(array_map(fn(AuditLog $log) => $log->getUuid(), $allResults), array_merge($firstPage, $secondPage));
        }

        public function testSearchAuditLogsLikeWildcardsAreLiteral(): void
        {
            $operatorName = $this->createNamedOperator('auditlike_' . uniqid());

            // "%" and "_" are escaped by the server, a query of wildcards must not match every entry
            $this->assertEmpty($this->client->searchAuditLogs('%%' . uniqid() . '%%'));
            $this->assertNull($this->findEntry(
                $this->client->searchAuditLogs(str_replace('_', '%', $operatorName)),
                AuditLogType::OPERATOR_CREATED,
                fn(AuditLog $log) => str_contains($log->getMessage(), $operatorName)
            ));
        }

        public function testSearchAuditLogsRejectsShortQueries(): void
        {
            $token = getenv('SERVER_ACCESS_TOKEN');

            [$missingCode] = $this->rawRequest('GET', 'audit/search', $token);
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $missingCode);

            [$shortCode] = $this->rawRequest('GET', 'audit/search?q=a', $token);
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $shortCode);

            // Whitespace does not count towards the length of the query
            [$paddedCode] = $this->rawRequest('GET', 'audit/search?q=' . rawurlencode('  a  '), $token);
            $this->assertEquals(HttpResponseCode::BAD_REQUEST->value, $paddedCode);

            $this->expectException(InvalidArgumentException::class);
            $this->client->searchAuditLogs('a');
        }

        public function testSearchAuditLogsValidatesPaginationClientSide(): void
        {
            foreach ([fn() => $this->client->searchAuditLogs('query', 0), fn() => $this->client->searchAuditLogs('query', 1, 0)] as $index => $call)
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
    }
