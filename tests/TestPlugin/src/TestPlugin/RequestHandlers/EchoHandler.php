<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;

    /**
     * A new route with a path placeholder and multiple request methods, echoes the request back
     */
    class EchoHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse([
                'request_method' => FederationServer::getRequestMethod(),
                'path' => FederationServer::getPath(),
                'value' => self::getPathParameter('value'),
                'path_parameters' => self::getPathParameters(),
                'message' => FederationServer::getParameter('message')
            ]);
        }
    }
