<?php

    namespace TestPlugin\RequestHandlers;

    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\FederationServer;
    use TestPlugin\AuditLogEvents;

    /**
     * Exposes (GET) or resets (DELETE) the audit log entries recorded by the AUDIT_LOG event handlers
     */
    class AuditLogEventsHandler extends PluginRequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            FederationServer::requireAuthenticatedOperator();

            if(FederationServer::getRequestMethod() === 'DELETE')
            {
                AuditLogEvents::reset();
            }

            self::successResponse(AuditLogEvents::read());
        }
    }
