<?php

    namespace FederationLib\Interfaces;

    interface CommandLineInterface
    {
        /**
         * Handles the execution of the command-line command
         *
         * @param array $args The parsed arguments (OptsLib) to the command handler
         * @return int The exit code
         */
        public static function handle(array $args): int;

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