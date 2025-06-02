<?php

    namespace FederationServer\Classes;

    class Logger
    {
        private static ?\LogLib2\Logger $logger = null;

        /**
         * Get the logger instance. If it does not exist, create it using the configuration.
         *
         * @return \LogLib2\Logger
         */
        public static function log(): \LogLib2\Logger
        {
            if (self::$logger === null)
            {
                self::$logger = new \LogLib2\Logger('federation_server');

                // Don't register handlers if we are testing. This conflicts with PHPUnit.
                if(!defined('FS_TEST'))
                {
                    \LogLib2\Logger::registerHandlers();
                }
            }

            return self::$logger;
        }
    }