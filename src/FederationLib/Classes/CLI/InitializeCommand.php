<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;

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