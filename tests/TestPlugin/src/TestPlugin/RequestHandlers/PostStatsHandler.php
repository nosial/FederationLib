<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;
    use TestPlugin\PostRequestStats;

    /**
     * Exposes (GET) or resets (DELETE) the executions recorded by InfoPostRequestHandler
     */
    class PostStatsHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            if(FederationServer::getRequestMethod() === 'DELETE')
            {
                FederationServer::requireAuthenticatedOperator();
                PostRequestStats::reset();
            }

            self::successResponse(PostRequestStats::read());
        }
    }
