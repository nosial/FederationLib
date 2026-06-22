<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;

    class RefreshOperatorAccessToken implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            /** @noinspection PhpConditionCheckedByNextConditionInspection */
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

                if(OperatorManager::isRootOperator($uuid))
                {
                    print("Error: Cannot refresh access token for the root operator.\n");
                    return 1;
                }

                $accessToken = OperatorManager::newAccessToken($uuid);
                print("Access Token refreshed successfully.\n");
                print("New Access Token: $accessToken\n");
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to refresh Access Token: ' . $e->getMessage(), $e);
                print("Error: Failed to refresh Access Token. See logs for details.\n");
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
                "  federationserver refresh-access-token --uuid <uuid>\n" .
                "\nDescription:\n" .
                "  Refreshes the Access Token for the specified operator.\n" .
                "\nOptions:\n" .
                "  --uuid <uuid>   The UUID of the operator to refresh the Access Token for. (required)\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Refreshes the Access Token for an operator.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                "  federationserver refresh-access-token --uuid <uuid>\n" .
                "    Refreshes the Access Token for the specified operator.\n";
        }
    }