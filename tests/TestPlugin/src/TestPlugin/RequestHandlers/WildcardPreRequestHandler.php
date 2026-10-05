<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;

    /**
     * PRE_REQUEST handler for every route under /test-plugin/, it only does something beforehand (adds a header) and
     * never prevents the request handler from executing
     */
    class WildcardPreRequestHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            header('X-Test-Plugin-Wildcard: executed');
        }
    }
