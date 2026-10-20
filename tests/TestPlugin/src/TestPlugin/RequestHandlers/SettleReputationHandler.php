<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\Managers\ReputationManager;
    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    /**
     * Settles the recorded reputation activity including the current day, as `federationlib reputation settle` would
     * on the next day, so that the test units do not have to wait for the day to end. Restricted to operators with
     * management and operator permissions.
     */
    class SettleReputationHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $operator = FederationServer::requireAuthenticatedOperator();
            if(!$operator->hasManagementPermissions() || !$operator->hasOperatorPermissions())
            {
                throw new RequestException('Settling reputation requires management and operator permissions', HttpResponseCode::FORBIDDEN);
            }

            self::successResponse(ReputationManager::settle(time() + 86400));
        }
    }
