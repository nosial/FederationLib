<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;
    use FederationLib\Objects\OperatorRecord;
    use InvalidArgumentException;

    class OperatorsCommand implements CommandLineInterface
    {
        /**
         * The options of `operators <uuid> set` and their permission or configuration setting
         */
        private const array SETTINGS = [
            'client' => 'client_permissions',
            'management' => 'management_permissions',
            'operator' => 'operator_permissions',
            'auto-assign-reports' => 'auto_assign_reports',
            'auto-assign-automated-reports' => 'auto_assign_automated_reports',
            'bot' => 'is_bot',
        ];

        /**
         * @inheritDoc
         */
        public static function handle(array $tokens): int
        {
            // Every option of set takes a value (--bot false), the options of ls do too (--limit 10)
            $arguments = Arguments::parse($tokens, ['page', 'limit', ...array_keys(self::SETTINGS)]);
            $target = $arguments->get(0);

            try
            {
                if($target === null || ($arguments->wantsHelp() && $arguments->count() <= 1 && !Validate::uuid($target)))
                {
                    print(self::getHelp());
                    return $target === null && !$arguments->wantsHelp() ? 1 : 0;
                }

                if($target === 'ls' || $target === 'list')
                {
                    return self::list($arguments);
                }

                if($target === 'create')
                {
                    // The flags of create take no value: `operators create <name> --client --bot`
                    return self::create(Arguments::parse($tokens));
                }

                if(!Validate::uuid($target))
                {
                    print(sprintf("Error: Unknown command or invalid operator UUID '%s', see 'operators --help'\n", $target));
                    return 1;
                }

                return match($arguments->get(1) ?? 'inspect')
                {
                    'inspect' => self::inspect($target),
                    'set' => self::set($target, $arguments),
                    'enable' => self::setDisabled($target, false),
                    'disable' => self::setDisabled($target, true),
                    'token' => self::token($target),
                    'rm', 'delete' => self::delete($target),
                    default => self::unknownAction($arguments->get(1)),
                };
            }
            catch(InvalidArgumentException $e)
            {
                print(sprintf("Error: %s\n", $e->getMessage()));
                return 1;
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Operator command failed: ' . $e->getMessage(), $e);
                print("Error: The operation failed, see the logs for details.\n");
                return 1;
            }
        }

        /**
         * `operators ls [--page <page>] [--limit <limit>]`: lists the operators
         *
         * @param Arguments $arguments The arguments
         * @return int The exit code
         * @throws DatabaseOperationException If the operators cannot be retrieved
         */
        private static function list(Arguments $arguments): int
        {
            $page = $arguments->positiveInt('page', 1);
            $limit = $arguments->positiveInt('limit', Configuration::getServerConfiguration()->getListOperatorsMaxItems());
            $operators = OperatorManager::getOperators($limit, $page);

            if(empty($operators))
            {
                print("No operators found.\n");
            }
            else
            {
                printf("%-36s  %-32s  %-8s  %-11s  %s\n", 'UUID', 'NAME', 'STATUS', 'PERMISSIONS', 'CONFIGURATION');
                foreach($operators as $operator)
                {
                    printf("%-36s  %-32s  %-8s  %-11s  %s\n",
                        $operator->getUuid(),
                        $operator->getName(),
                        $operator->isDisabled() ? 'disabled' : 'enabled',
                        self::describePermissions($operator),
                        self::describeConfiguration($operator)
                    );
                }
            }

            printf("\nPage %d, %d per page, %d operators in total\n", $page, $limit, OperatorManager::getTotalOperatorsCount());
            return 0;
        }

        /**
         * `operators create <name> [--client] [--management] [--operator] [--bot] [--auto-assign-reports]
         * [--auto-assign-automated-reports]`: creates an operator and prints its access token
         *
         * @param Arguments $arguments The arguments, parsed without option values
         * @return int The exit code
         * @throws DatabaseOperationException If the operator cannot be created
         */
        private static function create(Arguments $arguments): int
        {
            $name = $arguments->get(1);
            if($name === null || trim($name) === '')
            {
                print("Error: The name of the operator is required, eg; 'operators create <name>'\n");
                return 1;
            }

            if(strtolower($name) === 'root' || strtolower($name) === 'system')
            {
                print(sprintf("Error: The operator name '%s' is reserved\n", $name));
                return 1;
            }

            $unknown = $arguments->getUnknownOptions(array_keys(self::SETTINGS));
            if(count($unknown) > 0)
            {
                print(sprintf("Error: Unknown option --%s\n", $unknown[0]));
                return 1;
            }

            $operatorCreated = OperatorManager::createOperator($name);
            $operatorUuid = $operatorCreated->getUuid();
            $settings = array_filter(array_map(fn(string $option) => $arguments->bool($option), array_combine(array_keys(self::SETTINGS), array_keys(self::SETTINGS))), fn($value) => $value !== null);
            self::apply($operatorUuid, $settings);

            AuditLogManager::createEntry(AuditLogType::OPERATOR_CREATED, sprintf(
                "Operator '%s' (%s) created from the command line%s",
                $name, $operatorUuid, count($settings) > 0 ? ', ' . self::describeChanges($settings) : ''
            ), OperatorManager::getRootOperator()->getUuid());

            printf("UUID:         %s\nAccess token: %s\n", $operatorUuid, $operatorCreated->getAccessToken());
            print("The access token is shown only once, store it securely.\n");
            return 0;
        }

        /**
         * `operators <uuid> [inspect]`: prints an operator
         *
         * @param string $uuid The operator UUID
         * @return int The exit code
         * @throws DatabaseOperationException If the operator cannot be retrieved
         */
        private static function inspect(string $uuid): int
        {
            $operator = OperatorManager::getOperator($uuid);
            if($operator === null)
            {
                return self::notFound($uuid);
            }

            // The command line acts as the root operator, which sees the configuration of every operator
            print(json_encode($operator->toStandardArray(OperatorManager::getRootOperator()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return 0;
        }

        /**
         * `operators <uuid> set [--client <bool>] [--management <bool>] [--operator <bool>] [--bot <bool>]
         * [--auto-assign-reports <bool>] [--auto-assign-automated-reports <bool>]`: changes the permissions and the
         * configuration of an operator
         *
         * @param string $uuid The operator UUID
         * @param Arguments $arguments The arguments
         * @return int The exit code
         * @throws DatabaseOperationException If the operator cannot be updated
         */
        private static function set(string $uuid, Arguments $arguments): int
        {
            $unknown = $arguments->getUnknownOptions(array_keys(self::SETTINGS));
            if(count($unknown) > 0)
            {
                print(sprintf("Error: Unknown option --%s\n", $unknown[0]));
                return 1;
            }

            $settings = array_filter(array_map(fn(string $option) => $arguments->bool($option), array_combine(array_keys(self::SETTINGS), array_keys(self::SETTINGS))), fn($value) => $value !== null);
            if(count($settings) === 0)
            {
                print("Error: Nothing to set, eg; 'operators <uuid> set --management true --bot false'\n");
                return 1;
            }

            $operator = self::getModifiableOperator($uuid);
            if(!$operator instanceof OperatorRecord)
            {
                return $operator;
            }

            // Management permissions inherit client permissions, an operator never keeps one without the other
            if(($settings['client'] ?? null) === false && ($settings['management'] ?? $operator->hasManagementPermissions()))
            {
                print("Error: Client permissions cannot be revoked while the operator keeps management permissions, which inherit them\n");
                return 1;
            }

            self::apply($uuid, $settings);
            AuditLogManager::createEntry(AuditLogType::OPERATOR_UPDATED, sprintf(
                "Operator %s updated from the command line: %s", $operator->getName(), self::describeChanges($settings)
            ), OperatorManager::getRootOperator()->getUuid());

            print(sprintf("Operator %s updated: %s\n", $operator->getName(), self::describeChanges($settings)));
            return 0;
        }

        /**
         * `operators <uuid> enable` and `operators <uuid> disable`
         *
         * @param string $uuid The operator UUID
         * @param bool $disabled True to disable the operator, false to enable it
         * @return int The exit code
         * @throws DatabaseOperationException If the operator cannot be updated
         */
        private static function setDisabled(string $uuid, bool $disabled): int
        {
            $operator = self::getModifiableOperator($uuid);
            if(!$operator instanceof OperatorRecord)
            {
                return $operator;
            }

            $disabled ? OperatorManager::disableOperator($uuid) : OperatorManager::enableOperator($uuid);
            AuditLogManager::createEntry($disabled ? AuditLogType::OPERATOR_DISABLED : AuditLogType::OPERATOR_ENABLED, sprintf(
                "Operator %s %s from the command line", $operator->getName(), $disabled ? 'disabled' : 'enabled'
            ), OperatorManager::getRootOperator()->getUuid());

            print(sprintf("Operator %s %s\n", $operator->getName(), $disabled ? 'disabled' : 'enabled'));
            return 0;
        }

        /**
         * `operators <uuid> token`: generates a new access token for an operator, invalidating the previous one
         *
         * @param string $uuid The operator UUID
         * @return int The exit code
         * @throws DatabaseOperationException If the access token cannot be generated
         */
        private static function token(string $uuid): int
        {
            $operator = self::getModifiableOperator($uuid);
            if(!$operator instanceof OperatorRecord)
            {
                return $operator;
            }

            $accessToken = OperatorManager::newAccessToken($uuid);
            printf("Access token: %s\n", $accessToken);
            print("The previous access token no longer works, the new one is shown only once.\n");
            return 0;
        }

        /**
         * `operators <uuid> rm`: deletes an operator
         *
         * @param string $uuid The operator UUID
         * @return int The exit code
         * @throws DatabaseOperationException If the operator cannot be deleted
         */
        private static function delete(string $uuid): int
        {
            $operator = self::getModifiableOperator($uuid);
            if(!$operator instanceof OperatorRecord)
            {
                return $operator;
            }

            OperatorManager::deleteOperator($uuid);
            AuditLogManager::createEntry(AuditLogType::OPERATOR_DELETED, sprintf(
                "Operator %s deleted from the command line", $operator->getName()
            ), OperatorManager::getRootOperator()->getUuid());

            print(sprintf("Operator %s deleted\n", $operator->getName()));
            return 0;
        }

        /**
         * Applies permission and configuration settings to an operator
         *
         * @param string $uuid The operator UUID
         * @param array<string, bool> $settings The settings keyed by their option name
         * @return void
         * @throws DatabaseOperationException If the operator cannot be updated
         */
        private static function apply(string $uuid, array $settings): void
        {
            OperatorManager::updatePermissions($uuid, $settings['client'] ?? null, $settings['management'] ?? null, $settings['operator'] ?? null);
            OperatorManager::updateConfiguration($uuid, $settings['auto-assign-reports'] ?? null, $settings['auto-assign-automated-reports'] ?? null, $settings['bot'] ?? null);
        }

        /**
         * Returns the operator to modify, the root and system operators are never modified
         *
         * @param string $uuid The operator UUID
         * @return OperatorRecord|int The operator, or the exit code when it cannot be modified
         * @throws DatabaseOperationException If the operator cannot be retrieved
         */
        private static function getModifiableOperator(string $uuid): OperatorRecord|int
        {
            $operator = OperatorManager::getOperator($uuid);
            if($operator === null)
            {
                return self::notFound($uuid);
            }

            if(OperatorManager::isRootOperator($uuid) || OperatorManager::isSystemOperator($uuid))
            {
                print("Error: The root and system operators cannot be modified\n");
                return 1;
            }

            return $operator;
        }

        /**
         * Prints that an operator does not exist
         *
         * @param string $uuid The operator UUID
         * @return int The exit code
         */
        private static function notFound(string $uuid): int
        {
            print(sprintf("Error: No operator with the UUID %s exists\n", $uuid));
            return 1;
        }

        /**
         * Prints that an action is unknown
         *
         * @param string|null $action The action
         * @return int The exit code
         */
        private static function unknownAction(?string $action): int
        {
            print(sprintf("Error: Unknown action '%s', see 'operators --help'\n", $action));
            return 1;
        }

        /**
         * Describes the permission groups of an operator, eg; client,management
         *
         * @param OperatorRecord $operator The operator
         * @return string The description
         */
        private static function describePermissions(OperatorRecord $operator): string
        {
            $permissions = array_keys(array_filter($operator->getPermissions()->toArray()));
            return count($permissions) > 0 ? implode(',', $permissions) : '-';
        }

        /**
         * Describes the configuration of an operator, eg; bot,auto-assign-reports
         *
         * @param OperatorRecord $operator The operator
         * @return string The description
         */
        private static function describeConfiguration(OperatorRecord $operator): string
        {
            $configuration = array_keys(array_filter([
                'bot' => $operator->isBot(),
                'auto-assign-reports' => $operator->isAutoAssignReports(),
                'auto-assign-automated-reports' => $operator->isAutoAssignAutomatedReports(),
            ]));
            return count($configuration) > 0 ? implode(',', $configuration) : '-';
        }

        /**
         * Describes applied settings, eg; client=true, bot=false
         *
         * @param array<string, bool> $settings The settings keyed by their option name
         * @return string The description
         */
        private static function describeChanges(array $settings): string
        {
            return implode(', ', array_map(fn(string $option, bool $value) => sprintf('%s=%s', $option, $value ? 'true' : 'false'), array_keys($settings), $settings));
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationlib operators ls [--page <page>] [--limit <limit>]\n" .
                "  federationlib operators create <name> [--client] [--management] [--operator] [--bot]\n" .
                "                                        [--auto-assign-reports] [--auto-assign-automated-reports]\n" .
                "  federationlib operators <uuid> [inspect]\n" .
                "  federationlib operators <uuid> set [--client <bool>] [--management <bool>] [--operator <bool>]\n" .
                "                                     [--bot <bool>] [--auto-assign-reports <bool>]\n" .
                "                                     [--auto-assign-automated-reports <bool>]\n" .
                "  federationlib operators <uuid> enable|disable\n" .
                "  federationlib operators <uuid> token\n" .
                "  federationlib operators <uuid> rm\n" .
                "\nCommands:\n" .
                "  ls              List the operators\n" .
                "  create <name>   Create an operator and print its access token\n" .
                "  <uuid> inspect  Show an operator (the default action)\n" .
                "  <uuid> set      Change the permissions and configuration of an operator\n" .
                "  <uuid> enable   Enable an operator\n" .
                "  <uuid> disable  Disable an operator\n" .
                "  <uuid> token    Generate a new access token, the previous one stops working\n" .
                "  <uuid> rm       Delete an operator\n" .
                "\nPermissions and configuration:\n" .
                "  --client                         Client permissions: submit entities, evidence and reports\n" .
                "  --management                     Management permissions, which include client permissions\n" .
                "  --operator                       Operator permissions: manage other operators\n" .
                "  --bot                            A bot, its evidence and reports are automated and confidential\n" .
                "  --auto-assign-reports            Receive ordinary reports automatically\n" .
                "  --auto-assign-automated-reports  Receive automated reports automatically\n" .
                "\n  Booleans are true or false (also yes, no, on, off, 1 and 0); with create they are flags.\n" .
                "  The root and system operators cannot be modified.\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Manages operators: ls, create, inspect, set, enable, disable, token, rm.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                "  federationlib operators ls\n" .
                "  federationlib operators create moderator --management --auto-assign-reports\n" .
                "  federationlib operators create telegram-bot --client --bot\n" .
                "  federationlib operators 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b\n" .
                "  federationlib operators 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b set --bot false --auto-assign-automated-reports true\n" .
                "  federationlib operators 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b token\n" .
                "  federationlib operators 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b rm\n";
        }
    }
