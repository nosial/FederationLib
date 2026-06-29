<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;

    class CreateOperator implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            /** @noinspection PhpConditionCheckedByNextConditionInspection */
            if(!isset($args['name']) || empty($args['name']))
            {
                print("Error: Operator name is required.\n");
                return 1;
            }

            $name = $args['name'];
            if(strtolower($name) === 'root' || strtolower($name) === 'system')
            {
                print("Error: Operator name '" . $name . "' is reserved.\n");
                return 1;
            }


            $operatorPermissions = isset($args['operator-permissions']) && $args['operator-permissions'] === true ?? false;
            $managementPermissions = isset($args['management-permissions']) && $args['management-permissions'] === true ?? false;
            $clientPermissions = isset($args['client-permissions']) && $args['client-permissions'] === true ?? false;

            try
            {
                print(sprintf("Creating operator %s\n", $name));
                $operatorUuid = OperatorManager::createOperator($name);
                print(sprintf("Operator %s created successfully\n", $operatorUuid));

                if($operatorPermissions)
                {
                    print("Setting operator permissions\n");
                    OperatorManager::setOperatorPermissions($operatorUuid, true);
                }

                if($managementPermissions)
                {
                    print("Setting management permissions\n");
                    OperatorManager::setManagementPermissions($operatorUuid, true);
                }

                if($clientPermissions)
                {
                    print("Setting client permissions\n");
                    OperatorManager::setClientPermissions($operatorUuid, true);
                }

                $masterOperator = OperatorManager::getRootOperator();

                AuditLogManager::createEntry(AuditLogType::OPERATOR_CREATED, sprintf(
                    "Operator '%s' (%s) created. Operator Permissions: %s, Management Permissions: %s, Client Permissions: %s",
                    $name,
                    $operatorUuid,
                    $operatorPermissions ? 'true' : 'false',
                    $managementPermissions ? 'true' : 'false',
                    $clientPermissions ? 'true' : 'false'
                ), $masterOperator->getUuid());
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
            return "Usage:\n" .
                   "  federationserver create-operator --name <name> [--operator-permissions] [--management-permissions] [--client-permissions]\n" .
                   "\nDescription:\n" .
                   "  Creates a new operator with the specified permissions.\n" .
                   "\nOptions:\n" .
                   "  --name <name>                The name of the operator to create. (required)\n" .
                   "  --operator-permissions       If set, the operator can manage other operators.\n" .
                   "  --management-permissions     If set, the operator has management permissions.\n" .
                   "  --client-permissions         If set, the operator has client permissions.\n";
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
            return "Examples:\n" .
                   "  federationserver create-operator --name 'John Doe' --operator-permissions --client-permissions\n" .
                   "    Creates a new operator named 'John Doe' who can manage other operators and has client permissions.\n";
        }
    }

