<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Objects\Plugin\Maintenance;

    interface MaintenanceEventHandlerInterface
    {
        /**
         * Handles a maintenance run: every run of `federationlib maintenance` (hourly in the docker image) dispatches
         * it after FederationLib cleaned its records, whether or not cleaning is enabled.
         *
         * Use it for a plugin's own periodic work, such as removing the records it keeps itself. Any exception thrown
         * is logged and makes the maintenance run exit with an error, the remaining MAINTENANCE event handlers are
         * still executed.
         *
         * @param Maintenance $maintenance The maintenance run
         * @return void
         */
        public static function handleMaintenance(Maintenance $maintenance): void;
    }
