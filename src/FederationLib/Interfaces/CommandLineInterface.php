<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Classes\CLI\Arguments;

    interface CommandLineInterface
    {
        /**
         * Handles the execution of the command-line command
         *
         * @param string[] $tokens The arguments that follow the command name, as passed on the command line; the
         *                        command parses them with Arguments::parse(), declaring the options that take a value
         * @return int The exit code
         */
        public static function handle(array $tokens): int;

        /**
         * Returns a string message of the help message for this command
         *
         * @return string The help message
         */
        public static function getHelp(): string;

        /**
         * Returns a single-line short help message for this command
         *
         * @return string Single-line short help message
         */
        public static function getShortHelp(): string;

        /**
         * Returns one or more examples of how the command is used, null if none is provided
         *
         * @return string|null One or more examples, null if none is provided.
         */
        public static function getExamples(): ?string;
    }