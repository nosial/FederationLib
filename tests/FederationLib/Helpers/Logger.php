<?php

    namespace FederationLib\Helpers;

    final class Logger
    {
        private static ?\LogLib2\Logger $logger=null;

        /**
         * Returns a singleton instance of the logger for tests.
         *
         * @return \LogLib2\Logger
         */
        public static function getLogger():\LogLib2\Logger
        {
            if(self::$logger===null)
            {
                self::$logger = new \LogLib2\Logger('tests');
                \LogLib2\Logger::unregisterHandlers();
            }

            return self::$logger;
        }
    }