<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    /**
     * PRE_REQUEST handler for FederationLib's GET /info route
     *
     *  - By default it only adds a header, the original request handler is still executed
     *  - With `test_plugin_intercept` it responds, preventing the original request handler from executing
     *  - With `test_plugin_deny` it throws, preventing the original request handler from executing
     */
    class InfoPreRequestHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            header('X-Test-Plugin-Pre-Request: executed');

            if(FederationServer::getParameter('test_plugin_deny') !== null)
            {
                throw new RequestException('Denied by the test plugin', HttpResponseCode::FORBIDDEN);
            }

            if(FederationServer::getParameter('test_plugin_intercept') !== null)
            {
                self::successResponse([
                    'intercepted' => true,
                    'plugin' => self::getPlugin()->getPackage(),
                    'execution_priority' => self::getExecutionPriority()?->value
                ]);
            }
        }
    }
