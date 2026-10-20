<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Objects\Plugin\Startup;

    interface StartupEventHandlerInterface
    {
        /**
         * Handles the start of the server, once per start: `federationlib init` (run by the docker entrypoint every
         * time the container starts) dispatches it after the database was initialized and migrated, and before the web
         * server and the scheduled jobs are started, so the server does not answer requests yet.
         *
         * Use it for work a plugin must do before the server serves requests, such as creating its own tables. Any
         * exception thrown is logged and fails `federationlib init`, so the container does not start with a plugin
         * that could not be set up; the remaining STARTUP event handlers are not executed.
         *
         * @param Startup $startup The start of the server
         * @return void
         */
        public static function handleStartup(Startup $startup): void;
    }
