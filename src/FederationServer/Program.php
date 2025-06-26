<?php

    namespace FederationServer;

    use FederationServer\Classes\CLI\CreateOperator;
    use FederationServer\Classes\Enums\CliCommands;
    use OptsLib\Parse;

    class Program
    {
        /**
         * The main entry point for the FederationServer command-line interface program
         *
         * @param array $args Raw arguments passed on from the command line.
         * @return int Exit code indicating the success or failure of the operation.
         */
        public static function main(array $args): int
        {
            $args = Parse::parseArgument($args);
            if(isset($args['help']))
            {
                if($args['help'] === true)
                {
                    return self::displayHelp();
                }

                $command = CliCommands::tryFrom($args['help']);
                if($command === null)
                {
                    print(sprintf("Unknown command: %s\n", $command));
                    return 1;
                }

                print(sprintf("%s - %s\n\n%s\n\n%s", $command->value, $command->getShortHelp(), $command->getHelp(), $command->getExamples()));
                return 0;
            }

            if(isset($args[CliCommands::CREATE_OPERATOR->value]))
            {
                return CreateOperator::handle($args);
            }
            elseif(isset($args[CliCommands::DELETE_OPERATOR->value]))
            {
                return CliCommands::DELETE_OPERATOR->handle($args);
            }
            elseif(isset($args[CliCommands::GET_OPERATOR->value]))
            {
                return CliCommands::GET_OPERATOR->handle($args);
            }
            elseif(isset($args[CliCommands::EDIT_OPERATOR->value]))
            {
                return CliCommands::EDIT_OPERATOR->handle($args);
            }
            if(isset($args[CliCommands::INITIALIZE->value]))
            {
                return CliCommands::INITIALIZE->handle($args);
            }
            elseif(isset($args[CliCommands::LIST_AUDIT_LOGS->value]))
            {
                return CliCommands::LIST_AUDIT_LOGS->handle($args);
            }
            elseif(isset($args[CliCommands::LIST_OPERATORS->value]))
            {
                return CliCommands::LIST_OPERATORS->handle($args);
            }
            elseif(isset($args[CliCommands::REFRESH_OPERATOR_API_KEY->value]))
            {
                return CliCommands::REFRESH_OPERATOR_API_KEY->handle($args);
            }

            return self::displayHelp();
        }

        /**
         * Returns the full help menu for the command-line interface
         *
         * @return int The exit code, always 0.
         */
        private static function displayHelp(): int
        {
            print("FederationServer Command-Line Interface\n");
            print("Usage: federationserver [command] [options]\n\n");
            print("Available commands:\n");
            print("  help - Displays help information about a command\n");
            foreach(CliCommands::cases() as $command)
            {
                print(sprintf("  %s - %s\n", $command->value, $command->getShortHelp()));
            }

            print("\nExample Usage:\n");
            print("help [command] - Displays help information about the given command\n\n");
            foreach(CliCommands::cases() as $command)
            {
                if($command->getExamples() !== null)
                {
                    print(sprintf("%s\n%s\n\n", $command->value, $command->getExamples()));
                }
            }

            return 0;
        }
    }