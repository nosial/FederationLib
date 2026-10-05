<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;

    /**
     * OVERRIDE handler for FederationLib's GET /specification.json route, the original handler is never executed
     */
    class SpecificationOverrideHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse([
                'overridden' => true,
                'plugin' => self::getPlugin()->getPackage(),
                'execution_priority' => self::getExecutionPriority()?->value
            ]);
        }
    }
