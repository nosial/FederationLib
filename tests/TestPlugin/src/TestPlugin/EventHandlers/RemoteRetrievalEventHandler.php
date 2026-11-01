<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Interfaces\RemoteRetrievalEventHandlerInterface;
    use FederationLib\Objects\Plugin\RemoteRetrievalRequest;
    use RuntimeException;

    class RemoteRetrievalEventHandler implements RemoteRetrievalEventHandlerInterface
    {
        /** @var RemoteRetrievalRequest[] The requests the handler received in this process */
        public static array $received = [];

        /**
         * @inheritDoc
         */
        public static function handleRemoteRetrieval(RemoteRetrievalRequest $request): void
        {
            self::$received[] = $request;
            $url = $request->getUrl();

            if(str_contains($url, 'test_plugin_reject'))
            {
                $request->reject('The TestPlugin does not retrieve this URL', HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS);
            }

            if(str_contains($url, 'test_plugin_deny'))
            {
                $request->deny();
            }

            if(str_contains($url, 'test_plugin_fail'))
            {
                throw new RuntimeException('RemoteRetrievalEventHandler intentionally failed');
            }
        }
    }
