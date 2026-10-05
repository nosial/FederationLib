<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Plugins;

    use FederationLib\Classes\Configuration\PluginsConfiguration;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EventType;
    use FederationLib\Enums\ExecutionPriority;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Classes\Configuration;
    use FederationLib\Exceptions\ContentScanRejectedException;
    use FederationLib\Exceptions\EntityQueryRejectedException;
    use FederationLib\Exceptions\PluginException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Interfaces\AuditLogEventHandlerInterface;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Interfaces\QueryEntityEventHandlerInterface;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin;
    use FederationLib\Objects\Plugin\EntityQuery;
    use FederationLib\Objects\Plugin\EventHandlerDefinition;
    use InvalidArgumentException;
    use FederationLib\Objects\Plugin\MatchedRequestHandler;
    use FederationLib\Objects\Plugin\PluginRoute;
    use FederationLib\Objects\Plugin\RecordChange;
    use FederationLib\Objects\Plugin\RequestHandlerDefinition;
    use ncc\Runtime;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use TestPlugin\AuditLogEvents;
    use TestPlugin\ContentScanEvents;
    use TestPlugin\RecordChangeEvents;

    class PluginSystemTest extends TestCase
    {
        private const string TEST_PLUGIN = 'net.nosial.test_plugin';
        private const string HANDLER_CLASS = 'TestPlugin\RequestHandlers\PingHandler';
        private const string EVENT_HANDLER_CLASS = 'TestPlugin\EventHandlers\AuditLogEventHandler';
        private const string RECORD_UUID = '01890a5d-ac96-774b-bcce-b302099a8057';
        private const string ENTITY_UUID = '01890a5d-ac96-774b-bcce-b302099a8058';
        private const string RELATED_UUID = '01890a5d-ac96-774b-bcce-b302099a8059';

        protected function tearDown(): void
        {
            PluginManager::setPlugins([]);
            AuditLogEvents::reset();
            ContentScanEvents::reset();
            RecursiveAuditLogEventHandler::$received = [];
        }

        public function testExecutionPriorityIsCaseInsensitive(): void
        {
            $this->assertSame(ExecutionPriority::PRE_REQUEST, ExecutionPriority::tryFromCaseInsensitive('pre_request'));
            $this->assertSame(ExecutionPriority::POST_REQUEST, ExecutionPriority::tryFromCaseInsensitive(' Post_Request '));
            $this->assertSame(ExecutionPriority::OVERRIDE, ExecutionPriority::tryFromCaseInsensitive('OVERRIDE'));
            $this->assertNull(ExecutionPriority::tryFromCaseInsensitive('DURING_REQUEST'));
        }

        public function testDefinitionFromArrayWithCommaSeparatedMethods(): void
        {
            $definition = RequestHandlerDefinition::fromArray([
                'path' => '/foo/bar',
                'class' => '\TestPlugin\RequestHandlers\FooHandler::class',
                'request_method' => 'get, POST,',
            ]);

            $this->assertSame('/foo/bar', $definition->getPath());
            $this->assertSame('TestPlugin\RequestHandlers\FooHandler', $definition->getClass());
            $this->assertSame(['GET', 'POST'], $definition->getRequestMethods());
            $this->assertNull($definition->getExecutionPriority());
            $this->assertTrue($definition->isLiteralPath());
        }

        public function testDefinitionFromArrayWithPushMethod(): void
        {
            // PUSH is not a standard method, but is supported for plugins proxying services that use it (eg; BayesianServer)
            $definition = RequestHandlerDefinition::fromArray([
                'path' => '/foo/*',
                'class' => 'TestPlugin\RequestHandlers\FooHandler',
                'request_method' => 'GET, push',
            ]);

            $this->assertSame(['GET', 'PUSH'], $definition->getRequestMethods());
        }

        public function testDefinitionFromArrayWithMethodListAndPriority(): void
        {
            $definition = RequestHandlerDefinition::fromArray([
                'path' => '/foo/bar',
                'class' => 'TestPlugin\RequestHandlers\FooHandler',
                'request_method' => ['GET', 'get', 'DELETE'],
                'execution_priority' => 'pre_request',
            ]);

            $this->assertSame(['GET', 'DELETE'], $definition->getRequestMethods());
            $this->assertSame(ExecutionPriority::PRE_REQUEST, $definition->getExecutionPriority());
        }

        public static function invalidDefinitionProvider(): array
        {
            return [
                'missing path' => [['class' => 'Foo', 'request_method' => 'GET']],
                'missing class' => [['path' => '/foo', 'request_method' => 'GET']],
                'missing request_method' => [['path' => '/foo', 'class' => 'Foo']],
                'empty request_method' => [['path' => '/foo', 'class' => 'Foo', 'request_method' => ' , ']],
                'unsupported request_method' => [['path' => '/foo', 'class' => 'Foo', 'request_method' => 'GET, TRACE']],
                'unknown execution_priority' => [['path' => '/foo', 'class' => 'Foo', 'request_method' => 'GET', 'execution_priority' => 'DURING_REQUEST']],
                'path without leading slash' => [['path' => 'foo', 'class' => 'Foo', 'request_method' => 'GET']],
                'empty class' => [['path' => '/foo', 'class' => '::class', 'request_method' => 'GET']],
                'wildcard not last' => [['path' => '/foo/*/bar', 'class' => 'Foo', 'request_method' => 'GET']],
                'partial placeholder' => [['path' => '/foo/bar-{id}', 'class' => 'Foo', 'request_method' => 'GET']],
                'duplicate placeholder' => [['path' => '/foo/{id}/{id}', 'class' => 'Foo', 'request_method' => 'GET']],
            ];
        }

        #[DataProvider('invalidDefinitionProvider')]
        public function testInvalidDefinitionsAreRejected(array $data): void
        {
            $this->expectException(PluginException::class);
            RequestHandlerDefinition::fromArray($data);
        }

        public function testLiteralPathMatching(): void
        {
            $definition = new RequestHandlerDefinition('/foo/bar', self::HANDLER_CLASS, ['GET']);

            $this->assertSame([], $definition->match('GET', '/foo/bar'));
            $this->assertSame([], $definition->match('get', '/foo/bar'));
            $this->assertNull($definition->match('POST', '/foo/bar'));
            $this->assertNull($definition->match('GET', '/foo/bar/baz'));
            $this->assertNull($definition->match('GET', '/foo'));
        }

        public function testLiteralPathIsNotTreatedAsRegex(): void
        {
            $definition = new RequestHandlerDefinition('/foo.json', self::HANDLER_CLASS, ['GET']);

            $this->assertNotNull($definition->match('GET', '/foo.json'));
            $this->assertNull($definition->match('GET', '/fooXjson'));
        }

        public function testPlaceholderPathMatching(): void
        {
            $definition = new RequestHandlerDefinition('/foo/{uuid}/items/{item}', self::HANDLER_CLASS, ['GET']);

            $this->assertFalse($definition->isLiteralPath());
            $this->assertSame(['uuid' => 'abc', 'item' => '42'], $definition->match('GET', '/foo/abc/items/42'));
            $this->assertNull($definition->match('GET', '/foo/abc/items'));
            $this->assertNull($definition->match('GET', '/foo/abc/def/items/42'));
            $this->assertNull($definition->match('GET', '/foo//items/42'));
        }

        public function testWildcardPathMatching(): void
        {
            $definition = new RequestHandlerDefinition('/foo/*', self::HANDLER_CLASS, ['GET']);

            $this->assertFalse($definition->isLiteralPath());
            $this->assertNotNull($definition->match('GET', '/foo'));
            $this->assertNotNull($definition->match('GET', '/foo/bar'));
            $this->assertNotNull($definition->match('GET', '/foo/bar/baz'));
            $this->assertNull($definition->match('GET', '/foobar'));
            $this->assertNull($definition->match('GET', '/bar/foo'));
        }

        public function testValidateClassRejectsMissingClass(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('does not exist');
            new RequestHandlerDefinition('/foo', 'TestPlugin\DoesNotExist', ['GET'])->validateClass();
        }

        public function testValidateClassRejectsNonRequestHandler(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('must implement');
            new RequestHandlerDefinition('/foo', PluginRoute::class, ['GET'])->validateClass();
        }

        public function testEventTypeIsCaseInsensitive(): void
        {
            $this->assertSame(EventType::AUDIT_LOG, EventType::tryFromCaseInsensitive(' audit_log '));
            $this->assertNull(EventType::tryFromCaseInsensitive('UNKNOWN_EVENT'));
            $this->assertSame(AuditLogEventHandlerInterface::class, EventType::AUDIT_LOG->getHandlerInterface());
            $this->assertContains(AuditLogType::OPERATOR_CREATED->value, EventType::AUDIT_LOG->getFilterValues());
        }

        public function testEventHandlerDefinitionFromArray(): void
        {
            $definition = EventHandlerDefinition::fromArray([
                'event' => 'audit_log',
                'class' => '\\' . self::EVENT_HANDLER_CLASS . '::class',
                'filter' => 'operator_created, OPERATOR_DELETED, operator_created',
            ]);

            $this->assertSame(EventType::AUDIT_LOG, $definition->getEvent());
            $this->assertSame(self::EVENT_HANDLER_CLASS, $definition->getClass());
            $this->assertSame(['OPERATOR_CREATED', 'OPERATOR_DELETED'], $definition->getFilter());
            $this->assertTrue($definition->matches('OPERATOR_CREATED'));
            $this->assertTrue($definition->matches('OPERATOR_DELETED'));
            $this->assertFalse($definition->matches('ENTITY_PUSHED'));
        }

        public function testEventHandlerDefinitionWithoutFilterMatchesEverything(): void
        {
            $definition = EventHandlerDefinition::fromArray(['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS]);
            $this->assertSame([], $definition->getFilter());

            foreach(AuditLogType::cases() as $type)
            {
                $this->assertTrue($definition->matches($type->value));
            }
        }

        public static function invalidEventHandlerProvider(): array
        {
            return [
                'missing event' => [['class' => self::EVENT_HANDLER_CLASS], 'missing the required "event"'],
                'unknown event' => [['event' => 'ENTITY_EXPLODED', 'class' => self::EVENT_HANDLER_CLASS], 'unknown event'],
                'missing class' => [['event' => 'AUDIT_LOG'], 'missing the required "class"'],
                'empty class' => [['event' => 'AUDIT_LOG', 'class' => '::class'], 'has no class'],
                'unknown filter value' => [['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS, 'filter' => 'OPERATOR_CREATED, NOT_A_TYPE'], 'unknown filter value "NOT_A_TYPE"'],
                'invalid filter' => [['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS, 'filter' => 42], 'invalid filter'],
                'invalid filter value' => [['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS, 'filter' => [['OPERATOR_CREATED']]], 'invalid filter value'],
            ];
        }

        #[DataProvider('invalidEventHandlerProvider')]
        public function testInvalidEventHandlerDefinitions(array $data, string $message): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage($message);
            EventHandlerDefinition::fromArray($data);
        }

        public function testEventHandlerValidateClass(): void
        {
            new EventHandlerDefinition(EventType::AUDIT_LOG, self::EVENT_HANDLER_CLASS)->validateClass();
            $this->addToAssertionCount(1);
        }

        public function testEventHandlerValidateClassRejectsMissingClass(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('does not exist');
            new EventHandlerDefinition(EventType::AUDIT_LOG, 'TestPlugin\EventHandlers\DoesNotExist')->validateClass();
        }

        public function testEventHandlerValidateClassRejectsWrongInterface(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('must implement ' . AuditLogEventHandlerInterface::class);
            new EventHandlerDefinition(EventType::AUDIT_LOG, self::HANDLER_CLASS)->validateClass();
        }

        public function testPluginAcceptsSingleEventHandlerMapping(): void
        {
            $plugin = new Plugin('com.example.plugin', ['event_handlers' => ['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS]]);
            $this->assertCount(1, $plugin->getEventHandlers());
            $this->assertCount(1, $plugin->getEventHandlers(EventType::AUDIT_LOG));
        }

        public function testPluginReportsInvalidEventHandlerIndex(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('invalid event handler at index 1');

            new Plugin('com.example.plugin', ['event_handlers' => [
                ['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS],
                ['event' => 'AUDIT_LOG'],
            ]]);
        }

        public function testPluginRejectsInvalidEventHandlersProperty(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('invalid "event_handlers" property');
            new Plugin('com.example.plugin', ['event_handlers' => 'AUDIT_LOG']);
        }

        public function testContentScanEventType(): void
        {
            $this->assertSame(EventType::CONTENT_SCAN, EventType::tryFromCaseInsensitive('content_scan'));
            $this->assertSame(ContentScanEventHandlerInterface::class, EventType::CONTENT_SCAN->getHandlerInterface());
            $this->assertSame([], EventType::CONTENT_SCAN->getFilterValues());

            $definition = EventHandlerDefinition::fromArray(['event' => 'CONTENT_SCAN', 'class' => 'TestPlugin\EventHandlers\ContentScanEventHandler']);
            $definition->validateClass();
            $this->assertTrue($definition->matches('anything'));
        }

        public function testContentScanEventHandlerRejectsFilter(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('does not support filters');
            EventHandlerDefinition::fromArray(['event' => 'CONTENT_SCAN', 'class' => 'TestPlugin\EventHandlers\ContentScanEventHandler', 'filter' => 'OPERATOR_CREATED']);
        }

        public function testContentScanProvidesTheRequest(): void
        {
            $evidence = [
                new ContentInput('first message', 'a note', 'a tag', true, ['key' => 'value']),
                new ContentInput(null, 'evidence without text'),
                new ContentInput('second message'),
            ];

            $contentScan = new ContentScan($evidence, 'user@example.com', null, [], null, 3, 0.5);
            $this->assertSame($evidence, $contentScan->getEvidence());
            $this->assertSame(['first message', 'second message'], $contentScan->getTextContents());
            $this->assertSame('user@example.com', $contentScan->getAuthorIdentifier());
            $this->assertNull($contentScan->getAuthorEntity());
            $this->assertSame([], $contentScan->getResolvedEntities());
            $this->assertNull($contentScan->getAuthenticatedOperator());
            $this->assertSame(3, $contentScan->getTopK());
            $this->assertSame(0.5, $contentScan->getThreshold());
            $this->assertSame([], $contentScan->getScanResults());
        }

        public function testContentScanAddScanResultAccumulates(): void
        {
            $contentScan = $this->createContentScan('content');
            $contentScan->addScanResult('ABUSEIPDB_REPORTED', -10.0);
            $contentScan->addScanResult('ABUSEIPDB_REPORTED', -5.5);
            $contentScan->addScanResult('EXAMPLE_TRUSTED', 3.0);

            $this->assertSame(['ABUSEIPDB_REPORTED' => -15.5, 'EXAMPLE_TRUSTED' => 3.0], $contentScan->getScanResults());
        }

        public static function invalidScanResultProvider(): array
        {
            return [
                'lowercase' => ['abuseipdb_reported', 1.0, 'Invalid scanning rule name'],
                'leading digit' => ['1_RULE', 1.0, 'Invalid scanning rule name'],
                'empty' => ['', 1.0, 'Invalid scanning rule name'],
                'spaces' => ['MY RULE', 1.0, 'Invalid scanning rule name'],
                'built-in rule' => [ScanningRules::CLASSIFICATION_MALICIOUS->name, -10.0, 'own scanning rules'],
                'infinite' => ['MY_RULE', INF, 'finite number'],
                'nan' => ['MY_RULE', NAN, 'finite number'],
            ];
        }

        #[DataProvider('invalidScanResultProvider')]
        public function testContentScanRejectsInvalidScanResults(string $rule, float $points, string $message): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($message);
            $this->createContentScan('content')->addScanResult($rule, $points);
        }

        public function testContentScanRejectDefaultsToForbidden(): void
        {
            try
            {
                $this->createContentScan('content')->reject('Not accepted');
                $this->fail('reject() must throw');
            }
            catch(ContentScanRejectedException $e)
            {
                $this->assertInstanceOf(RequestException::class, $e);
                $this->assertSame(HttpResponseCode::FORBIDDEN->value, $e->getCode());
                $this->assertStringContainsString('Not accepted', $e->getMessage());
            }
        }

        public function testContentScanRejectWithCustomCode(): void
        {
            $this->expectException(ContentScanRejectedException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS->value);
            $this->createContentScan('content')->reject('Not accepted', HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS);
        }

        public function testContentScanRejectRequiresClientError(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('4xx client error');
            $this->createContentScan('content')->reject('Not accepted', HttpResponseCode::INTERNAL_SERVER_ERROR);
        }

        public function testScannedContentIncludesAdditionalScanResults(): void
        {
            $scannedContent = new ScannedContent([], null, null, [
                'ABUSEIPDB_REPORTED' => -20.0,
                'EXAMPLE_TRUSTED' => 4,
                ScanningRules::AUTHOR_WHITELISTED->name => 100.0, // Built-in rules can not be overridden
                'NOT_A_NUMBER' => 'abc',
            ]);

            $this->assertSame(['ABUSEIPDB_REPORTED' => -20.0, 'EXAMPLE_TRUSTED' => 4.0], $scannedContent->getAdditionalScanResults());

            $scanResults = $scannedContent->getScanResults();
            $this->assertSame(0.0, $scanResults[ScanningRules::AUTHOR_WHITELISTED->name]);
            $this->assertSame(-20.0, $scanResults['ABUSEIPDB_REPORTED']);
            $this->assertSame(4.0, $scanResults['EXAMPLE_TRUSTED']);
            $this->assertCount(count(ScanningRules::cases()) + 2, $scanResults);

            // The additional scanning rules contribute to the risk score like the built-in scanning rules
            $configuration = Configuration::getScanningConfiguration();
            $expected = round(max($configuration->getRiskScoreMinBound(), min($configuration->getRiskScoreMaxBound(),
                $configuration->getRiskScoreNeutralPoint() - (-16.0 * $configuration->getRiskScoreScalingFactor()))), 2);
            $this->assertSame($expected, $scannedContent->getRiskScore());
            $this->assertNotSame(new ScannedContent([])->getRiskScore(), $scannedContent->getRiskScore());

            // The standard response contains the additional scanning rules, and they survive a round trip
            $standardArray = $scannedContent->toStandardArray();
            $this->assertSame(-20.0, ((array)$standardArray['scan_results'])['ABUSEIPDB_REPORTED']);

            $parsed = ScannedContent::fromArray(json_decode(json_encode($standardArray), true));
            $this->assertSame(['ABUSEIPDB_REPORTED' => -20.0, 'EXAMPLE_TRUSTED' => 4.0], $parsed->getAdditionalScanResults());
            $this->assertSame($scannedContent->getRiskScore(), $parsed->getRiskScore());
        }

        public function testDispatchContentScanCollectsScanResults(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $contentScan = $this->createContentScan('hello test_plugin_penalty test_plugin_bonus');
            PluginManager::dispatchContentScan($contentScan);
            $this->assertSame(['TEST_PLUGIN_PENALTY' => -20.0, 'TEST_PLUGIN_BONUS' => 5.0], $contentScan->getScanResults());

            // Content without trigger words is never affected
            $contentScan = $this->createContentScan('hello world');
            PluginManager::dispatchContentScan($contentScan);
            $this->assertSame([], $contentScan->getScanResults());
        }

        public function testDispatchContentScanDiscardsResultsOfFailedHandler(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $contentScan = $this->createContentScan('test_plugin_penalty test_plugin_fail');
            PluginManager::dispatchContentScan($contentScan);

            // The handler added TEST_PLUGIN_PENALTY and TEST_PLUGIN_FAILED before failing, both are discarded
            $this->assertSame([], $contentScan->getScanResults());
        }

        public function testDispatchContentScanKeepsResultsOfEarlierHandlers(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.first', ['event_handlers' => ['event' => 'CONTENT_SCAN', 'class' => 'TestPlugin\EventHandlers\ContentScanEventHandler']]),
                new Plugin('com.example.second', ['event_handlers' => ['event' => 'CONTENT_SCAN', 'class' => FailingContentScanEventHandler::class]]),
            ]);

            $contentScan = $this->createContentScan('test_plugin_penalty');
            PluginManager::dispatchContentScan($contentScan);
            $this->assertSame(['TEST_PLUGIN_PENALTY' => -20.0], $contentScan->getScanResults());
        }

        public function testDispatchContentScanRejection(): void
        {
            PluginManager::setPlugins([
                Plugin::load(self::TEST_PLUGIN),
                new Plugin('com.example.second', ['event_handlers' => ['event' => 'CONTENT_SCAN', 'class' => FailingContentScanEventHandler::class]]),
            ]);
            FailingContentScanEventHandler::$executions = 0;

            try
            {
                PluginManager::dispatchContentScan($this->createContentScan('test_plugin_reject'));
                $this->fail('The content scan must be rejected');
            }
            catch(ContentScanRejectedException $e)
            {
                $this->assertSame(HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS->value, $e->getCode());
                $this->assertStringContainsString('The TestPlugin does not accept this content', $e->getMessage());
            }

            // The remaining event handlers are not executed once the request is rejected
            $this->assertSame(0, FailingContentScanEventHandler::$executions);
        }

        public function testDispatchContentScanRecordsInspection(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $contentScan = new ContentScan([new ContentInput('test_plugin_inspect', 'note', 'tag', true, ['a' => 1])], 'user@example.com', null, [], null, 5, 0.25);
            PluginManager::dispatchContentScan($contentScan);

            $inspected = ContentScanEvents::read();
            $this->assertNotNull($inspected);
            $this->assertSame([['text_content' => 'test_plugin_inspect', 'note' => 'note', 'tag' => 'tag', 'confidential' => true, 'metadata' => ['a' => 1]]], $inspected['evidence']);
            $this->assertSame('user@example.com', $inspected['author_identifier']);
            $this->assertNull($inspected['author_entity']);
            $this->assertSame(5, $inspected['top_k']);
            $this->assertSame(0.25, $inspected['threshold']);
        }

        public function testContentScanAddClassification(): void
        {
            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput('second')], null, null, []);
            $first = new ContentClassification(ClassificationFlag::NORMAL, 0.8, 'en');
            $second = new ContentClassification(ClassificationFlag::MALICIOUS, 0.9, 'en');
            $unassigned = new ContentClassification(ClassificationFlag::SUSPICIOUS, 0.6, null);

            $contentScan->addClassification($first, 0);
            $contentScan->addClassification($second, 1);
            $contentScan->addClassification($unassigned);

            $this->assertSame([$first, $second, $unassigned], $contentScan->getAddedClassifications());
            $this->assertSame([$first], $contentScan->getEvidenceClassifications(0));
            $this->assertSame([$second], $contentScan->getEvidenceClassifications(1));
            $this->assertSame([], $contentScan->getEvidenceClassifications(2));
        }

        public function testContentScanAddClassificationRejectsUnknownEvidenceIndex(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('evidence index 1 does not exist');
            $this->createContentScan('content')->addClassification(new ContentClassification(ClassificationFlag::NORMAL, 0.5, null), 1);
        }

        public function testContentScanAddClassificationRejectsInvalidConfidence(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('between 0 and 1');
            $this->createContentScan('content')->addClassification(new ContentClassification(ClassificationFlag::NORMAL, 1.5, null));
        }

        public function testContentScanStateCanBeRestored(): void
        {
            $contentScan = $this->createContentScan('content');
            $contentScan->addScanResult('FIRST_RULE', 1.0);
            $state = $contentScan->getState();

            $contentScan->addScanResult('SECOND_RULE', 2.0);
            $contentScan->addClassification(new ContentClassification(ClassificationFlag::MALICIOUS, 0.9, null), 0);
            $contentScan->restoreState($state);

            $this->assertSame(['FIRST_RULE' => 1.0], $contentScan->getScanResults());
            $this->assertSame([], $contentScan->getAddedClassifications());
        }

        public function testScannedContentIncludesPluginClassifications(): void
        {
            $classification = new ContentClassification(ClassificationFlag::MALICIOUS, 0.9, 'en');
            $scannedContent = new ScannedContent([], null, [$classification]);

            $this->assertSame(ClassificationFlag::MALICIOUS, $scannedContent->getClassification()->getClassificationFlag());
            $this->assertLessThan(0.0, $scannedContent->getScanResults()[ScanningRules::CLASSIFICATION_MALICIOUS->name]);
        }

        public function testDispatchContentScanCollectsClassifications(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $contentScan = new ContentScan([new ContentInput('nothing here'), new ContentInput('test_plugin_classify')], null, null, []);
            PluginManager::dispatchContentScan($contentScan);

            $this->assertCount(1, $contentScan->getAddedClassifications());
            $this->assertSame([], $contentScan->getEvidenceClassifications(0));
            $this->assertSame(ClassificationFlag::MALICIOUS, $contentScan->getEvidenceClassifications(1)[0]->getClassificationFlag());
        }

        public function testDispatchContentScanDiscardsClassificationsOfFailedHandler(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            // The handler classifies the content, adds TEST_PLUGIN_FAILED and a classification and then fails
            $contentScan = $this->createContentScan('test_plugin_classify test_plugin_fail');
            PluginManager::dispatchContentScan($contentScan);

            $this->assertSame([], $contentScan->getAddedClassifications());
            $this->assertSame([], $contentScan->getScanResults());
        }

        public function testQueryEntityEventType(): void
        {
            $this->assertSame(EventType::QUERY_ENTITY, EventType::tryFromCaseInsensitive('query_entity'));
            $this->assertSame(QueryEntityEventHandlerInterface::class, EventType::QUERY_ENTITY->getHandlerInterface());
            $this->assertSame([], EventType::QUERY_ENTITY->getFilterValues());

            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('does not support filters');
            EventHandlerDefinition::fromArray(['event' => 'QUERY_ENTITY', 'class' => 'TestPlugin\EventHandlers\QueryEntityEventHandler', 'filter' => 'ANYTHING']);
        }

        public function testEntityQueryWithoutChangesKeepsTheResult(): void
        {
            $blacklist = $this->createBlacklist(self::RELATED_UUID, time() + 3600);
            $entityQuery = $this->createEntityQuery([$blacklist]);

            $this->assertSame('example.com', $entityQuery->getIdentifier());
            $this->assertFalse($entityQuery->isSuggestedActionOverridden());

            $expected = new EntityQueryResult($entityQuery->getEntityRecord(), $entityQuery->getRelatedEntities(), [$blacklist]);
            $this->assertSame($expected->toStandardArray(), $entityQuery->getResult()->toStandardArray());
            $this->assertSame(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY->value, $entityQuery->getResult()->toStandardArray()['suggested_action']);
        }

        public function testEntityQueryRelatedEntities(): void
        {
            $entityQuery = $this->createEntityQuery([$this->createBlacklist(self::RELATED_UUID, null)]);
            $added = $this->createEntity('01890a5d-ac96-774b-bcce-b302099a8060', 'added.example.com');

            $entityQuery->addRelatedEntity($added);
            $this->assertSame([self::RELATED_UUID, $added->getUuid()], array_map(fn(EntityRecord $entity) => $entity->getUuid(), $entityQuery->getRelatedEntities()));

            // Removing a related entity also removes its active blacklists
            $entityQuery->removeRelatedEntity(self::RELATED_UUID);
            $this->assertSame([$added->getUuid()], array_column($entityQuery->getResult()->toStandardArray()['related_entities'], 'uuid'));
            $this->assertSame([], $entityQuery->getActiveBlacklists());
            $this->assertNull($entityQuery->getResult()->getSuggestedAction());
        }

        public function testEntityQueryCannotRelateTheQueriedEntity(): void
        {
            $entityQuery = $this->createEntityQuery();
            $this->expectException(InvalidArgumentException::class);
            $entityQuery->addRelatedEntity($entityQuery->getEntityRecord());
        }

        public function testEntityQueryBlacklists(): void
        {
            $entityQuery = $this->createEntityQuery();
            $this->assertNull($entityQuery->getResult()->getSuggestedAction());

            // A permanent blacklist of the queried entity is taken into account by the suggested action
            $blacklist = $this->createBlacklist(self::ENTITY_UUID, null);
            $entityQuery->addBlacklist($blacklist);
            $this->assertSame(SuggestedActionType::PERMANENTLY_BLOCK_ENTITY, $entityQuery->getResult()->getSuggestedAction());

            $entityQuery->removeBlacklist($blacklist->getUuid());
            $this->assertNull($entityQuery->getResult()->getSuggestedAction());
        }

        public function testEntityQueryRejectsBlacklistsOutsideTheRelationshipGroup(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('neither the queried entity nor a related entity');
            $this->createEntityQuery()->addBlacklist($this->createBlacklist('01890a5d-ac96-774b-bcce-b302099a8061', null));
        }

        public function testEntityQueryRejectsLiftedBlacklists(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('lifted');
            $this->createEntityQuery()->addBlacklist($this->createBlacklist(self::ENTITY_UUID, null, true));
        }

        public function testEntityQueryEntityMetadata(): void
        {
            $entityQuery = $this->createEntityQuery();
            $entityQuery->addEntityMetadata(['source' => 'plugin', 'stored' => 'overwritten']);
            $entityQuery->addEntityMetadata(['score' => 5]);

            // The stored entity is unchanged, the response contains the merged metadata
            $this->assertSame(['stored' => 'value'], $entityQuery->getEntityRecord()->getMetadata());
            $this->assertSame(['stored' => 'overwritten', 'source' => 'plugin', 'score' => 5], $entityQuery->getResult()->getEntityRecord()->getMetadata());
            $this->assertNull($entityQuery->getResult()->toStandardArray(false)['entity_record']['metadata']);
        }

        public function testEntityQueryRejectsInvalidMetadata(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->createEntityQuery()->addEntityMetadata(['nested' => ['not' => 'allowed']]);
        }

        public function testEntityQuerySuggestedActionOverride(): void
        {
            $entityQuery = $this->createEntityQuery([$this->createBlacklist(self::ENTITY_UUID, null)]);

            $entityQuery->setSuggestedAction(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY, 4102444800);
            $result = $entityQuery->getResult()->toStandardArray();
            $this->assertSame(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY->value, $result['suggested_action']);
            $this->assertSame(4102444800, $result['suggested_lift_timestamp']);

            // The override survives parsing the response
            $parsed = EntityQueryResult::fromArray($result);
            $this->assertSame(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY, $parsed->getSuggestedAction());
            $this->assertSame(4102444800, $parsed->getSuggestedLiftTimestamp());

            $entityQuery->setSuggestedAction(null);
            $this->assertNull($entityQuery->getResult()->getSuggestedAction());
            $this->assertNull(EntityQueryResult::fromArray($entityQuery->getResult()->toStandardArray())->getSuggestedAction());

            $entityQuery->resetSuggestedAction();
            $this->assertFalse($entityQuery->isSuggestedActionOverridden());
            $this->assertSame(SuggestedActionType::PERMANENTLY_BLOCK_ENTITY, $entityQuery->getResult()->getSuggestedAction());
        }

        public function testEntityQueryLiftTimestampRequiresTemporaryBlock(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->createEntityQuery()->setSuggestedAction(SuggestedActionType::PERMANENTLY_BLOCK_ENTITY, 4102444800);
        }

        public function testEntityQueryReject(): void
        {
            try
            {
                $this->createEntityQuery()->reject('Not allowed', HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS);
                $this->fail('EntityQuery::reject() must throw');
            }
            catch(EntityQueryRejectedException $e)
            {
                $this->assertStringContainsString('Not allowed', $e->getMessage());
                $this->assertSame(451, $e->getCode());
            }

            $this->expectException(InvalidArgumentException::class);
            $this->createEntityQuery()->reject('Not allowed', HttpResponseCode::INTERNAL_SERVER_ERROR);
        }

        public function testDispatchQueryEntityToTestPlugin(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            // Entities without a trigger label are left alone
            $entityQuery = $this->createEntityQuery();
            PluginManager::dispatchQueryEntity($entityQuery);
            $this->assertSame([], $entityQuery->getAddedEntityMetadata());
            $this->assertFalse($entityQuery->isSuggestedActionOverridden());
            $this->assertCount(1, $entityQuery->getRelatedEntities());

            $entityQuery = $this->createEntityQuery([], 'a.test-plugin-enrich.test-plugin-block.test-plugin-isolate.example.com');
            PluginManager::dispatchQueryEntity($entityQuery);
            $this->assertSame(['test_plugin_identifier' => 'example.com', 'test_plugin_related' => 1, 'test_plugin_blacklists' => 0], $entityQuery->getAddedEntityMetadata());
            $this->assertSame(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY, $entityQuery->getResult()->getSuggestedAction());
            $this->assertSame([], $entityQuery->getRelatedEntities());
        }

        public function testDispatchQueryEntityDiscardsChangesOfFailedHandler(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            // The handler enriches the entity, then adds metadata, suggests a permanent block and fails
            $entityQuery = $this->createEntityQuery([], 'a.test-plugin-enrich.test-plugin-fail.example.com');
            PluginManager::dispatchQueryEntity($entityQuery);

            $this->assertSame([], $entityQuery->getAddedEntityMetadata());
            $this->assertFalse($entityQuery->isSuggestedActionOverridden());
        }

        public function testDispatchQueryEntityRejection(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $this->expectException(EntityQueryRejectedException::class);
            $this->expectExceptionCode(451);
            PluginManager::dispatchQueryEntity($this->createEntityQuery([], 'a.test-plugin-reject.example.com'));
        }

        /**
         * Creates an EntityQuery of an entity with one related entity
         *
         * @param BlacklistRecord[] $activeBlacklists The active blacklists
         * @param string $host The host of the queried entity
         */
        private function createEntityQuery(array $activeBlacklists=[], string $host='example.com'): EntityQuery
        {
            return new EntityQuery('example.com', $this->createEntity(self::ENTITY_UUID, $host, ['stored' => 'value']),
                [$this->createEntity(self::RELATED_UUID, 'related.example.com')], $activeBlacklists);
        }

        private function createEntity(string $uuid, string $host, ?array $metadata=null): EntityRecord
        {
            return new EntityRecord(['uuid' => $uuid, 'host' => $host, 'metadata' => $metadata, 'reputation' => 0, 'whitelisted' => false, 'created' => 1700000000]);
        }

        private function createBlacklist(string $entityUuid, ?int $expires, bool $lifted=false): BlacklistRecord
        {
            return new BlacklistRecord(['uuid' => '01890a5d-ac96-774b-bcce-b3020' . substr(md5($entityUuid . $expires . $lifted), 0, 7), 'operator' => self::RECORD_UUID,
                'entity' => $entityUuid, 'type' => 'SPAM', 'expires' => $expires, 'lifted' => $lifted, 'created' => 1700000000]);
        }

        public function testRecordChangeEventType(): void
        {
            $this->assertSame(EventType::RECORD_CHANGE, EventType::tryFromCaseInsensitive('record_change'));
            $this->assertSame(RecordChangeEventHandlerInterface::class, EventType::RECORD_CHANGE->getHandlerInterface());
            $this->assertSame(array_map(fn(RecordChangeType $type) => $type->value, RecordChangeType::cases()), EventType::RECORD_CHANGE->getFilterValues());

            $definition = EventHandlerDefinition::fromArray(['event' => 'RECORD_CHANGE', 'class' => 'TestPlugin\EventHandlers\RecordChangeEventHandler', 'filter' => 'report_closed, evidence_classified']);
            $definition->validateClass();
            $this->assertSame(['REPORT_CLOSED', 'EVIDENCE_CLASSIFIED'], $definition->getFilter());
            $this->assertTrue($definition->matches(RecordChangeType::EVIDENCE_CLASSIFIED->value));
            $this->assertFalse($definition->matches(RecordChangeType::EVIDENCE_CREATED->value));
        }

        public function testRecordChangeEventHandlerRejectsUnknownFilter(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('unknown filter value "MALICIOUS"');
            EventHandlerDefinition::fromArray(['event' => 'RECORD_CHANGE', 'class' => 'TestPlugin\EventHandlers\RecordChangeEventHandler', 'filter' => 'MALICIOUS']);
        }

        public function testRecordChangeTypes(): void
        {
            foreach(RecordChangeType::cases() as $type)
            {
                // Every change type is named after the type of the record it changes
                $this->assertStringStartsWith($type->getRecordType()->value . '_', $type->value);
                $this->assertSame(str_ends_with($type->value, '_DELETED'), $type->isDeletion());
            }

            $this->assertSame(RecordChangeType::REPORT_CLOSED, RecordChangeType::tryFromCaseInsensitive(' report_closed '));
        }

        public function testRecordChangeOfDeletedRecord(): void
        {
            // The record of a deletion is never retrieved, so this does not require a database
            $change = new RecordChange(RecordChangeType::REPORT_DELETED, self::RECORD_UUID);

            $this->assertSame(RecordChangeType::REPORT_DELETED, $change->getType());
            $this->assertSame(RecordType::REPORT, $change->getRecordType());
            $this->assertSame(self::RECORD_UUID, $change->getUuid());
            $this->assertNull($change->getRecord());
        }

        public function testDispatchRecordChangeRespectsFilters(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.plugin', ['event_handlers' => [
                    ['event' => 'RECORD_CHANGE', 'class' => RecordingRecordChangeEventHandler::class, 'filter' => 'REPORT_DELETED'],
                ]]),
            ]);
            RecordingRecordChangeEventHandler::$received = [];

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_DELETED, self::RECORD_UUID);
            PluginManager::dispatchRecordChange(RecordChangeType::REPORT_DELETED, self::RECORD_UUID);

            $this->assertSame([['REPORT_DELETED', self::RECORD_UUID]], RecordingRecordChangeEventHandler::$received);
        }

        public function testDispatchRecordChangeExecutesPluginsInOrder(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.first', ['event_handlers' => ['event' => 'RECORD_CHANGE', 'class' => RecursiveRecordChangeEventHandler::class]]),
                new Plugin('com.example.second', ['event_handlers' => ['event' => 'RECORD_CHANGE', 'class' => FailingRecordChangeEventHandler::class]]),
                new Plugin('com.example.third', ['event_handlers' => ['event' => 'RECORD_CHANGE', 'class' => RecordingRecordChangeEventHandler::class]]),
            ]);
            RecursiveRecordChangeEventHandler::$received = [];
            RecordingRecordChangeEventHandler::$received = [];

            // The nested dispatch from within the first event handler is ignored and the failure of the second event
            // handler does not propagate, so every event handler executes once
            PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_DELETED, self::RECORD_UUID);
            $this->assertSame([self::RECORD_UUID], RecursiveRecordChangeEventHandler::$received);
            $this->assertSame([['EVIDENCE_DELETED', self::RECORD_UUID]], RecordingRecordChangeEventHandler::$received);

            // Dispatching works again once the previous dispatch completed
            PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_DELETED, self::RECORD_UUID);
            $this->assertCount(2, RecursiveRecordChangeEventHandler::$received);
        }

        public function testDispatchRecordChangeToTestPlugin(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);
            RecordChangeEvents::reset();

            PluginManager::dispatchRecordChange(RecordChangeType::BLACKLIST_DELETED, self::RECORD_UUID);

            $this->assertSame([[
                'type' => 'BLACKLIST_DELETED',
                'record_type' => 'BLACKLIST',
                'uuid' => self::RECORD_UUID,
                'record_exists' => false,
            ]], RecordChangeEvents::read());
        }

        public function testDispatchAuditLogRespectsFilters(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);
            AuditLogEvents::reset();

            $created = $this->createAuditLog(AuditLogType::OPERATOR_CREATED);
            PluginManager::dispatchAuditLog($created);

            $events = AuditLogEvents::read();
            $this->assertCount(1, $events);
            $this->assertSame('all', $events[0]['handler']);
            $this->assertSame($created->toArray(), $events[0]['audit_log']);

            // The filtered event handler throws after recording, which must not propagate
            $deleted = $this->createAuditLog(AuditLogType::OPERATOR_DELETED);
            PluginManager::dispatchAuditLog($deleted);

            $events = AuditLogEvents::read();
            $this->assertCount(3, $events);
            $this->assertSame(['all', 'filtered'], [$events[1]['handler'], $events[2]['handler']]);
            $this->assertSame($deleted->toArray(), $events[1]['audit_log']);
            $this->assertSame($deleted->toArray(), $events[2]['audit_log']);
        }

        public function testDispatchAuditLogExecutesPluginsInOrder(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.first', ['event_handlers' => ['event' => 'AUDIT_LOG', 'class' => RecursiveAuditLogEventHandler::class]]),
                new Plugin('com.example.second', ['event_handlers' => ['event' => 'AUDIT_LOG', 'class' => self::EVENT_HANDLER_CLASS]]),
            ]);
            AuditLogEvents::reset();

            $auditLog = $this->createAuditLog(AuditLogType::ENTITY_PUSHED);
            PluginManager::dispatchAuditLog($auditLog);

            // The nested dispatch from within the first event handler is ignored, so every handler executes once
            $this->assertSame([$auditLog->getUuid()], RecursiveAuditLogEventHandler::$received);
            $this->assertCount(1, AuditLogEvents::read());

            // Dispatching works again once the previous dispatch completed
            PluginManager::dispatchAuditLog($auditLog);
            $this->assertCount(2, RecursiveAuditLogEventHandler::$received);
        }

        public function testDispatchAuditLogWithoutPlugins(): void
        {
            PluginManager::setPlugins([]);
            PluginManager::dispatchAuditLog($this->createAuditLog(AuditLogType::OTHER));
            $this->assertSame([], AuditLogEvents::read());
        }

        public function testPluginAcceptsSingleRequestHandlerMapping(): void
        {
            $plugin = new Plugin('com.example.plugin', [
                'request_handlers' => ['path' => '/foo/bar', 'class' => self::HANDLER_CLASS, 'request_method' => 'GET, POST'],
            ]);

            $this->assertCount(1, $plugin->getRequestHandlers());
            $this->assertSame('/foo/bar', $plugin->getRequestHandlers()[0]->getPath());
        }

        public function testPluginReportsInvalidRequestHandlerIndex(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('invalid request handler at index 1');

            new Plugin('com.example.plugin', [
                'request_handlers' => [
                    ['path' => '/foo', 'class' => self::HANDLER_CLASS, 'request_method' => 'GET'],
                    ['path' => '/bar', 'class' => self::HANDLER_CLASS],
                ],
            ]);
        }

        public function testLoadRejectsInvalidPackageName(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('Invalid plugin name');
            Plugin::load('not a package');
        }

        public function testLoadFailsForMissingPackage(): void
        {
            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('Failed to import the plugin');
            Plugin::load('net.nosial.plugin_that_does_not_exist');
        }

        public function testLoadFailsForPackageThatIsNotAPlugin(): void
        {
            // Imported as a dependency of FederationLib, a package that is not a plugin
            $this->assertTrue(Runtime::isImported('net.nosial.loglib2'));

            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('is not a FederationLib plugin');
            Plugin::load('net.nosial.loglib2');
        }

        public function testLoadTestPlugin(): void
        {
            $plugin = Plugin::load(self::TEST_PLUGIN);

            $this->assertSame(self::TEST_PLUGIN, $plugin->getPackage());
            $this->assertSame('1.0.0', $plugin->getVersion());
            $this->assertCount(12, $plugin->getRequestHandlers());
            $this->assertCount(5, $plugin->getEventHandlers());
            $this->assertCount(2, $plugin->getEventHandlers(EventType::AUDIT_LOG));
            $this->assertCount(1, $plugin->getEventHandlers(EventType::CONTENT_SCAN));
            $this->assertCount(1, $plugin->getEventHandlers(EventType::QUERY_ENTITY));
            $this->assertCount(1, $plugin->getEventHandlers(EventType::RECORD_CHANGE));
            $this->assertSame([], $plugin->getEventHandlers(EventType::AUDIT_LOG)[0]->getFilter());
            $this->assertSame(['OPERATOR_DELETED'], $plugin->getEventHandlers(EventType::AUDIT_LOG)[1]->getFilter());

            $priorities = array_map(fn(RequestHandlerDefinition $definition) => $definition->getExecutionPriority()?->value, $plugin->getRequestHandlers());
            $this->assertContains(ExecutionPriority::PRE_REQUEST->value, $priorities);
            $this->assertContains(ExecutionPriority::POST_REQUEST->value, $priorities);
            $this->assertContains(ExecutionPriority::OVERRIDE->value, $priorities);
            $this->assertContains(null, $priorities);
        }

        public function testTestPluginRoutesAreValid(): void
        {
            $result = PluginManager::validateRoutes([Plugin::load(self::TEST_PLUGIN)]);
            $this->assertSame([], $result['errors']);
            $this->assertSame([], $result['warnings']);
        }

        public function testPluginsConfiguration(): void
        {
            $configuration = new PluginsConfiguration(['net.nosial.test_plugin', ' com.example.plugin ', '', 'net.nosial.test_plugin', 42]);
            $this->assertTrue($configuration->hasPlugins());
            $this->assertSame(['net.nosial.test_plugin', 'com.example.plugin'], $configuration->getPlugins());

            // FEDERATION_PLUGINS is a comma-separated string
            $configuration = new PluginsConfiguration('net.nosial.test_plugin, com.example.plugin,');
            $this->assertSame(['net.nosial.test_plugin', 'com.example.plugin'], $configuration->getPlugins());

            $this->assertFalse(new PluginsConfiguration(null)->hasPlugins());
            $this->assertFalse(new PluginsConfiguration('')->hasPlugins());
            $this->assertFalse(new PluginsConfiguration([])->hasPlugins());
        }

        public function testRouteWithoutPriorityConflictingWithBuiltInRouteIsAnError(): void
        {
            $result = PluginManager::validateRoutes([
                $this->createPlugin('com.example.a', [['path' => '/info', 'request_method' => 'GET']]),
            ]);

            $this->assertCount(1, $result['errors']);
            $this->assertStringContainsString('already handled by FederationLib', $result['errors'][0]);
            $this->assertStringContainsString('GET_SERVER_INFORMATION', $result['errors'][0]);
        }

        public function testRouteWithPriorityOnBuiltInRouteIsValid(): void
        {
            $result = PluginManager::validateRoutes([
                $this->createPlugin('com.example.a', [
                    ['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'PRE_REQUEST'],
                    ['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'POST_REQUEST'],
                    ['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'OVERRIDE'],
                ]),
            ]);

            $this->assertSame([], $result['errors']);
            $this->assertSame([], $result['warnings']);
        }

        public function testDuplicatePluginRoutesAreAnError(): void
        {
            $result = PluginManager::validateRoutes([
                $this->createPlugin('com.example.a', [['path' => '/plugin/route', 'request_method' => 'GET, POST']]),
                $this->createPlugin('com.example.b', [['path' => '/plugin/route', 'request_method' => 'POST']]),
            ]);

            $this->assertCount(1, $result['errors']);
            $this->assertStringContainsString('com.example.a', $result['errors'][0]);
            $this->assertStringContainsString('POST /plugin/route', $result['errors'][0]);
        }

        public function testDuplicateOverridesAreAnError(): void
        {
            $result = PluginManager::validateRoutes([
                $this->createPlugin('com.example.a', [['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'OVERRIDE']]),
                $this->createPlugin('com.example.b', [['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'OVERRIDE']]),
            ]);

            $this->assertCount(1, $result['errors']);
            $this->assertStringContainsString('already overridden', $result['errors'][0]);
        }

        public function testHooksOnUnhandledRoutesAreWarnings(): void
        {
            $result = PluginManager::validateRoutes([
                $this->createPlugin('com.example.a', [
                    ['path' => '/plugin/route', 'request_method' => 'GET'],
                    // Hooks a route provided by a plugin, valid
                    ['path' => '/plugin/route', 'request_method' => 'GET', 'execution_priority' => 'POST_REQUEST'],
                    // Hooks a route that nothing handles, never executed
                    ['path' => '/nothing/here', 'request_method' => 'GET', 'execution_priority' => 'PRE_REQUEST'],
                ]),
            ]);

            $this->assertSame([], $result['errors']);
            $this->assertCount(1, $result['warnings']);
            $this->assertStringContainsString('/nothing/here', $result['warnings'][0]);
        }

        public function testMatchRequestGroupsHandlersByPriority(): void
        {
            PluginManager::setPlugins([
                $this->createPlugin('com.example.a', [
                    ['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'PRE_REQUEST'],
                    ['path' => '/info', 'request_method' => 'GET', 'execution_priority' => 'POST_REQUEST'],
                    ['path' => '/plugin/{name}', 'request_method' => 'GET'],
                ]),
                $this->createPlugin('com.example.b', [
                    ['path' => '/*', 'request_method' => 'GET', 'execution_priority' => 'PRE_REQUEST'],
                ]),
            ]);

            $route = PluginManager::matchRequest('GET', '/info');
            $this->assertCount(2, $route->getPreRequestHandlers());
            $this->assertSame('com.example.a', $route->getPreRequestHandlers()[0]->getPlugin()->getPackage());
            $this->assertSame('com.example.b', $route->getPreRequestHandlers()[1]->getPlugin()->getPackage());
            $this->assertCount(1, $route->getPostRequestHandlers());
            $this->assertFalse($route->hasHandler());

            $route = PluginManager::matchRequest('GET', '/plugin/foo');
            $this->assertTrue($route->hasHandler());
            $this->assertSame(['name' => 'foo'], $route->getRequestHandler()->getPathParameters());
            $this->assertNull($route->getOverrideHandler());

            $route = PluginManager::matchRequest('POST', '/plugin/foo');
            $this->assertFalse($route->hasHandler());
            $this->assertSame([], $route->getPreRequestHandlers());
        }

        public function testFirstOverrideTakesPrecedence(): void
        {
            $route = new PluginRoute();
            $first = $this->createMatch('com.example.a', 'OVERRIDE');
            $second = $this->createMatch('com.example.b', 'OVERRIDE');

            $this->assertTrue($route->add($first));
            $this->assertFalse($route->add($second));
            $this->assertSame($first, $route->getOverrideHandler());
            $this->assertTrue($route->hasHandler());
        }

        private function createContentScan(string $textContent): ContentScan
        {
            return new ContentScan([new ContentInput($textContent)], null, null, []);
        }

        private function createAuditLog(AuditLogType $type): AuditLog
        {
            return new AuditLog([
                'uuid' => '01890a5d-ac96-774b-bcce-b302099a8057',
                'type' => $type,
                'message' => 'Test audit log entry',
                'operator' => '01890a5d-ac96-774b-bcce-b302099a8058',
                'entity' => '01890a5d-ac96-774b-bcce-b302099a8059',
                'blacklist' => null,
                'evidence' => null,
                'file_attachment' => null,
                'timestamp' => 1700000000,
            ]);
        }

        private function createPlugin(string $package, array $requestHandlers): Plugin
        {
            return new Plugin($package, [
                'request_handlers' => array_map(fn(array $handler) => $handler + ['class' => self::HANDLER_CLASS], $requestHandlers),
            ]);
        }

        private function createMatch(string $package, ?string $executionPriority): MatchedRequestHandler
        {
            $handler = ['path' => '/info', 'request_method' => 'GET'];
            if($executionPriority !== null)
            {
                $handler['execution_priority'] = $executionPriority;
            }

            $plugin = $this->createPlugin($package, [$handler]);
            return new MatchedRequestHandler($plugin, $plugin->getRequestHandlers()[0], []);
        }
    }

    class RecursiveAuditLogEventHandler implements AuditLogEventHandlerInterface
    {
        /** @var string[] */
        public static array $received = [];

        public static function handleAuditLog(AuditLog $auditLog): void
        {
            self::$received[] = $auditLog->getUuid();
            PluginManager::dispatchAuditLog($auditLog);
        }
    }

    class FailingContentScanEventHandler implements ContentScanEventHandlerInterface
    {
        public static int $executions = 0;

        public static function handleContentScan(ContentScan $contentScan): void
        {
            self::$executions++;
            $contentScan->addScanResult('FAILING_HANDLER', -100.0);
            throw new \RuntimeException('FailingContentScanEventHandler intentionally failed');
        }
    }

    /**
     * A RECORD_CHANGE event handler that records the changes it receives
     */
    class RecordingRecordChangeEventHandler implements RecordChangeEventHandlerInterface
    {
        /** @var array<array{string, string}> */
        public static array $received = [];

        public static function handleRecordChange(RecordChange $change): void
        {
            self::$received[] = [$change->getType()->value, $change->getUuid()];
        }
    }

    class RecursiveRecordChangeEventHandler implements RecordChangeEventHandlerInterface
    {
        /** @var string[] */
        public static array $received = [];

        public static function handleRecordChange(RecordChange $change): void
        {
            self::$received[] = $change->getUuid();
            PluginManager::dispatchRecordChange($change->getType(), $change->getUuid());
        }
    }

    class FailingRecordChangeEventHandler implements RecordChangeEventHandlerInterface
    {
        public static function handleRecordChange(RecordChange $change): void
        {
            throw new \RuntimeException('FailingRecordChangeEventHandler intentionally failed');
        }
    }
