<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;

    class DeleteOperator implements CommandLineInterface
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

                OperatorManager::deleteOperator($uuid);
                print("Operator with UUID $uuid has been deleted.\n");
                $masterOperator = OperatorManager::getMasterOperator();

                AuditLogManager::createEntry(AuditLogType::OPERATOR_DELETED, sprintf(
                    "Operator with UUID %s has been deleted.",
                    $uuid
                ), $masterOperator->getUuid());
            }
            catch (DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to delete operator: ' . $e->getMessage(), $e);
                print("Error: Failed to delete operator. See logs for details.\n");
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
                "  federationserver delete-operator --uuid <uuid>\n" .
                "\nDescription:\n" .
                "  Deletes an operator by UUID.\n" .
                "\nOptions:\n" .
                "  --uuid <uuid>   The UUID of the operator to delete. (required)\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Deletes an operator by UUID.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                "  federationserver delete-operator --uuid <uuid>\n" .
                "    Deletes the operator with the specified UUID.\n";
        }
    }