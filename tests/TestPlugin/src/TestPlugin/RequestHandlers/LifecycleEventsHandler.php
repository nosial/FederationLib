<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;
    use TestPlugin\LifecycleEvents;

    /**
     * Exposes (GET) the STARTUP and MAINTENANCE events received by the TestPlugin, or resets (DELETE) the recorded
     * MAINTENANCE events
     */
    class LifecycleEventsHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            FederationServer::requireAuthenticatedOperator();

            if(FederationServer::getRequestMethod() === 'DELETE')
            {
                LifecycleEvents::resetMaintenance();
            }

            self::successResponse(LifecycleEvents::read());
        }
    }
