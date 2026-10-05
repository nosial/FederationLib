<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;
    use TestPlugin\ContentScanEvents;

    /**
     * Exposes (GET) or resets (DELETE) the most recent scan inspected by the CONTENT_SCAN event handler
     */
    class ContentScanEventsHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            FederationServer::requireAuthenticatedOperator();

            if(FederationServer::getRequestMethod() === 'DELETE')
            {
                ContentScanEvents::reset();
            }

            self::successResponse(ContentScanEvents::read());
        }
    }
