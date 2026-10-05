<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Interfaces\AuditLogEventHandlerInterface;
    use FederationLib\Objects\AuditLog;
    use RuntimeException;
    use TestPlugin\AuditLogEvents;

    /**
     * An AUDIT_LOG event handler filtered to OPERATOR_DELETED entries, it throws after recording the entry to verify
     * that a failing event handler does not affect the operation that produced the audit log entry
     */
    class FilteredAuditLogEventHandler implements AuditLogEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleAuditLog(AuditLog $auditLog): void
        {
            AuditLogEvents::record('filtered', $auditLog);
            throw new RuntimeException('FilteredAuditLogEventHandler intentionally failed');
        }
    }
