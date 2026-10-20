<?php

    namespace FederationLib;

    use FederationLib\Enums\CliCommands;

    class Program
    {
        /**
         * The main entry point for the FederationLib command-line interface program. Commands are written in the style
         * of docker: `federationlib <command> [<arguments>...]`, eg; `federationlib operators ls` or
         * `federationlib operators <uuid> set --bot true`.
         *
         * @param array $args Raw arguments passed on from the command line, optionally starting with the program path
         * @return int Exit code indicating the success or failure of the operation.
         */
        public static function main(array $args): int
        {
            $tokens = array_values(array_map('strval', $args));
            if(count($tokens) > 0 && self::isProgramPath($tokens[0]))
            {
                array_shift($tokens);
            }

            $name = $tokens[0] ?? null;
            if($name === null || in_array($name, ['help', '--help', '-h'], true))
            {
                // `help <command>` shows the help of a command
                $command = isset($tokens[1]) ? CliCommands::tryFrom($tokens[1]) : null;
                if(isset($tokens[1]) && $command === null)
                {
                    print(sprintf("Unknown command: %s\n\n", $tokens[1]));
                    self::displayHelp();
                    return 1;
                }

                return $command !== null ? self::displayCommandHelp($command) : self::displayHelp();
            }

            $command = CliCommands::tryFrom($name);
            if($command === null)
            {
                print(sprintf("Unknown command: %s\n\n", $name));
                self::displayHelp();
                return 1;
            }

            $commandTokens = array_slice($tokens, 1);
            if(in_array($command, [CliCommands::INITIALIZE, CliCommands::MAINTENANCE], true) && array_intersect($commandTokens, ['--help', '-h']) !== [])
            {
                return self::displayCommandHelp($command);
            }

            return $command->handle($commandTokens);
        }

        /**
         * Returns whether an argument is the path of the program rather than a command
         *
         * @param string $argument The first argument
         * @return bool True if the argument is the program path
         */
        private static function isProgramPath(string $argument): bool
        {
            return CliCommands::tryFrom($argument) === null && !in_array($argument, ['help', '--help', '-h'], true)
                && (str_contains($argument, DIRECTORY_SEPARATOR) || str_ends_with($argument, '.ncc') || $argument === 'federationlib' || $argument === 'main');
        }

        /**
         * Prints the help of a command
         *
         * @param CliCommands $command The command
         * @return int The exit code, always 0
         */
        private static function displayCommandHelp(CliCommands $command): int
        {
            print(sprintf("%s - %s\n\n%s\n%s", $command->value, $command->getShortHelp(), $command->getHelp(), $command->getExamples() ?? ''));
            return 0;
        }

        /**
         * Returns the full help menu for the command-line interface
         *
         * @return int The exit code, always 0.
         */
        private static function displayHelp(): int
        {
            print("FederationLib Command-Line Interface\n\n");
            print("Usage: federationlib <command> [<arguments>...]\n\n");
            print("Commands:\n");
            foreach(CliCommands::cases() as $command)
            {
                print(sprintf("  %-12s %s\n", $command->value, $command->getShortHelp()));
            }

            print("\nRun 'federationlib help <command>' or 'federationlib <command> --help' for the usage of a command.\n");
            return 0;
        }
    }
