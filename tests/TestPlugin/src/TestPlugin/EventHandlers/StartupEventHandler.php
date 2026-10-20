<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Interfaces\StartupEventHandlerInterface;
    use FederationLib\Objects\Plugin\Startup;
    use TestPlugin\LifecycleEvents;

    /**
     * A STARTUP event handler that records every start of the server
     */
    class StartupEventHandler implements StartupEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleStartup(Startup $startup): void
        {
            LifecycleEvents::recordStartup($startup);
        }
    }
