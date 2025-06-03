<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\RequestHandler;
    use FederationServer\FederationServer;

    class GetSelfOperator extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse(FederationServer::getAuthenticatedOperator()->toArray());
        }
    }