<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Objects\Plugin\RecordChange;

    interface RecordChangeEventHandlerInterface
    {
        /**
         * Handles a change that was written to the database, such as a created report, a closed report, a classified
         * evidence record or a deleted entity. The change contains its type and the UUID of the record, the current
         * state of the record can be retrieved with RecordChange::getRecord().
         *
         * Event handlers are executed synchronously in the process that made the change (which may be a request), they
         * must not send a response. Any exception thrown is logged and does not affect the change or the operation
         * that made it.
         *
         * @param RecordChange $change The change that was written to the database
         * @return void
         */
        public static function handleRecordChange(RecordChange $change): void;
    }
