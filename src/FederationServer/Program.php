<?php

    namespace FederationServer;

    class Program
    {
        /**
         * FederationServer main entry point
         *
         * @param string[] $args Command-line arguments
         * @return int Exit code
         */
        public static function main(array $args): int
        {
            print("Hello World from net.nosial.federation!" . PHP_EOL);
            return 0;
        }
    }