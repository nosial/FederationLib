<?php

    namespace FederationServer\Classes\CLI;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Interfaces\CommandLineInterface;

    class CreateOperator implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            if(!isset($args['name']) || empty($args['name']))
            {
                print("Error: Operator name is required.\n");
                return 1;
            }

            $name = $args['name'];
            $manageOperators = isset($args['manage-operators']) && $args['manage-operators'] === true ?? false;
            $manageBlacklist = isset($args['manage-blacklist']) && $args['manage-blacklist'] === true ?? false;
            $isClient = isset($args['is-client']) && $args['is-client'] === true ?? false;

            try
            {
                print(sprintf("Creating operator %s\n", $name));
                $operatorUuid = OperatorManager::createOperator($name);
                print(sprintf("Operator %s created successfully\n", $operatorUuid));

                if($manageOperators)
                {
                    print("Setting manage operators permissions\n");
                    OperatorManager::setManageOperators($operatorUuid, true);
                }

                if($manageBlacklist)
                {
                    print("Setting manage blacklist permissions\n");
                    OperatorManager::setManageBlacklist($operatorUuid, true);
                }

                if($isClient)
                {
                    print("Setting client permissions\n");
                    OperatorManager::setClient($operatorUuid, true);
                }
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to create operator: ' . $e->getMessage(), $e);
                return 1;
            }

            // Simulate success
            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return
                "Usage: federationserver create-operator --name <name> --manage-operators --manage-blacklist --is-client\n" .
                "Creates a new operator with the specified permissions.\n" .
                "Options:\n" .
                "  --name <name>            The name of the operator to create.\n" .
                "  --manage-operators       Optional. If set, the operator can manage other operators.\n" .
                "  --manage-blacklist       Optional. If set, the operator can manage the blacklist.\n" .
                "  --is-client              Optional. If set, the operator is a client operator.";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Creates a new operator with specified permissions.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Example: federationserver create-operator --name 'John Doe' --manage-operators --is-client\n" .
                   "This command creates a new operator named 'John Doe' who can manage other operators and is a client operator.";
        }
    }