<?php

    namespace FederationServer\Interfaces;

    interface SerializableInterface
    {
        /**
         * Convert the object to an associative array representation.
         *
         * @return array An associative array representing the object's state.
         */
        public function toArray(): array;

        /**
         * Create an instance of the object from an associative array.
         *
         * @param array $array An associative array containing the object's state.
         * @return SerializableInterface An instance of the object.
         */
        public static function fromArray(array $array): SerializableInterface;
    }