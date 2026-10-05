<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;

    /**
     * A new route that requires authentication the same way FederationLib's own routes do
     */
    class WhoAmIHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $operator = FederationServer::requireAuthenticatedOperator();
            self::successResponse([
                'uuid' => $operator->getUuid(),
                'name' => $operator->getName()
            ]);
        }
    }
