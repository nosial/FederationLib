<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\CLI\MaintenanceCommand;
    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    /**
     * Runs `federationlib maintenance` within the request, as the crontab of the container does on its interval, so
     * that the test units do not have to wait for it. Restricted to operators with management and operator permissions.
     */
    class RunMaintenanceHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $operator = FederationServer::requireAuthenticatedOperator();
            if(!$operator->hasManagementPermissions() || !$operator->hasOperatorPermissions())
            {
                throw new RequestException('Running maintenance requires management and operator permissions', HttpResponseCode::FORBIDDEN);
            }

            ob_start();
            try
            {
                $exitCode = MaintenanceCommand::handle([]);
            }
            finally
            {
                $output = (string)ob_get_clean();
            }

            self::successResponse([
                'exit_code' => $exitCode,
                'output' => $output
            ]);
        }
    }
