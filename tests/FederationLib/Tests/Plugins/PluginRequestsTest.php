<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Plugins;

    use FederationLib\FederationClient;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * Integration tests of the plugin system against the server, the server must be running with the TestPlugin
     * installed and enabled (make test-env, see docker-compose.test.yml).
     */
    class PluginRequestsTest extends TestCase
    {
        private const string TEST_PLUGIN = 'net.nosial.test_plugin';

        protected function setUp(): void
        {
            $response = $this->request('GET', '/test-plugin/ping');
            if($response['code'] !== 200)
            {
                $this->fail(sprintf('The server at %s does not have the TestPlugin enabled (HTTP %d), start the test environment with "make test-env"', getenv('SERVER_ENDPOINT'), $response['code']));
            }
        }

        protected function tearDown(): void
        {
            Logger::unregisterHandlers();
        }

        public function testPluginRouteHasAccessToItsConfiguration(): void
        {
            $response = $this->request('GET', '/test-plugin/ping');

            $this->assertSame(200, $response['code']);
            $this->assertSame(self::TEST_PLUGIN, $response['body']['plugin']);
            $this->assertSame('1.0.0', $response['body']['version']);
            // The plugin's own configuration, set by TEST_PLUGIN_GREETING (docker-compose.test.yml)
            $this->assertSame('Hello from the test environment', $response['body']['greeting']);
            // Default value of the plugin's own configuration
            $this->assertSame(42, $response['body']['nested_value']);
            $this->assertSame('default', $response['body']['missing_value']);
            $this->assertSame('Hello from the test environment', $response['body']['configuration']['greeting']);
            $this->assertNull($response['body']['execution_priority']);
        }

        public function testPluginRouteWithPathParameterAndMultipleMethods(): void
        {
            $response = $this->request('GET', '/test-plugin/echo/abc123', ['message' => 'hello']);
            $this->assertSame(200, $response['code']);
            $this->assertSame('GET', $response['body']['request_method']);
            $this->assertSame('/test-plugin/echo/abc123', $response['body']['path']);
            $this->assertSame('abc123', $response['body']['value']);
            $this->assertSame(['value' => 'abc123'], $response['body']['path_parameters']);
            $this->assertSame('hello', $response['body']['message']);

            $response = $this->request('POST', '/test-plugin/echo/xyz', null, ['message' => 'posted']);
            $this->assertSame(200, $response['code']);
            $this->assertSame('POST', $response['body']['request_method']);
            $this->assertSame('xyz', $response['body']['value']);
            $this->assertSame('posted', $response['body']['message']);
        }

        public function testPluginRouteRejectsUnsupportedMethodAndUnknownPath(): void
        {
            $response = $this->request('PATCH', '/test-plugin/echo/abc123');
            $this->assertSame(400, $response['code']);
            $this->assertFalse($response['body']['success']);

            // The wildcard PRE_REQUEST handler matches, but nothing handles the route so nothing is executed
            $response = $this->request('GET', '/test-plugin/does-not-exist');
            $this->assertSame(400, $response['code']);
            $this->assertArrayNotHasKey('x-test-plugin-wildcard', $response['headers']);
        }

        public function testPluginRouteCanRequireAuthentication(): void
        {
            $response = $this->request('GET', '/test-plugin/whoami');
            $this->assertSame(401, $response['code']);
            $this->assertFalse($response['body']['success']);

            $response = $this->request('GET', '/test-plugin/whoami', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertNotEmpty($response['body']['uuid']);
            $this->assertNotEmpty($response['body']['name']);

            $operator = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'))->getSelf();
            $this->assertSame($operator->getUuid(), $response['body']['uuid']);
        }

        public function testPluginRouteExceptionsBecomeErrorResponses(): void
        {
            $response = $this->request('GET', '/test-plugin/error');

            $this->assertSame(418, $response['code']);
            $this->assertFalse($response['body']['success']);
            $this->assertSame(418, $response['body']['code']);
            $this->assertStringContainsString('Test plugin error', $response['body']['message']);
        }

        public function testWildcardPreRequestHandlerDoesNotPreventTheRequest(): void
        {
            $response = $this->request('GET', '/test-plugin/ping');

            $this->assertSame(200, $response['code']);
            $this->assertSame('executed', $response['headers']['x-test-plugin-wildcard'] ?? null);
            $this->assertSame(self::TEST_PLUGIN, $response['body']['plugin']);
        }

        public function testPreRequestHandlerExecutesBeforeTheOriginalRequest(): void
        {
            $response = $this->request('GET', '/info');

            // The PRE_REQUEST handler was executed but did not prevent the original request handler
            $this->assertSame(200, $response['code']);
            $this->assertSame('executed', $response['headers']['x-test-plugin-pre-request'] ?? null);
            $this->assertArrayHasKey('name', $response['body']);
            $this->assertArrayHasKey('api_version', $response['body']);
            $this->assertArrayNotHasKey('intercepted', $response['body']);

            // FederationLib's own client is unaffected
            $this->assertNotEmpty(new FederationClient(getenv('SERVER_ENDPOINT'))->getServerInformation()->getServerName());
        }

        public function testPreRequestHandlerCanPreventTheOriginalRequestByResponding(): void
        {
            $response = $this->request('GET', '/info', ['test_plugin_intercept' => '1']);

            $this->assertSame(200, $response['code']);
            $this->assertTrue($response['body']['intercepted']);
            $this->assertSame(self::TEST_PLUGIN, $response['body']['plugin']);
            $this->assertSame('PRE_REQUEST', $response['body']['execution_priority']);
            // The original request handler was never executed
            $this->assertArrayNotHasKey('name', $response['body']);
            $this->assertArrayNotHasKey('api_version', $response['body']);
        }

        public function testPreRequestHandlerCanPreventTheOriginalRequestByThrowing(): void
        {
            $response = $this->request('GET', '/info', ['test_plugin_deny' => '1']);

            $this->assertSame(403, $response['code']);
            $this->assertFalse($response['body']['success']);
            $this->assertStringContainsString('Denied by the test plugin', $response['body']['message']);
            $this->assertArrayNotHasKey('api_version', $response['body']);
        }

        public function testPostRequestHandlerExecutesAfterTheRequest(): void
        {
            $this->resetPostRequestStats();

            $response = $this->request('GET', '/info');
            $this->assertSame(200, $response['code']);
            $this->assertArrayHasKey('api_version', $response['body']);

            $stats = $this->getPostRequestStats();
            $this->assertSame(1, $stats['count']);
            $this->assertSame(200, $stats['last_response_code']);
            $this->assertTrue($stats['last_response_sent']);
            $this->assertSame('/info', $stats['last_path']);
        }

        public function testPostRequestHandlerExecutesWhenPreRequestHandlerPreventsTheRequest(): void
        {
            $this->resetPostRequestStats();

            $this->assertSame(403, $this->request('GET', '/info', ['test_plugin_deny' => '1'])['code']);
            $stats = $this->getPostRequestStats();
            $this->assertSame(1, $stats['count']);
            $this->assertSame(403, $stats['last_response_code']);

            $this->assertSame(200, $this->request('GET', '/info', ['test_plugin_intercept' => '1'])['code']);
            $stats = $this->getPostRequestStats();
            $this->assertSame(2, $stats['count']);
            $this->assertSame(200, $stats['last_response_code']);
        }

        public function testPostRequestHandlerCannotAlterTheResponse(): void
        {
            $this->resetPostRequestStats();

            $response = $this->request('GET', '/info', ['test_plugin_post_respond' => '1']);

            // The response is the original response only, the POST_REQUEST handler's response was ignored
            $this->assertSame(200, $response['code']);
            $this->assertIsArray($response['body'], 'The response is not a single valid JSON document: ' . $response['raw']);
            $this->assertArrayHasKey('api_version', $response['body']);
            $this->assertStringNotContainsString('this must never be sent', $response['raw']);
            $this->assertSame(1, $this->getPostRequestStats()['count']);
        }

        // ---------------------------------------------------------------------------------------------------------
        // OVERRIDE
        // ---------------------------------------------------------------------------------------------------------

        public function testOverrideHandlerReplacesTheOriginalRequest(): void
        {
            $response = $this->request('GET', '/specification.json', null, null, getenv('SERVER_ACCESS_TOKEN'));

            $this->assertSame(200, $response['code']);
            $this->assertTrue($response['body']['overridden']);
            $this->assertSame(self::TEST_PLUGIN, $response['body']['plugin']);
            $this->assertSame('OVERRIDE', $response['body']['execution_priority']);
            $this->assertArrayNotHasKey('openapi', $response['body']);
        }

        public function testOverrideHandlerOnlyAffectsItsOwnRoute(): void
        {
            // /specification is handled by the same built-in handler, but only /specification.json is overridden
            $specification = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'))->getSpecification();

            $this->assertArrayHasKey('openapi', $specification);
            $this->assertArrayNotHasKey('overridden', $specification);
        }

        public function testContentScanWithoutTriggerWordsIsUnaffected(): void
        {
            $response = $this->scan(['text_content' => 'Hello world, nothing to see here']);
            $this->assertSame(200, $response['code']);
            $this->assertSame([], $this->getTestPluginScanResults($response['body']['scan_results']));
        }

        public function testContentScanEventHandlerAddsScanningRules(): void
        {
            $response = $this->scan(['text_content' => 'Hello test_plugin_penalty and test_plugin_bonus']);
            $this->assertSame(200, $response['code']);

            $scanResults = $response['body']['scan_results'];
            $this->assertEquals(['TEST_PLUGIN_PENALTY' => -20.0, 'TEST_PLUGIN_BONUS' => 5.0], $this->getTestPluginScanResults($scanResults));

            // The plugin's scanning rules contribute to the risk score like FederationLib's own scanning rules, using
            // the default scanning configuration of the test environment
            $expectedRiskScore = round(max(0.0, min(100.0, 50.0 - (array_sum($scanResults) * 2.3))), 2);
            $this->assertEqualsWithDelta($expectedRiskScore, $response['body']['risk_score'], 0.01);

            // The scan results remain valid for the client
            $scanned = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'))->scanContent('Hello test_plugin_penalty');
            $this->assertEquals(['TEST_PLUGIN_PENALTY' => -20.0], $scanned->getAdditionalScanResults());
            $this->assertEquals(-20.0, $scanned->getScanResults()['TEST_PLUGIN_PENALTY']);
        }

        public function testContentScanEventHandlerRejectsTheRequest(): void
        {
            $response = $this->scan([
                ['text_content' => 'An innocent message'],
                ['text_content' => 'This message contains test_plugin_reject and test_plugin_penalty'],
            ]);

            $this->assertSame(451, $response['code']);
            $this->assertFalse($response['body']['success']);
            $this->assertStringContainsString('The TestPlugin does not accept this content', $response['body']['message']);
            $this->assertArrayNotHasKey('scan_results', $response['body']);
        }

        public function testContentScanRejectionThroughTheClient(): void
        {
            try
            {
                new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'))->scanContent('test_plugin_reject');
                $this->fail('The content scan must be rejected');
            }
            catch(\FederationLib\Exceptions\RequestException $e)
            {
                $this->assertSame(451, $e->getCode());
            }
        }

        public function testFailedContentScanEventHandlerIsIgnored(): void
        {
            // The handler adds its scanning rules and then fails, the scan succeeds without any of them
            $response = $this->scan(['text_content' => 'test_plugin_penalty test_plugin_fail']);
            $this->assertSame(200, $response['code']);
            $this->assertSame([], $this->getTestPluginScanResults($response['body']['scan_results']));
        }

        public function testContentScanEventHandlerReceivesTheRequest(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $authorUuid = $client->pushEntity(uniqid('pluginauthor') . '.example.com');
            $mentionedHost = uniqid('pluginmention') . '.example.com';
            $mentionedUuid = $client->pushEntity($mentionedHost);

            $this->assertSame(200, $this->request('DELETE', '/test-plugin/content-scan-events', null, null, getenv('SERVER_ACCESS_TOKEN'))['code']);

            $response = $this->request('POST', '/scan', null, [
                'author' => $authorUuid,
                'evidence' => [
                    ['text_content' => 'test_plugin_inspect, visit ' . $mentionedHost, 'note' => 'a note', 'tag' => 'a_tag', 'confidential' => true, 'metadata' => ['source' => 'tests']],
                    ['note' => 'Evidence without text content'],
                    ['text_content' => 'A second message'],
                ],
                'top_k' => 2,
                'threshold' => 0.1,
            ], getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);

            $inspected = $this->request('GET', '/test-plugin/content-scan-events', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $inspected['code']);
            $inspected = $inspected['body'];

            $this->assertSame([
                ['text_content' => 'test_plugin_inspect, visit ' . $mentionedHost, 'note' => 'a note', 'tag' => 'a_tag', 'confidential' => true, 'metadata' => ['source' => 'tests']],
                ['text_content' => null, 'note' => 'Evidence without text content', 'tag' => null, 'confidential' => false, 'metadata' => null],
                ['text_content' => 'A second message', 'note' => null, 'tag' => null, 'confidential' => false, 'metadata' => null],
            ], $inspected['evidence']);
            $this->assertSame(['test_plugin_inspect, visit ' . $mentionedHost, 'A second message'], $inspected['text_contents']);
            $this->assertSame($authorUuid, $inspected['author_identifier']);
            $this->assertSame($authorUuid, $inspected['author_entity']);
            $this->assertContains($mentionedUuid, $inspected['resolved_entities']);
            $this->assertSame($client->getSelf()->getUuid(), $inspected['authenticated_operator']);
            $this->assertSame(2, $inspected['top_k']);
            $this->assertEquals(0.1, $inspected['threshold']);

            // FederationLib's own results are unaffected by the plugin
            $this->assertSame($authorUuid, $response['body']['author_entity']['entity']['uuid']);
        }

        public function testContentScanEventHandlerClassifiesTheContent(): void
        {
            $response = $this->scan([
                ['text_content' => 'An innocent message'],
                ['text_content' => 'Please classify this test_plugin_classify'],
            ]);
            $this->assertSame(200, $response['code']);

            // The plugin's MALICIOUS classification is the worst classification, so it determines the response's
            // classification regardless of any classification made by FederationLib itself
            $this->assertSame('MALICIOUS', $response['body']['classification']['classification_flag']);
            $this->assertLessThan(0.0, $response['body']['scan_results']['CLASSIFICATION_MALICIOUS']);

            $scanned = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'))->scanContent('test_plugin_classify');
            $this->assertSame(\FederationLib\Enums\ClassificationFlag::MALICIOUS, $scanned->getClassification()->getClassificationFlag());
        }

        public function testQueryEntityWithoutTriggerIsUnchanged(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginquery') . '.com', null, ['stored' => 'value']);

            $result = $client->queryEntity($entityUuid);
            $this->assertSame(['stored' => 'value'], $result->getEntityRecord()->getMetadata());
            $this->assertNull($result->getSuggestedAction());
        }

        public function testQueryEntityEnrichedByPlugin(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginquery') . '-test-plugin-enrich.com', null, ['stored' => 'value']);

            $metadata = $client->queryEntity($entityUuid)->getEntityRecord()->getMetadata();
            $this->assertSame('value', $metadata['stored']);
            $this->assertSame($entityUuid, $metadata['test_plugin_identifier']);
            $this->assertSame(0, $metadata['test_plugin_related']);
            $this->assertSame(0, $metadata['test_plugin_blacklists']);

            // Only the response is changed, the stored entity is not
            $this->assertSame(['stored' => 'value'], $client->getEntityRecord($entityUuid)->getMetadata());
        }

        public function testQueryEntitySuggestedActionOverriddenByPlugin(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginquery') . '-test-plugin-block.com');

            $response = $this->request('GET', '/entities/' . $entityUuid . '/query', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertSame('TEMPORARILY_BLOCK_ENTITY', $response['body']['suggested_action']);
            $this->assertSame(4102444800, $response['body']['suggested_lift_timestamp']);

            $result = $client->queryEntity($entityUuid);
            $this->assertSame(\FederationLib\Enums\SuggestedActionType::TEMPORARILY_BLOCK_ENTITY, $result->getSuggestedAction());
            $this->assertSame(4102444800, $result->getSuggestedLiftTimestamp());
        }

        public function testQueryEntityChangesOfFailedHandlerAreDiscarded(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginquery') . '-test-plugin-fail.com');

            $response = $this->request('GET', '/entities/' . $entityUuid . '/query', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertNull($response['body']['entity_record']['metadata']);
            $this->assertNull($response['body']['suggested_action']);
        }

        public function testQueryEntityRejectedByPlugin(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginquery') . '-test-plugin-reject.com');

            $response = $this->request('GET', '/entities/' . $entityUuid . '/query', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(451, $response['code']);
            $this->assertFalse($response['body']['success']);
            $this->assertStringContainsString('Rejected by the TestPlugin', $response['body']['message']);
            $this->assertArrayNotHasKey('entity_record', $response['body']);
        }

        public function testRecordChangeWhenAnEntityIsCreatedAndDeleted(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $this->resetRecordChangeEvents();

            $entityUuid = $client->pushEntity(uniqid('pluginentity') . '.example.com');
            $this->assertSame([['type' => 'ENTITY_CREATED', 'record_type' => 'ENTITY', 'uuid' => $entityUuid, 'record_exists' => true]], array_slice($this->getRecordChangeEvents($entityUuid), 0, 1));

            $this->resetRecordChangeEvents();
            $client->deleteEntity($entityUuid);
            $this->assertSame([['type' => 'ENTITY_DELETED', 'record_type' => 'ENTITY', 'uuid' => $entityUuid, 'record_exists' => false]], $this->getRecordChangeEvents($entityUuid));
        }

        public function testRecordChangeWhenEvidenceIsSubmitted(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginevidence') . '.example.com');
            $this->resetRecordChangeEvents();

            $classifiedUuid = $client->submitEvidence($entityUuid, 'Classified evidence text', classification: \FederationLib\Enums\ClassificationFlag::SUSPICIOUS);
            $unclassifiedUuid = $client->submitEvidence($entityUuid, 'Unclassified evidence text');

            // Evidence submitted with a classification is created and then classified
            $classified = ['record_type' => 'EVIDENCE', 'uuid' => $classifiedUuid, 'record_exists' => true, 'entity' => $entityUuid, 'text_content' => 'Classified evidence text', 'classification' => 'SUSPICIOUS'];
            $this->assertSame([
                ['type' => 'EVIDENCE_CREATED'] + $classified,
                ['type' => 'EVIDENCE_CLASSIFIED'] + $classified,
            ], $this->getRecordChangeEvents($classifiedUuid));

            $this->assertSame([
                ['type' => 'EVIDENCE_CREATED', 'record_type' => 'EVIDENCE', 'uuid' => $unclassifiedUuid, 'record_exists' => true, 'entity' => $entityUuid, 'text_content' => 'Unclassified evidence text', 'classification' => null],
            ], $this->getRecordChangeEvents($unclassifiedUuid));
        }

        public function testRecordChangeWhenEvidenceIsClassified(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $entityUuid = $client->pushEntity(uniqid('pluginevidence') . '.example.com');
            $evidenceUuid = $client->submitEvidence($entityUuid, 'Evidence to classify later');
            $this->resetRecordChangeEvents();

            $client->classifyEvidence($evidenceUuid, \FederationLib\Enums\ClassificationFlag::MALICIOUS);
            $this->assertSame([[
                'type' => 'EVIDENCE_CLASSIFIED',
                'record_type' => 'EVIDENCE',
                'uuid' => $evidenceUuid,
                'record_exists' => true,
                'entity' => $entityUuid,
                'text_content' => 'Evidence to classify later',
                'classification' => 'MALICIOUS',
            ]], $this->getRecordChangeEvents($evidenceUuid));

            // Classifications are immutable, classifying it again fails and changes nothing
            try
            {
                $client->classifyEvidence($evidenceUuid, \FederationLib\Enums\ClassificationFlag::NORMAL);
                $this->fail('Classifying evidence twice must fail');
            }
            catch(\FederationLib\Exceptions\RequestException $e)
            {
                $this->assertSame(409, $e->getCode());
            }

            $this->assertCount(1, $this->getRecordChangeEvents($evidenceUuid));
        }

        public function testRecordChangeWhenAReportIsClosed(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operatorUuid = $client->getSelf()->getUuid();
            $entityUuid = $client->pushEntity(uniqid('pluginreport') . '.example.com');
            $this->resetRecordChangeEvents();

            $submission = $client->submitReport($entityUuid, [
                ['text_content' => 'First reported message'],
                ['text_content' => 'Second reported message'],
            ], \FederationLib\Enums\IncidentType::SPAM);
            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuids = array_map(fn($evidence) => $evidence->getUuid(), $submission->getEvidence());
            $this->assertCount(2, $evidenceUuids);
            $this->assertSame('REPORT_CREATED', $this->getRecordChangeEvents($reportUuid)[0]['type']);

            $client->assignOperatorToReport($reportUuid, $operatorUuid);
            $this->resetRecordChangeEvents();
            $client->closeReport($reportUuid, \FederationLib\Enums\ClassificationFlag::SUSPICIOUS);

            $this->assertSame([[
                'type' => 'REPORT_CLOSED',
                'record_type' => 'REPORT',
                'uuid' => $reportUuid,
                'record_exists' => true,
                'opened' => false,
                'assigned_operator' => $operatorUuid,
            ]], $this->getRecordChangeEvents($reportUuid));

            // Closing the report with a classification classifies its evidence
            $events = array_values(array_filter($this->getRecordChangeEvents(), fn(array $event) => $event['type'] === 'EVIDENCE_CLASSIFIED'));
            $this->assertEqualsCanonicalizing($evidenceUuids, array_column($events, 'uuid'));
            $this->assertSame(['SUSPICIOUS', 'SUSPICIOUS'], array_column($events, 'classification'));
            $this->assertEqualsCanonicalizing(['First reported message', 'Second reported message'], array_column($events, 'text_content'));
        }

        private function resetRecordChangeEvents(): void
        {
            $response = $this->request('DELETE', '/test-plugin/record-change-events', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
        }

        private function getRecordChangeEvents(?string $uuid=null): array
        {
            $response = $this->request('GET', '/test-plugin/record-change-events', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertIsArray($response['body']);

            if($uuid === null)
            {
                return $response['body'];
            }

            return array_values(array_filter($response['body'], fn(array $event) => $event['uuid'] === $uuid));
        }

        private function scan(array $evidence): array
        {
            return $this->request('POST', '/scan', null, ['evidence' => $evidence], getenv('SERVER_ACCESS_TOKEN'));
        }

        private function getTestPluginScanResults(array $scanResults): array
        {
            return array_filter($scanResults, fn(string $rule) => str_starts_with($rule, 'TEST_PLUGIN_'), ARRAY_FILTER_USE_KEY);
        }

        public function testAuditLogEventHandlerReceivesTheAuditLogEntry(): void
        {
            $this->resetAuditLogEvents();

            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operator = $client->createOperator(uniqid('plugin_audit_'));
            $startTime = time();

            $events = $this->findAuditLogEvents('all', 'OPERATOR_CREATED', $operator->getUuid());
            $this->assertCount(1, $events, 'The unfiltered event handler must receive the OPERATOR_CREATED entry exactly once');
            $auditLog = $events[0]['audit_log'];

            // The event handler receives the same audit log entry that was stored
            $stored = $client->getAuditLogRecord($auditLog['uuid'])->toArray();
            $this->assertSame($stored['uuid'], $auditLog['uuid']);
            $this->assertSame($stored['type'], $auditLog['type']);
            $this->assertSame($stored['message'], $auditLog['message']);
            $this->assertSame($client->getSelf()->getUuid(), $auditLog['operator']);
            $this->assertSame($stored['operator'], $auditLog['operator']);
            $this->assertSame($stored['entity'], $auditLog['entity']);
            $this->assertSame($stored['blacklist'], $auditLog['blacklist']);
            $this->assertSame($stored['evidence'], $auditLog['evidence']);
            $this->assertSame($stored['file_attachment'], $auditLog['file_attachment']);
            $this->assertEqualsWithDelta($stored['timestamp'], $auditLog['timestamp'], 5);
            $this->assertEqualsWithDelta($startTime, $auditLog['timestamp'], 30);

            // The filtered event handler only receives OPERATOR_DELETED entries
            $this->assertCount(0, $this->findAuditLogEvents('filtered', 'OPERATOR_CREATED', $operator->getUuid()));

            $client->deleteOperator($operator->getUuid());
        }

        public function testFilteredAuditLogEventHandler(): void
        {
            $client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operator = $client->createOperator(uniqid('plugin_audit_'));
            $this->resetAuditLogEvents();

            // The filtered event handler throws after recording the entry, which must not affect the request
            $client->deleteOperator($operator->getUuid());
            $this->assertNull($this->getOperatorOrNull($client, $operator->getUuid()), 'The operator must be deleted even though an event handler failed');

            $this->assertCount(1, $this->findAuditLogEvents('filtered', 'OPERATOR_DELETED', $operator->getUuid()));
            $this->assertCount(1, $this->findAuditLogEvents('all', 'OPERATOR_DELETED', $operator->getUuid()));

            foreach($this->getAuditLogEvents() as $event)
            {
                if($event['handler'] === 'filtered')
                {
                    $this->assertSame('OPERATOR_DELETED', $event['audit_log']['type']);
                }
            }
        }

        private function resetAuditLogEvents(): void
        {
            $response = $this->request('DELETE', '/test-plugin/audit-log-events', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertSame([], $response['body']);
        }

        private function getAuditLogEvents(): array
        {
            $response = $this->request('GET', '/test-plugin/audit-log-events', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertIsArray($response['body']);
            return $response['body'];
        }

        private function findAuditLogEvents(string $handler, string $type, string $mentions): array
        {
            return array_values(array_filter($this->getAuditLogEvents(), fn(array $event) =>
                $event['handler'] === $handler &&
                $event['audit_log']['type'] === $type &&
                str_contains($event['audit_log']['message'], $mentions)
            ));
        }

        private function getOperatorOrNull(FederationClient $client, string $operatorUuid): ?string
        {
            try
            {
                return $client->getOperator($operatorUuid)->getUuid();
            }
            catch(\Throwable)
            {
                return null;
            }
        }

        private function resetPostRequestStats(): void
        {
            $response = $this->request('DELETE', '/test-plugin/post-stats', null, null, getenv('SERVER_ACCESS_TOKEN'));
            $this->assertSame(200, $response['code']);
            $this->assertSame(0, $response['body']['count']);
        }

        /**
         * Returns the executions recorded by the TestPlugin's POST_REQUEST handler
         */
        private function getPostRequestStats(): array
        {
            $response = $this->request('GET', '/test-plugin/post-stats');
            $this->assertSame(200, $response['code']);
            return $response['body'];
        }

        /**
         * Makes a raw HTTP request to the server, returning the response code, the lower-case response headers, the
         * decoded JSON body and the raw body.
         *
         * @return array{code: int, headers: array<string, string>, body: mixed, raw: string}
         */
        private function request(string $method, string $path, ?array $query=null, ?array $json=null, ?string $accessToken=null): array
        {
            $url = rtrim(getenv('SERVER_ENDPOINT'), '/') . $path;
            if($query !== null)
            {
                $url .= '?' . http_build_query($query);
            }

            $headers = ['Accept: application/json'];
            if($accessToken !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            if($json !== null)
            {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $responseHeaders = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, string $header) use (&$responseHeaders): int
            {
                $parts = explode(':', $header, 2);
                if(count($parts) === 2)
                {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            });

            $raw = curl_exec($ch);
            if($raw === false)
            {
                $this->fail(sprintf('Request to %s failed: %s', $url, curl_error($ch)));
            }

            return [
                'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'headers' => $responseHeaders,
                'body' => json_decode($raw, true),
                'raw' => $raw,
            ];
        }
    }
