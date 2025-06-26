<?php

    namespace FederationServer\Classes\CLI;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Interfaces\CommandLineInterface;

    class RefreshOperatorApiKey implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            if (!isset($args['uuid']) || empty($args['uuid']))
            {
                print("Error: Operator UUID is required.\n");
                return 1;
            }

            $uuid = $args['uuid'];
            try
            {
                if (!OperatorManager::operatorExists($uuid))
                {
                    print("Error: Operator with UUID $uuid does not exist.\n");
                    return 1;
                }

                $newApiKey = OperatorManager::refreshApiKey($uuid);
                print("API key refreshed successfully.\n");
                print("New API Key: $newApiKey\n");
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to refresh API key: ' . $e->getMessage(), $e);
                print("Error: Failed to refresh API key. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationserver refresh-apikey --uuid <uuid>\n" .
                "\nDescription:\n" .
                "  Refreshes the API key for the specified operator.\n" .
                "\nOptions:\n" .
                "  --uuid <uuid>   The UUID of the operator to refresh the API key for. (required)\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Refreshes the API key for an operator.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                "  federationserver refresh-apikey --uuid <uuid>\n" .
                "    Refreshes the API key for the specified operator.\n";
        }
    }