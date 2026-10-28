<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Plugins;

    use Exception;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Enums\EventType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\PluginException;
    use FederationLib\Interfaces\MaintenanceEventHandlerInterface;
    use FederationLib\Interfaces\StartupEventHandlerInterface;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\Plugin;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\EventHandlerDefinition;
    use FederationLib\Objects\Plugin\Maintenance;
    use FederationLib\Objects\Plugin\Startup;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\Detection;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use TestPlugin\LifecycleEvents;

    /**
     * The STARTUP and MAINTENANCE events, and the concerns and detections of content scans, in-process. Nothing here
     * reads the configuration of FederationLib, the plugins are always set explicitly.
     */
    class PluginLifecycleTest extends TestCase
    {
        private const string TEST_PLUGIN = 'net.nosial.test_plugin';

        protected function setUp(): void
        {
            @unlink(LifecycleEvents::getPath());
            RecordingLifecycleEventHandler::$received = [];
        }

        protected function tearDown(): void
        {
            PluginManager::setPlugins([]);
            @unlink(LifecycleEvents::getPath());
            RecordingLifecycleEventHandler::$received = [];
        }

        public function testLifecycleEventTypes(): void
        {
            $this->assertSame(EventType::STARTUP, EventType::from('STARTUP'));
            $this->assertSame(EventType::MAINTENANCE, EventType::from('MAINTENANCE'));
        }

        public function testTestPluginDeclaresLifecycleEventHandlers(): void
        {
            $plugin = Plugin::load(self::TEST_PLUGIN);

            $this->assertSame(['TestPlugin\EventHandlers\StartupEventHandler'], array_values(array_map(fn($definition) => ltrim($definition->getClass(), '\\'), $plugin->getEventHandlers(EventType::STARTUP))));
            $this->assertSame(['TestPlugin\EventHandlers\MaintenanceEventHandler'], array_values(array_map(fn($definition) => ltrim($definition->getClass(), '\\'), $plugin->getEventHandlers(EventType::MAINTENANCE))));
        }

        public function testLifecycleEventHandlerMustImplementItsInterface(): void
        {
            $this->assertSame(StartupEventHandlerInterface::class, EventType::STARTUP->getHandlerInterface());
            $this->assertSame(MaintenanceEventHandlerInterface::class, EventType::MAINTENANCE->getHandlerInterface());

            (new EventHandlerDefinition(EventType::MAINTENANCE, RecordingMaintenanceOnlyEventHandler::class))->validateClass();

            $this->expectException(PluginException::class);
            $this->expectExceptionMessage('must implement');
            (new EventHandlerDefinition(EventType::STARTUP, RecordingMaintenanceOnlyEventHandler::class))->validateClass();
        }

        public function testStartupObject(): void
        {
            $startup = new Startup(1767225600, false, '1.0');

            $this->assertSame(1767225600, $startup->getStartedAt());
            $this->assertFalse($startup->isDatabaseCreated());
            $this->assertSame('1.0', $startup->getMigratedFrom());
            $this->assertSame(DatabaseConnection::SCHEMA_VERSION, $startup->getSchemaVersion());

            $created = new Startup(1767225600, true, null);
            $this->assertTrue($created->isDatabaseCreated());
            $this->assertNull($created->getMigratedFrom());
        }

        public function testMaintenanceObject(): void
        {
            $maintenance = new Maintenance(1767225600, true, '/var/backups', ['evidence' => 3, 'audit log' => 10]);

            $this->assertSame(1767225600, $maintenance->getStartedAt());
            $this->assertTrue($maintenance->isCleaningEnabled());
            $this->assertSame('/var/backups', $maintenance->getArchivePath());
            $this->assertSame(['evidence' => 3, 'audit log' => 10], $maintenance->getRemoved());
        }

        public function testDispatchStartupToTestPlugin(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $this->assertTrue(PluginManager::dispatchStartup(new Startup(1767225600, false, '1.0')));

            $events = LifecycleEvents::read();
            $this->assertCount(1, $events);
            $this->assertSame([
                'event' => 'STARTUP',
                'started_at' => 1767225600,
                'database_created' => false,
                'migrated_from' => '1.0',
                'schema_version' => DatabaseConnection::SCHEMA_VERSION,
            ], $events[0]);
        }

        public function testDispatchMaintenanceToTestPlugin(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);

            $this->assertTrue(PluginManager::dispatchMaintenance(new Maintenance(1767225600, false, null, [])));
            $this->assertTrue(PluginManager::dispatchMaintenance(new Maintenance(1767229200, true, null, ['evidence' => 2])));

            $events = LifecycleEvents::read();
            $this->assertCount(2, $events);
            $this->assertSame(['event' => 'MAINTENANCE', 'started_at' => 1767225600, 'cleaning_enabled' => false, 'archive_path' => null, 'removed' => []], $events[0]);
            $this->assertSame(['event' => 'MAINTENANCE', 'started_at' => 1767229200, 'cleaning_enabled' => true, 'archive_path' => null, 'removed' => ['evidence' => 2]], $events[1]);
        }

        public function testResetMaintenanceKeepsStartup(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN)]);
            PluginManager::dispatchStartup(new Startup(1767225600, true, null));
            PluginManager::dispatchMaintenance(new Maintenance(1767225600, true, null, []));

            LifecycleEvents::resetMaintenance();

            $this->assertSame(['STARTUP'], array_column(LifecycleEvents::read(), 'event'));
        }

        public function testDispatchWithoutPlugins(): void
        {
            PluginManager::setPlugins([]);

            $this->assertTrue(PluginManager::dispatchStartup(new Startup(time(), false, null)));
            $this->assertTrue(PluginManager::dispatchMaintenance(new Maintenance(time(), true, null, [])));
        }

        public function testFailingStartupHandlerFailsTheStart(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.first', ['event_handlers' => ['event' => 'STARTUP', 'class' => FailingLifecycleEventHandler::class]]),
                new Plugin('com.example.second', ['event_handlers' => ['event' => 'STARTUP', 'class' => RecordingLifecycleEventHandler::class]]),
            ]);

            $this->assertFalse(PluginManager::dispatchStartup(new Startup(time(), false, null)));
            // The remaining STARTUP handlers are not executed
            $this->assertSame([], RecordingLifecycleEventHandler::$received);
        }

        public function testFailingMaintenanceHandlerDoesNotStopTheOthers(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.first', ['event_handlers' => ['event' => 'MAINTENANCE', 'class' => FailingLifecycleEventHandler::class]]),
                new Plugin('com.example.second', ['event_handlers' => ['event' => 'MAINTENANCE', 'class' => RecordingLifecycleEventHandler::class]]),
            ]);

            $this->assertFalse(PluginManager::dispatchMaintenance(new Maintenance(time(), true, null, [])));
            $this->assertSame(['MAINTENANCE'], RecordingLifecycleEventHandler::$received);
        }

        public function testLifecycleHandlersRunInPluginOrder(): void
        {
            PluginManager::setPlugins([
                new Plugin('com.example.first', ['event_handlers' => [
                    ['event' => 'STARTUP', 'class' => RecordingLifecycleEventHandler::class],
                    ['event' => 'MAINTENANCE', 'class' => RecordingLifecycleEventHandler::class],
                ]]),
                Plugin::load(self::TEST_PLUGIN),
            ]);

            $this->assertTrue(PluginManager::dispatchStartup(new Startup(time(), false, null)));
            $this->assertTrue(PluginManager::dispatchMaintenance(new Maintenance(time(), true, null, [])));

            $this->assertSame(['STARTUP', 'MAINTENANCE'], RecordingLifecycleEventHandler::$received);
            $this->assertSame(['STARTUP', 'MAINTENANCE'], array_column(LifecycleEvents::read(), 'event'));
        }

        public function testCollectsScanContentProperty(): void
        {
            $this->assertFalse((new Plugin('com.example.plugin', []))->collectsScanContent());
            $this->assertTrue((new Plugin('com.example.plugin', ['collects_scan_content' => true]))->collectsScanContent());

            $this->expectException(PluginException::class);
            new Plugin('com.example.plugin', ['collects_scan_content' => 'yes']);
        }

        public function testContentScanConcerns(): void
        {
            $all = new ContentScan([new ContentInput('content')], null, null, []);
            $this->assertSame(IncidentType::cases(), $all->getConcerns());
            $this->assertTrue($all->isConcernEffective(IncidentType::ADVERTISING));

            $spamOnly = new ContentScan([new ContentInput('content')], null, null, [], null, null, null, [IncidentType::SPAM, IncidentType::PHISHING]);
            $this->assertSame([IncidentType::SPAM, IncidentType::PHISHING], $spamOnly->getConcerns());
            $this->assertTrue($spamOnly->isConcernEffective(IncidentType::SPAM));
            $this->assertFalse($spamOnly->isConcernEffective(IncidentType::ADVERTISING));
        }

        public function testContributionsForIneffectiveConcernsAreDiscarded(): void
        {
            $contentScan = new ContentScan([new ContentInput('content')], null, null, [], null, null, null, [IncidentType::SPAM]);

            $contentScan->addScanResult('ADS_RULE', 10.0, IncidentType::ADVERTISING);
            $contentScan->addScanResult('SPAM_RULE', 5.0, IncidentType::SPAM);
            $contentScan->addDetection(IncidentType::ADVERTISING, 0, 'ad_list');
            $contentScan->addDetection(IncidentType::SPAM, 0, 'spam_list');

            $this->assertSame(['SPAM_RULE' => 5.0], $contentScan->getScanResults());
            $this->assertCount(1, $contentScan->getDetections());
            $this->assertSame('spam_list', $contentScan->getDetections()[0]->getSource());
        }

        public function testAddDetection(): void
        {
            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput('ads.example.com')], null, null, []);
            $contentScan->addDetection(IncidentType::ADVERTISING, 1, 'ad_filter_list', '01890a5d-ac96-774b-bcce-b302099a8058', 0.75, false);

            $detections = $contentScan->getDetections();
            $this->assertCount(1, $detections);
            $this->assertInstanceOf(Detection::class, $detections[0]);
            $this->assertSame([
                'concern' => 'ADVERTISING',
                'content_index' => 1,
                'file_index' => null,
                'entity' => '01890a5d-ac96-774b-bcce-b302099a8058',
                'source' => 'ad_filter_list',
                'confidence' => 0.75,
                'verified' => false,
            ], $detections[0]->toArray());
        }

        public function testAddDetectionRejectsUnknownEvidenceIndex(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('evidence index 1 does not exist');
            (new ContentScan([new ContentInput('content')], null, null, []))->addDetection(IncidentType::SPAM, 1, 'source');
        }

        public function testAddDetectionRejectsEmptySource(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new ContentScan([new ContentInput('content')], null, null, []))->addDetection(IncidentType::SPAM, 0, '  ');
        }

        public function testAddDetectionRejectsNonFiniteConfidence(): void
        {
            $this->expectException(InvalidArgumentException::class);
            (new ContentScan([new ContentInput('content')], null, null, []))->addDetection(IncidentType::SPAM, 0, 'source', null, NAN);
        }

        public function testDetectionsAreRestoredWithState(): void
        {
            $contentScan = new ContentScan([new ContentInput('content')], null, null, []);
            $contentScan->addDetection(IncidentType::SPAM, 0, 'first');
            $state = $contentScan->getState();

            $contentScan->addDetection(IncidentType::SPAM, 0, 'second');
            $contentScan->restoreState($state);

            $this->assertSame(['first'], array_map(fn(Detection $detection) => $detection->getSource(), $contentScan->getDetections()));
        }

        public function testDetectionRoundTrip(): void
        {
            $detection = new Detection(IncidentType::TRACKING, 2, 'tracker_list', null, null, true);
            $this->assertSame($detection->toArray(), Detection::fromArray($detection->toArray())->toArray());
        }

        public function testResolvedAddresses(): void
        {
            $contentScan = new ContentScan([new ContentInput('ads.example.com'), new ContentInput('text')], null, null, [], null, null, null, null, [0 => ['93.184.216.34']]);

            $this->assertSame(['93.184.216.34'], $contentScan->getResolvedAddresses(0));
            $this->assertSame([], $contentScan->getResolvedAddresses(1));
            $this->assertSame([], $contentScan->getResolvedAddresses(5));
        }

        public function testScannedContentKeepsDetectionsAndConcerns(): void
        {
            $detection = new Detection(IncidentType::ADVERTISING, 0, 'ad_list');
            $scannedContent = new ScannedContent([], null, null, [], [$detection, 'not a detection'], [IncidentType::ADVERTISING]);

            $this->assertSame([$detection], $scannedContent->getDetections());
            $this->assertSame([IncidentType::ADVERTISING], $scannedContent->getConcerns());
            $this->assertNull((new ScannedContent([]))->getConcerns());
        }
    }

    class RecordingLifecycleEventHandler implements StartupEventHandlerInterface, MaintenanceEventHandlerInterface
    {
        public static array $received = [];

        public static function handleStartup(Startup $startup): void
        {
            self::$received[] = 'STARTUP';
        }

        public static function handleMaintenance(Maintenance $maintenance): void
        {
            self::$received[] = 'MAINTENANCE';
        }
    }

    class FailingLifecycleEventHandler implements StartupEventHandlerInterface, MaintenanceEventHandlerInterface
    {
        public static function handleStartup(Startup $startup): void
        {
            throw new Exception('The plugin could not be set up');
        }

        public static function handleMaintenance(Maintenance $maintenance): void
        {
            throw new Exception('The plugin could not do its maintenance');
        }
    }

    class RecordingMaintenanceOnlyEventHandler implements MaintenanceEventHandlerInterface
    {
        public static function handleMaintenance(Maintenance $maintenance): void
        {
        }
    }
