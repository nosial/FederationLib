<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\RequestHandler;
    use FederationLib\FederationServer;

    class GetSelfOperator extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse(FederationServer::requireAuthenticatedOperator()->toArray());
        }
    }