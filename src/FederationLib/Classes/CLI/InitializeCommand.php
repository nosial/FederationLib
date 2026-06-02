<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;
    use InvalidArgumentException;

    class InitializeCommand implements CommandLineInterface
    {

        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            try
            {
                Logger::log()->info('Initializing Database');
                DatabaseConnection::initializeDatabase();
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->critical('Failed to initialize the database: ' . $e->getMessage(), $e);
                return 1;
            }

            Logger::log()->info('Database initialized successfully');

            try
            {
                $masterOperator = OperatorManager::getMasterOperator();
                $uuid = $masterOperator->getUuid();
                $fixed = false;

                if($masterOperator->isDisabled())
                {
                    Logger::log()->warning('Root operator was disabled, re-enabling');
                    OperatorManager::enableOperator($uuid);
                    $fixed = true;
                }

                if(!$masterOperator->canManageOperators())
                {
                    Logger::log()->warning('Root operator missing manage_operators permission, fixing');
                    OperatorManager::setManageOperators($uuid, true);
                    $fixed = true;
                }

                if(!$masterOperator->canManageBlacklist())
                {
                    Logger::log()->warning('Root operator missing manage_blacklist permission, fixing');
                    OperatorManager::setManageBlacklist($uuid, true);
                    $fixed = true;
                }

                if(!$masterOperator->isClient())
                {
                    Logger::log()->warning('Root operator missing is_client permission, fixing');
                    OperatorManager::setClient($uuid, true);
                    $fixed = true;
                }

                if($fixed)
                {
                    Logger::log()->info('Root operator properties corrected successfully');
                }
            }
            catch (DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->critical('Failed to validate/fix root operator: ' . $e->getMessage(), $e);
                return 1;
            }

            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage: federationserver init";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Initializes FederationLib's database";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return null;
        }
    }