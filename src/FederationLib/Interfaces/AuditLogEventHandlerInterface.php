<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Objects\AuditLog;

    interface AuditLogEventHandlerInterface
    {
        /**
         * Handles an audit log entry after it has been created, the entry contains everything that was recorded about
         * the event (the UUID of the entry, its type, message, timestamp and the UUIDs of the related operator,
         * entity, blacklist record, evidence and file attachment).
         *
         * Event handlers are executed synchronously in the process that created the audit log entry (which may be a
         * request), they must not send a response. Any exception thrown is logged and does not affect the audit log
         * entry or the operation that produced it.
         *
         * @param AuditLog $auditLog The audit log entry that was created
         * @return void
         */
        public static function handleAuditLog(AuditLog $auditLog): void;
    }
