<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\BayesianClient;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
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

            Logger::log()->info('Database OK');

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

                    if(!$operator->hasOperatorPermissions())
                    {
                        Logger::log()->warning(sprintf('%s operator missing operator_permissions permission, re-enabling', $operator->getName()));
                        OperatorManager::setOperatorPermissions($operator->getUuid(), true);
                    }

                    if(!$operator->hasManagementPermissions())
                    {
                        Logger::log()->warning(sprintf('%s operator missing management_permissions permission, re-enabling', $operator->getName()));
                        OperatorManager::setManagementPermissions($operator->getUuid(), true);
                    }

                    if(!$operator->hasClientPermissions())
                    {
                        Logger::log()->warning(sprintf('%s operator missing client_permissions permission, re-enabling', $operator->getName()));
                        OperatorManager::setClientPermissions($operator->getUuid(), true);
                    }

                    if($operator->getName() === 'system' && $operator->getAccessToken() !== 'none')
                    {
                        Logger::log()->warning('The system operator\'s access token has changed, resetting value');
                        OperatorManager::newAccessToken($operator->getUuid(), 'none');
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