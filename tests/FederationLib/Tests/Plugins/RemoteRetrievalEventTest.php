<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Plugins;

    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RemoteRetrieval;
    use FederationLib\Enums\EventType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RetrievalPurpose;
    use FederationLib\Exceptions\RemoteRetrievalRejectedException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Interfaces\RemoteRetrievalEventHandlerInterface;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\Plugin;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\RemoteRetrievalRequest;
    use FederationLib\Objects\Plugin\ScanFile;
    use FederationLib\Objects\RemoteRetrievalContext;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use TestPlugin\EventHandlers\RemoteRetrievalEventHandler;

    /**
     * Tests the REMOTE_RETRIEVAL event and the file indexes of detections. Nothing is ever connected to: every URL that
     * reaches a connection is denied by a handler first, and the public addresses below are only resolved, never used
     */
    class RemoteRetrievalEventTest extends TestCase
    {
        private const string TEST_PLUGIN = 'net.nosial.test_plugin';
        // A public address literal, it needs no DNS and passes the checks against private addresses
        private const string PUBLIC_URL = 'http://1.1.1.1/file.png';

        protected function setUp(): void
        {
            PluginManager::setPlugins([]);
            RemoteRetrievalEventHandler::$received = [];
            RecordingRemoteRetrievalEventHandler::reset();
        }

        protected function tearDown(): void
        {
            PluginManager::setPlugins([]);
            RecordingRemoteRetrievalEventHandler::reset();
        }

        private static function request(string $url='http://example.com/file.png', RetrievalPurpose $purpose=RetrievalPurpose::ATTACHMENT_UPLOAD): RemoteRetrievalRequest
        {
            return new RemoteRetrievalRequest($url, 'example.com', 80, '93.184.216.34', $url, 0, time() + 30, new RemoteRetrievalContext($purpose));
        }

        private static function plugin(string $package, string $class, ?string $filter=null): Plugin
        {
            return new Plugin($package, ['event_handlers' => array_filter(['event' => 'REMOTE_RETRIEVAL', 'class' => $class, 'filter' => $filter])]);
        }

        public function testEventType(): void
        {
            $this->assertSame(EventType::REMOTE_RETRIEVAL, EventType::tryFromCaseInsensitive('remote_retrieval'));
            $this->assertSame(RemoteRetrievalEventHandlerInterface::class, EventType::REMOTE_RETRIEVAL->getHandlerInterface());
            $this->assertSame(['ATTACHMENT_UPLOAD', 'SCAN_FILE', 'OTHER'], EventType::REMOTE_RETRIEVAL->getFilterValues());
        }

        public function testUrlsArePermittedWithoutHandlers(): void
        {
            $this->assertTrue(PluginManager::dispatchRemoteRetrieval(self::request()));
        }

        public function testDeniedUrlsStopTheRemainingHandlers(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN), self::plugin('com.example.recording', RecordingRemoteRetrievalEventHandler::class)]);

            $this->assertFalse(PluginManager::dispatchRemoteRetrieval(self::request('http://example.com/test_plugin_deny.png')));
            $this->assertSame(0, RecordingRemoteRetrievalEventHandler::$executions);

            $this->assertTrue(PluginManager::dispatchRemoteRetrieval(self::request()));
            $this->assertSame(1, RecordingRemoteRetrievalEventHandler::$executions);
        }

        public function testRejections(): void
        {
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN), self::plugin('com.example.recording', RecordingRemoteRetrievalEventHandler::class)]);

            try
            {
                PluginManager::dispatchRemoteRetrieval(self::request('http://example.com/test_plugin_reject.png'));
                $this->fail('The retrieval must be rejected');
            }
            catch(RemoteRetrievalRejectedException $e)
            {
                $this->assertSame(HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS->value, $e->getCode());
                $this->assertStringContainsString('The TestPlugin does not retrieve this URL', $e->getMessage());
            }

            $this->assertSame(0, RecordingRemoteRetrievalEventHandler::$executions);
        }

        public function testOtherRequestExceptionsAreRejections(): void
        {
            PluginManager::setPlugins([self::plugin('com.example.throwing', ThrowingRemoteRetrievalEventHandler::class)]);

            try
            {
                PluginManager::dispatchRemoteRetrieval(self::request());
                $this->fail('The retrieval must be rejected');
            }
            catch(RemoteRetrievalRejectedException $e)
            {
                // Wrapped so that a content scan can't mistake it for a URL it failed to retrieve, with its message as is
                $this->assertSame(403, $e->getCode());
                $this->assertSame((new RequestException('Not this one', 403))->getMessage(), $e->getMessage());
            }
        }

        public function testFailingHandlersDenyTheUrl(): void
        {
            // A URL a plugin could not check is never retrieved
            PluginManager::setPlugins([Plugin::load(self::TEST_PLUGIN), self::plugin('com.example.recording', RecordingRemoteRetrievalEventHandler::class)]);

            $this->assertFalse(PluginManager::dispatchRemoteRetrieval(self::request('http://example.com/test_plugin_fail.png')));
            $this->assertSame(0, RecordingRemoteRetrievalEventHandler::$executions);
        }

        public function testFiltersSelectThePurpose(): void
        {
            PluginManager::setPlugins([self::plugin('com.example.recording', RecordingRemoteRetrievalEventHandler::class, 'SCAN_FILE')]);

            PluginManager::dispatchRemoteRetrieval(self::request(purpose: RetrievalPurpose::ATTACHMENT_UPLOAD));
            $this->assertSame(0, RecordingRemoteRetrievalEventHandler::$executions);

            PluginManager::dispatchRemoteRetrieval(self::request(purpose: RetrievalPurpose::SCAN_FILE));
            $this->assertSame(1, RecordingRemoteRetrievalEventHandler::$executions);
        }

        public function testHandlersCantStartRetrievals(): void
        {
            PluginManager::setPlugins([self::plugin('com.example.nested', NestedRemoteRetrievalEventHandler::class)]);

            $this->assertTrue(PluginManager::dispatchRemoteRetrieval(self::request()));
            $this->assertFalse(NestedRemoteRetrievalEventHandler::$nestedResult);
        }

        public function testDeniedUrlsAreNeverConnectedToOrWritten(): void
        {
            PluginManager::setPlugins([self::plugin('com.example.denying', DenyingRemoteRetrievalEventHandler::class)]);
            $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fl_remote_retrieval_' . bin2hex(random_bytes(8));

            try
            {
                RemoteRetrieval::download(self::PUBLIC_URL, $destination, 1024, time() + 30,
                    new RemoteRetrievalContext(RetrievalPurpose::SCAN_FILE, null, null, null, 0, 2));
                $this->fail('The URL must be denied');
            }
            catch(RemoteRetrievalRejectedException $e)
            {
                $this->fail('A denied URL is not a rejection: ' . $e->getMessage());
            }
            catch(RequestException $e)
            {
                $this->assertSame(400, $e->getCode());
                $this->assertStringContainsString(RemoteRetrieval::ERROR_NOT_PERMITTED, $e->getMessage());
            }

            $this->assertFileDoesNotExist($destination);

            $request = DenyingRemoteRetrievalEventHandler::$received;
            $this->assertNotNull($request);
            $this->assertSame(self::PUBLIC_URL, $request->getUrl());
            $this->assertSame(self::PUBLIC_URL, $request->getOriginalUrl());
            $this->assertSame('1.1.1.1', $request->getHost());
            $this->assertSame(0, $request->getRedirects());
            $this->assertSame(RetrievalPurpose::SCAN_FILE, $request->getPurpose());
            $this->assertSame(2, $request->getContext()->getFileIndex());
            $this->assertGreaterThan(0, $request->getRemainingTime());
        }

        public function testRetrievalsWithoutContextAreDispatchedToo(): void
        {
            PluginManager::setPlugins([self::plugin('com.example.denying', DenyingRemoteRetrievalEventHandler::class)]);

            $this->expectException(RequestException::class);
            RemoteRetrieval::download(self::PUBLIC_URL, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fl_remote_retrieval_' . bin2hex(random_bytes(8)), 1024);
        }

        public function testRejectRequiresAClientError(): void
        {
            $this->expectException(InvalidArgumentException::class);
            self::request()->reject('Nope', HttpResponseCode::INTERNAL_SERVER_ERROR);
        }

        public function testDetectionsUseTheFileIndexOfTheFile(): void
        {
            // The client named three files, the second (a file URL) could not be retrieved: the files have the indexes 0
            // and 2 but the positions 0 and 1
            $contentScan = new ContentScan([new ContentInput('content')], null, null, [], null, null, null, null, [],
                [0 => [new ScanFile(0, 'a.png', '/nonexistent/a', 1, 'image/png'), new ScanFile(2, 'c.png', '/nonexistent/c', 1, 'image/png')]],
                [0 => ['https://example.com/b.png', 'https://example.com/c.png']]);

            $contentScan->addDetection(IncidentType::SPAM, 0, 'by_index', null, null, true, 2);
            // A position, as a handler written before file URLs passes it, is translated to the file's index
            $contentScan->addDetection(IncidentType::SPAM, 0, 'by_position', null, null, true, 1);
            $contentScan->addDetection(IncidentType::SPAM, 0, 'first', null, null, true, 0);

            $this->assertSame([2, 2, 0], array_map(fn($detection) => $detection->getFileIndex(), $contentScan->getDetections()));
            $this->assertSame(['https://example.com/b.png', 'https://example.com/c.png'], $contentScan->getFileUrls(0));
            $this->assertSame([], $contentScan->getFileUrls(1));

            $this->expectException(InvalidArgumentException::class);
            $contentScan->addDetection(IncidentType::SPAM, 0, 'missing', null, null, true, 5);
        }
    }

    /**
     * A REMOTE_RETRIEVAL event handler that counts its executions
     */
    class RecordingRemoteRetrievalEventHandler implements RemoteRetrievalEventHandlerInterface
    {
        public static int $executions = 0;

        public static function reset(): void
        {
            self::$executions = 0;
            DenyingRemoteRetrievalEventHandler::$received = null;
            NestedRemoteRetrievalEventHandler::$nestedResult = null;
        }

        public static function handleRemoteRetrieval(RemoteRetrievalRequest $request): void
        {
            self::$executions++;
        }
    }

    /**
     * A REMOTE_RETRIEVAL event handler that denies every URL
     */
    class DenyingRemoteRetrievalEventHandler implements RemoteRetrievalEventHandlerInterface
    {
        public static ?RemoteRetrievalRequest $received = null;

        public static function handleRemoteRetrieval(RemoteRetrievalRequest $request): void
        {
            self::$received = $request;
            $request->deny();
        }
    }

    /**
     * A REMOTE_RETRIEVAL event handler that throws a plain RequestException
     */
    class ThrowingRemoteRetrievalEventHandler implements RemoteRetrievalEventHandlerInterface
    {
        public static function handleRemoteRetrieval(RemoteRetrievalRequest $request): void
        {
            throw new RequestException('Not this one', 403);
        }
    }

    /**
     * A REMOTE_RETRIEVAL event handler that tries to dispatch another retrieval
     */
    class NestedRemoteRetrievalEventHandler implements RemoteRetrievalEventHandlerInterface
    {
        public static ?bool $nestedResult = null;

        public static function handleRemoteRetrieval(RemoteRetrievalRequest $request): void
        {
            self::$nestedResult = PluginManager::dispatchRemoteRetrieval(new RemoteRetrievalRequest($request->getUrl(), $request->getHost(), $request->getPort(),
                $request->getAddress(), $request->getOriginalUrl(), 0, time() + 30, $request->getContext()));
        }
    }
