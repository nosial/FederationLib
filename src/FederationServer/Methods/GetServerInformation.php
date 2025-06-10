<?php

    namespace FederationServer\Methods;

    use FederationServer\Classes\RequestHandler;
    use FederationServer\FederationServer;

    class GetServerInformation extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse(FederationServer::getServerInformation());
        }
    }