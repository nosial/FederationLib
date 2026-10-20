<?php

    namespace FederationLib\Enums;

    use FederationLib\Classes\CLI\AuditCommand;
    use FederationLib\Classes\CLI\BayesianCommand;
    use FederationLib\Classes\CLI\InitializeCommand;
    use FederationLib\Classes\CLI\MaintenanceCommand;
    use FederationLib\Classes\CLI\OperatorsCommand;
    use FederationLib\Classes\CLI\ReputationCommand;

    enum CliCommands : string
    {
        case INITIALIZE = 'init';
        case OPERATORS = 'operators';
        case AUDIT = 'audit';
        case REPUTATION = 'reputation';
        case MAINTENANCE = 'maintenance';
        case BAYESIAN = 'bayesian';

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
                self::INITIALIZE => InitializeCommand::class,
                self::OPERATORS => OperatorsCommand::class,
                self::AUDIT => AuditCommand::class,
                self::REPUTATION => ReputationCommand::class,
                self::MAINTENANCE => MaintenanceCommand::class,
                self::BAYESIAN => BayesianCommand::class,
            };
        }

        /**
         * Handles the execution of the command-line command
         *
         * @param string[] $tokens The arguments that follow the command name
         * @return int The exit code
         */
        public function handle(array $tokens): int
        {
            return $this->getInterface()::handle($tokens);
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
