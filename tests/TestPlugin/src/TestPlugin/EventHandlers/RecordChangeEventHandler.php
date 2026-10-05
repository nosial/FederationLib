<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Objects\Plugin\RecordChange;
    use TestPlugin\RecordChangeEvents;

    /**
     * A RECORD_CHANGE event handler that records every change written to the database
     */
    class RecordChangeEventHandler implements RecordChangeEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRecordChange(RecordChange $change): void
        {
            RecordChangeEvents::record($change);
        }
    }
