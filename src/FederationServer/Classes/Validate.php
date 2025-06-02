<?php

    namespace FederationServer\Classes;

    class Validate
    {
        public static function uuid(string $uuid): bool
        {
            // Validate UUID format using regex
            return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid) === 1;
        }
    }