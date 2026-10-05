<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Interfaces\AuditLogEventHandlerInterface;
    use FederationLib\Objects\AuditLog;
    use TestPlugin\AuditLogEvents;

    /**
     * An AUDIT_LOG event handler without a filter, executed for every audit log entry
     */
    class AuditLogEventHandler implements AuditLogEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleAuditLog(AuditLog $auditLog): void
        {
            AuditLogEvents::record('all', $auditLog);
        }
    }
