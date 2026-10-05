<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use TestPlugin\Configuration;

    /**
     * A new route provided by the plugin, exposes the plugin's own configuration so the test units can verify it
     */
    class PingHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse([
                'plugin' => self::getPlugin()->getPackage(),
                'version' => self::getPlugin()->getVersion(),
                'greeting' => Configuration::get('greeting'),
                'nested_value' => Configuration::get('nested.value'),
                'missing_value' => Configuration::get('does.not.exist', 'default'),
                'configuration' => Configuration::getConfiguration(),
                'execution_priority' => self::getExecutionPriority()?->value
            ]);
        }
    }
