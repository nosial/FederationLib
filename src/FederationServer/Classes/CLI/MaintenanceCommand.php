<?php

    namespace FederationServer\Classes\CLI;

    use Exception;
    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\BlacklistManager;
    use FederationServer\Interfaces\CommandLineInterface;

    class MaintenanceCommand implements CommandLineInterface
    {

        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            if(!Configuration::getMaintenanceConfiguration()->isEnabled())
            {
                print("Maintenance mode is not enabled.\n");
                return 1;
            }

            if(Configuration::getMaintenanceConfiguration()->isCleanAuditLogsEnabled())
            {
                try
                {
                    print("Cleaning audit logs older than " . Configuration::getMaintenanceConfiguration()->getCleanAuditLogsDays() . " days...\n");
                    $cleanedEntries = AuditLogManager::cleanEntries(Configuration::getMaintenanceConfiguration()->getCleanAuditLogsDays());
                }
                catch(Exception $e)
                {
                    Logger::log()->error('Failed to clean audit logs: ' . $e->getMessage(), $e);
                    print("Error: Failed to clean audit logs. See logs for details.\n");
                    return 1;
                }

                if($cleanedEntries > 0)
                {
                    print("Cleaned $cleanedEntries audit log entries older than " . Configuration::getMaintenanceConfiguration()->getCleanAuditLogsDays() . " days.\n");
                }
                else
                {
                    print("No audit log entries were cleaned.\n");
                }
            }

            if(Configuration::getMaintenanceConfiguration()->isCleanBlacklistEnabled())
            {
                try
                {
                    print("Cleaning blacklist entries older than " . Configuration::getMaintenanceConfiguration()->getCleanBlacklistDays() . " days...\n");
                    $cleanedBlacklistEntries = BlacklistManager::cleanEntries(Configuration::getMaintenanceConfiguration()->getCleanBlacklistDays());
                }
                catch(Exception $e)
                {
                    Logger::log()->error('Failed to clean blacklist entries: ' . $e->getMessage(), $e);
                    print("Error: Failed to clean blacklist entries. See logs for details.\n");
                    return 1;
                }

                if($cleanedBlacklistEntries > 0)
                {
                    print("Cleaned $cleanedBlacklistEntries blacklist entries older than " . Configuration::getMaintenanceConfiguration()->getCleanBlacklistDays() . " days.\n");
                }
                else
                {
                    print("No blacklist entries were cleaned.\n");
                }
            }

            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationserver maintenance\n" .
                "\nDescription:\n" .
                "  Runs maintenance tasks such as cleaning old audit logs if enabled in the configuration.";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Runs maintenance tasks such as cleaning old audit logs if enabled in the configuration.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Example:\n" .
                "  federationserver maintenance\n" .
                "\nThis command will run maintenance tasks as configured in the system.\n";
        }
    }