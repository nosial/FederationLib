<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\RequestHandler;
    use FederationLib\FederationServer;

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