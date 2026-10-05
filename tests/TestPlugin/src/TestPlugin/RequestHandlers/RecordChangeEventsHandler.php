<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;
    use TestPlugin\RecordChangeEvents;

    /**
     * Exposes (GET) or resets (DELETE) the changes recorded by the RECORD_CHANGE event handler
     */
    class RecordChangeEventsHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            FederationServer::requireAuthenticatedOperator();

            if(FederationServer::getRequestMethod() === 'DELETE')
            {
                RecordChangeEvents::reset();
            }

            self::successResponse(RecordChangeEvents::read());
        }
    }
