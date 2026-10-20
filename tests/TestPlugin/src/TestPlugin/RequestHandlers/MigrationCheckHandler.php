<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use TestPlugin\MigrationCheck;

    /**
     * Checks the upgrade of a populated 1.0.14 database to the current schema in scratch databases (see
     * MigrationCheck). Restricted to operators with management and operator permissions.
     */
    class MigrationCheckHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $operator = FederationServer::requireAuthenticatedOperator();
            if(!$operator->hasManagementPermissions() || !$operator->hasOperatorPermissions())
            {
                throw new RequestException('The migration check requires management and operator permissions', HttpResponseCode::FORBIDDEN);
            }

            self::successResponse(MigrationCheck::run());
        }
    }
