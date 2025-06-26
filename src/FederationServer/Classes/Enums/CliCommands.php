<?php

    namespace FederationServer\Classes\Enums;

    use FederationServer\Classes\CLI\CreateOperator;
    use FederationServer\Classes\CLI\DeleteOperator;
    use FederationServer\Classes\CLI\EditOperator;
    use FederationServer\Classes\CLI\GetOperator;
    use FederationServer\Classes\CLI\InitializeCommand;
    use FederationServer\Classes\CLI\ListAuditLogs;
    use FederationServer\Classes\CLI\ListOperators;
    use FederationServer\Classes\CLI\MaintenanceCommand;
    use FederationServer\Classes\CLI\RefreshOperatorApiKey;

    enum CliCommands : string
    {
        case CREATE_OPERATOR = 'create-operator';
        case DELETE_OPERATOR = 'delete-operator';
        case GET_OPERATOR = 'get-operator';
        case INITIALIZE = 'init';
        case LIST_AUDIT_LOGS = 'list-audit';
        case EDIT_OPERATOR = 'edit-operator';
        case LIST_OPERATORS = 'list-operators';
        case MAINTENANCE = 'maintenance';
        case REFRESH_OPERATOR_API_KEY = 'refresh-operator-api-key';

        /**
         * Returns the class interface of the cli command
         *
         * @return string The class interface
         * @noinspection PhpMissingReturnTypeInspection Not necessary here, causes compiler warnings
         */
        public function getInterface()
        {
            return match ($this)
            {
                self::CREATE_OPERATOR => CreateOperator::class,
                self::DELETE_OPERATOR => DeleteOperator::class,
                self::EDIT_OPERATOR => EditOperator::class,
                self::GET_OPERATOR => GetOperator::class,
                self::INITIALIZE => InitializeCommand::class,
                self::LIST_AUDIT_LOGS => ListAuditLogs::class,
                self::LIST_OPERATORS => ListOperators::class,
                self::MAINTENANCE => MaintenanceCommand::class,
                self::REFRESH_OPERATOR_API_KEY => RefreshOperatorApiKey::class
            };
        }

        /**
         * Handles the execution of the command-line command
         *
         * @param array $args The parsed arguments (OptsLib) to the command handler
         * @return int The exit code
         */
        public function handle(array $args): int
        {
            return $this->getInterface()::handle($args);
        }

        /**
         * Returns a string message of the help message for this command
         *
         * @return string The help message
         */
        public function getHelp(): string
        {
            return $this->getInterface()::getHelp();
        }

        /**
         * Returns a single-line short help message for this command
         *
         * @return string Single-line short help message
         */
        public function getShortHelp(): string
        {
            return $this->getInterface()::getShortHelp();
        }

        /**
         * Returns one or more examples of how the command is used, null if none is provided
         *
         * @return string|null One or more examples, null if none is provided.
         */
        public function getExamples(): ?string
        {
            return $this->getInterface()::getExamples();
        }
    }
