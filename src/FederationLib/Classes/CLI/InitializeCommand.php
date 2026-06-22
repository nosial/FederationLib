<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;
    use FederationLib\Objects\OperatorRecord;
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
                /** @var OperatorRecord $operator */
                foreach([OperatorManager::getRootOperator(), OperatorManager::getSystemOperator()] as $operator)
                {
                    if($operator->isDisabled())
                    {
                        Logger::log()->warning(sprintf('%s operator was disabled, re-enabling', $operator->getName()));
                        OperatorManager::enableOperator($operator->getUuid());
                    }

                    if(!$operator->canManageOperators())
                    {
                        Logger::log()->warning(sprintf('%s operator missing manage_operators permission, re-enabling', $operator->getName()));
                        OperatorManager::setManageOperators($operator->getUuid(), true);
                    }

                    if(!$operator->canManageBlacklist())
                    {
                        Logger::log()->warning(sprintf('%s operator missing manage_blacklist permission, re-enabling', $operator->getName()));
                        OperatorManager::setManageBlacklist($operator->getUuid(), true);
                    }

                    if(!$operator->isClient())
                    {
                        Logger::log()->warning(sprintf('%s operator missing is_client permission, re-enabling', $operator->getName()));
                        OperatorManager::setClient($operator->getUuid(), true);
                    }

                    if($operator->getName() === 'system' && $operator->getAccessToken() !== '0')
                    {
                        Logger::log()->warning('The system operator\'s access token has changed, resetting value');
                        OperatorManager::newAccessToken($operator->getUuid(), '0');
                    }
                }
            }
            catch (DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->critical('Failed to initialize/fix a required operator: ' . $e->getMessage(), $e);
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