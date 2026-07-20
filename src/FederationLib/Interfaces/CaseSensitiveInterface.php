<?php

    namespace FederationLib\Interfaces;

    interface CaseSensitiveInterface
    {
        /**
         * Attempts to resolve the enum case from a case-insensitive input
         *
         * @param string $value The input, can be case-insensitive
         * @return static|null The matched enum case, null otherwise.
         */
        public static function tryFromCaseInsensitive(string $value): ?CaseSensitiveInterface;
    }