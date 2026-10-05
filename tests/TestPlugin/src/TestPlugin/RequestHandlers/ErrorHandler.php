<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;

    /**
     * A new route that always fails, FederationLib must turn the exception into an error response
     */
    class ErrorHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            throw new RequestException('Test plugin error', HttpResponseCode::IM_A_TEAPOT);
        }
    }
