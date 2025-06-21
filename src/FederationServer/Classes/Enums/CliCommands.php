<?php

    namespace FederationServer\Classes\Enums;

    use FederationServer\Classes\CLI\CreateOperator;
    use FederationServer\Classes\CLI\InitializeCommand;

    enum CliCommands : string
    {
        case INITIALIZE = 'init';
        case CREATE_OPERATOR = 'create-operator';

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
                self::CREATE_OPERATOR => CreateOperator::class
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
