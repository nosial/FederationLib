<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Interfaces\MaintenanceEventHandlerInterface;
    use FederationLib\Objects\Plugin\Maintenance;
    use TestPlugin\LifecycleEvents;

    /**
     * A MAINTENANCE event handler that records every maintenance run
     */
    class MaintenanceEventHandler implements MaintenanceEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleMaintenance(Maintenance $maintenance): void
        {
            LifecycleEvents::recordMaintenance($maintenance);
        }
    }
