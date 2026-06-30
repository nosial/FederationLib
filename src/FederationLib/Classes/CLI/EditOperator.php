<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;

    class EditOperator implements CommandLineInterface
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
                if(!OperatorManager::operatorExists($uuid))
                {
                    print("Error: Operator with UUID $uuid does not exist.\n");
                    return 1;
                }

                if(OperatorManager::isRootOperator($uuid))
                {
                    print("Error: Cannot edit the root operator.\n");
                    return 1;
                }

                $targetOperator = OperatorManager::getOperator($uuid);
                $changed = false;

                if (isset($args['set-client-permissions']))
                {
                    $val = filter_var($args['set-client-permissions'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val !== null)
                    {
                        OperatorManager::setClientPermissions($uuid, $val);
                        print("Set client permissions to " . ($val ? 'true' : 'false') . "\n");
                        $changed = true;
                    }
                }

                if (isset($args['set-operator-permissions']))
                {
                    $val = filter_var($args['set-operator-permissions'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val !== null)
                    {
                        OperatorManager::setOperatorPermissions($uuid, $val);
                        print("Set operator permissions to " . ($val ? 'true' : 'false') . "\n");
                        $changed = true;
                    }
                }

                if (isset($args['set-management-permissions']))
                {
                    $val = filter_var($args['set-management-permissions'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val !== null)
                    {
                        OperatorManager::setManagementPermissions($uuid, $val);
                        print("Set management permissions to " . ($val ? 'true' : 'false') . "\n");
                        $changed = true;
                    }
                }

                if (isset($args['disable']) && $args['disable'] === true)
                {
                    OperatorManager::disableOperator($uuid);
                    print("Operator disabled.\n");
                    $changed = true;
                }

                if (isset($args['enable']) && $args['enable'] === true)
                {
                    OperatorManager::enableOperator($uuid);
                    print("Operator enabled.\n");
                    $changed = true;
                }

                if (!$changed)
                {
                    print("No changes specified.\n");
                }
                else
                {
                    $masterOperator = OperatorManager::getRootOperator();
                    AuditLogManager::createEntry(AuditLogType::OPERATOR_PERMISSIONS_CHANGED, sprintf(
                        "Operator %s has been edited. Changes: %s",
                        $targetOperator->getName(),
                        json_encode($args)
                    ), $masterOperator->getUuid());
                }
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to edit operator: ' . $e->getMessage(), $e);
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
                "  federationserver edit-operator --uuid <uuid> [--set-client-permissions true|false] [--set-operator-permissions true|false] [--set-management-permissions true|false] [--disable] [--enable]\n" .
                "\nDescription:\n" .
                "  Edits an operator's permissions and status.\n" .
                "\nOptions:\n" .
                "  --uuid <uuid>                           The UUID of the operator to edit. (required)\n" .
                "  --set-client-permissions true|false     Set client permissions.\n" .
                "  --set-operator-permissions true|false   Set operator permissions.\n" .
                "  --set-management-permissions true|false Set management permissions.\n" .
                "  --disable                               Disable the operator.\n" .
                "  --enable                                Enable the operator.\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Edits an operator's permissions and status.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                "  federationserver edit-operator --uuid <uuid> --set-client-permissions true\n" .
                "  federationserver edit-operator --uuid <uuid> --set-operator-permissions false\n" .
                "  federationserver edit-operator --uuid <uuid> --disable\n" .
                "  federationserver edit-operator --uuid <uuid> --enable\n";
        }
    }


