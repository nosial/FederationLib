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

                $changed = false;

                if (isset($args['set-client']))
                {
                    $val = filter_var($args['set-client'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val !== null)
                    {
                        OperatorManager::setClient($uuid, $val);
                        print("Set client to " . ($val ? 'true' : 'false') . "\n");
                        $changed = true;
                    }
                }

                if (isset($args['set-manage-operators']))
                {
                    $val = filter_var($args['set-manage-operators'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val !== null)
                    {
                        OperatorManager::setManageOperators($uuid, $val);
                        print("Set manage operators to " . ($val ? 'true' : 'false') . "\n");
                        $changed = true;
                    }
                }

                if (isset($args['set-manage-blacklist']))
                {
                    $val = filter_var($args['set-manage-blacklist'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val !== null)
                    {
                        OperatorManager::setManageBlacklist($uuid, $val);
                        print("Set manage blacklist to " . ($val ? 'true' : 'false') . "\n");
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
                    $masterOperator = OperatorManager::getMasterOperator();
                    AuditLogManager::createEntry(AuditLogType::OPERATOR_PERMISSIONS_CHANGED, sprintf(
                        "Operator with UUID %s has been edited. Changes: %s",
                        $uuid,
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
                "  federationserver edit-operator --uuid <uuid> [--set-client true|false] [--set-manage-operators true|false] [--set-manage-blacklist true|false] [--disable] [--enable]\n" .
                "\nDescription:\n" .
                "  Edits an operator's permissions and status.\n" .
                "\nOptions:\n" .
                "  --uuid <uuid>                   The UUID of the operator to edit. (required)\n" .
                "  --set-client true|false         Set client status.\n" .
                "  --set-manage-operators true|false  Set manage operators permission.\n" .
                "  --set-manage-blacklist true|false Set manage blacklist permission.\n" .
                "  --disable                       Disable the operator.\n" .
                "  --enable                        Enable the operator.\n";
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
                "  federationserver edit-operator --uuid <uuid> --set-client true\n" .
                "  federationserver edit-operator --uuid <uuid> --set-manage-operators false\n" .
                "  federationserver edit-operator --uuid <uuid> --disable\n" .
                "  federationserver edit-operator --uuid <uuid> --enable\n";
        }
    }


