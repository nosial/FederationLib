<?php

    namespace FederationLib\Classes;

    class Validate
    {
        public static function uuid(string $uuid): bool
        {
            // Validate UUID format using regex - accepts all valid UUID versions
            return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid) === 1;
        }
    }