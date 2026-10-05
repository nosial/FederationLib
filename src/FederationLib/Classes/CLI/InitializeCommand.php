<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\PluginException;
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
            // Plugins are validated first so that a misconfigured plugin is reported before anything else is done
            if(!self::validatePlugins())
            {
                return 1;
            }

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

            try
            {
                $migratedEntities = EntitiesManager::migrateNonCanonicalHosts();
                if($migratedEntities > 0)
                {
                    Logger::log()->info(sprintf('Migrated %d entities with a leading "www." host label to their canonical host', $migratedEntities));
                }
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->critical('Failed to migrate non-canonical entity hosts: ' . $e->getMessage(), $e);
                return 1;
            }

            return 0;
        }

        /**
         * Imports and validates every plugin in the plugins configuration, reporting every issue that was found
         *
         * @return bool True if all the plugins are correctly configured, false otherwise
         * @throws PluginException Thrown if there was an error while validating the plugins
         */
        private static function validatePlugins(): bool
        {
            $plugins = Configuration::getPluginsConfiguration()->getPlugins();
            if(count($plugins) === 0)
            {
                Logger::log()->info('No plugins configured');
                return true;
            }

            Logger::log()->info(sprintf('Validating %d plugin(s): %s', count($plugins), implode(', ', $plugins)));
            $result = PluginManager::validate();

            foreach($result['warnings'] as $warning)
            {
                Logger::log()->warning($warning);
            }

            if(count($result['errors']) > 0)
            {
                foreach($result['errors'] as $error)
                {
                    Logger::log()->critical($error);
                }

                Logger::log()->critical(sprintf('%d plugin issue(s) must be resolved before FederationLib can start', count($result['errors'])));
                return false;
            }

            foreach(PluginManager::getPlugins() as $plugin)
            {
                Logger::log()->info(sprintf('Plugin %s=%s OK (%d request handler(s), %d event handler(s))', $plugin->getPackage(), $plugin->getVersion() ?? 'unknown', count($plugin->getRequestHandlers()), count($plugin->getEventHandlers())));
            }

            return true;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage: federationlib init";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Validates the configured plugins and initializes FederationLib's database";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return null;
        }
    }