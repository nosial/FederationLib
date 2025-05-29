<?php

    namespace FederationServer\Classes;

    class Utilities
    {
        /*
         * Generate a random string of specified length.
         *
         * @param int $length Length of the random string to generate.
         * @return string Randomly generated string.
         */
        public static function generateString(int $length=32): string
        {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';

            for ($i = 0; $i < $length; $i++)
            {
                $randomString .= $characters[rand(0, $charactersLength - 1)];

            }
            return $randomString;
        }
    }