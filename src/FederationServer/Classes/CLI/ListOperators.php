<?php

    namespace FederationServer\Classes\CLI;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Interfaces\CommandLineInterface;

    class ListOperators implements CommandLineInterface
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
            $page = $args['page'] ?? 1;
            $limit = $args['limit'] ?? Configuration::getServerConfiguration()->getListOperatorsMaxItems();

            if(!is_numeric($page) || !is_numeric($limit) || $page < 1 || $limit < 1)
            {
                print("Invalid page or limit value. Both must be positive integers.\n");
                return 1; // Error code
            }

            try
            {
                $operators = OperatorManager::getOperators($limit, $page);
                if(empty($operators))
                {
                    print("No operators found.\n\n");
                    print("Total Operators: " . OperatorManager::getTotalOperatorsCount() . "\n");
                    print("Page: $page, Limit: $limit\n");
                    print("Use 'list-operators --page <page> --limit <limit>' to paginate through results.\n");
                    return 0; // Success code
                }

                foreach($operators as $operator)
                {
                    print(
                        self::ANSI_BOLD . self::ANSI_CYAN . "UUID:" . self::ANSI_RESET . " {$operator->getUuid()}\n" .
                        self::ANSI_BOLD . self::ANSI_YELLOW . "Name:" . self::ANSI_RESET . " {$operator->getName()}\n" .
                        self::ANSI_BOLD . "API Key:" . self::ANSI_RESET . " {$operator->getApiKey()}\n" .
                        str_repeat('-', 50) . "\n"
                    );
                }

                print("\nOperator Count: " . count($operators) . "\n");
                print("Page: $page, Limit: $limit\n");
                print("Total Operators: " . OperatorManager::getTotalOperatorsCount() . "\n");
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error("Failed to list operators: " . $e->getMessage(), $e);
                return 1; // Error code
            }

            return 0; // Success code
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                   "  list-operators [--page <page>] [--limit <limit>]\n" .
                   "\nDescription:\n" .
                   "  List all operators with pagination support.\n" .
                   "\nOptions:\n" .
                   "  --page <page>   Page number to retrieve. (default: 1)\n" .
                   "  --limit <limit> Number of operators per page. (default: " .  Configuration::getServerConfiguration()->getListOperatorsMaxItems() . ")\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "List all operators with pagination support.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                   "  list-operators --page 1 --limit 100\n" .
                   "  list-operators --page 2 --limit 5\n" .
                   "  list-operators\n" .
                   "    Lists operators with pagination, defaulting to page 1 and a limit of 100 if not specified.\n";
        }
    }
