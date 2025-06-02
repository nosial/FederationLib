<?php

    namespace FederationServer\Interfaces;

    interface RequestHandlerInterface
    {
        /**
         * Handle the incoming request.
         * This method should be implemented to process the request and return a response.
         *
         * @return void
         */
        public static function handleRequest(): void;
    }