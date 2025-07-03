<?php

    namespace FederationServer\Interfaces;

    interface ResponseInterface extends SerializableInterface
    {
        /**
         * Check if the response is successful.
         *
         * @return bool True if the response is successful, false otherwise.
         */
        public function isSuccess(): bool;
    }