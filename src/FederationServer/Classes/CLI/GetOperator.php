<?php

    namespace FederationServer\Classes\CLI;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Interfaces\CommandLineInterface;
    use FederationServer\Exceptions\DatabaseOperationException;

    class GetOperator implements CommandLineInterface
    {
        private const string ANSI_BOLD = "\033[1m";
        private const string ANSI_CYAN = "\033[36m";
        private const string ANSI_YELLOW = "\033[33m";
        private const string ANSI_RESET = "\033[0m";

        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            $uuid = $args['uuid'] ?? null;
            if (!$uuid || !is_string($uuid))
            {
                print("Missing or invalid 'uuid' parameter.\n");
                return 1;
            }

            try
            {
                $operator = OperatorManager::getOperator($uuid);
                if ($operator === null)
                {
                    print("No operator found with the provided UUID.\n");
                    return 1;
                }

                print(self::ANSI_BOLD . self::ANSI_CYAN . "Operator Information:" . self::ANSI_RESET . "\n");
                print(self::ANSI_YELLOW . json_encode($operator, JSON_PRETTY_PRINT) . self::ANSI_RESET . "\n");
                return 0;
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->error("Failed to retrieve operator: " . $e->getMessage(), $e);
                print("An error occurred while retrieving the operator information.\n");
                return 1;
            }
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                   "  get-operator --uuid <uuid>\n" .
                   "\nDescription:\n" .
                   "  Retrieve information about a specific operator by UUID.\n" .
                   "\nOptions:\n" .
                   "  --uuid <uuid>   UUID of the operator to retrieve. (required)\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Retrieve information about a specific operator by UUID.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                   "  get-operator --uuid 123e4567-e89b-12d3-a456-426614174000\n" .
                   "    Displays information about the operator with the specified UUID.\n";
        }
    }

