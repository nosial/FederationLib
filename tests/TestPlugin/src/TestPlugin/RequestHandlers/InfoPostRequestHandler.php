<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;
    use TestPlugin\PostRequestStats;

    /**
     * POST_REQUEST handler for FederationLib's GET /info route, records each execution so the test units can verify
     * that it was executed after the response. With `test_plugin_post_respond` it attempts to write a response, which
     * FederationLib must ignore since the response has already been sent.
     */
    class InfoPostRequestHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            PostRequestStats::record((int)http_response_code(), self::isResponseSent(), FederationServer::getPath());

            if(FederationServer::getParameter('test_plugin_post_respond') !== null)
            {
                self::successResponse(['post_request' => 'this must never be sent']);
            }
        }
    }
