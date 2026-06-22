<?php

    namespace FederationLib\Interfaces;

    interface StandardObjectInterface extends SerializableInterface
    {
        /**
         * Returns a standard representation of the object's array
         *
         * @return array The standard array representation of the object
         */
        public function toStandardArray(): array;
    }