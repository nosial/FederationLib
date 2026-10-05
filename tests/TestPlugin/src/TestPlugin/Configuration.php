<?php

    namespace TestPlugin;

    /**
     * The TestPlugin's own configuration, plugins are responsible for their own configuration in the same way
     * FederationLib is (see FederationLib\Classes\Configuration). ConfigLib is provided by FederationLib at runtime.
     */
    class Configuration
    {
        private static ?\ConfigLib\Configuration $configuration = null;

        /**
         * Initializes the configuration with its default values
         */
        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('test_plugin');

            self::$configuration->setDefault('greeting', 'Hello from the TestPlugin', 'TEST_PLUGIN_GREETING');
            self::$configuration->setDefault('nested.value', 42, 'TEST_PLUGIN_NESTED_VALUE');

            // Only save if the configuration file does not exist or we're in CLI mode
            if(!file_exists(self::$configuration->getPath()) || php_sapi_name() === 'cli')
            {
                self::$configuration->save();
            }
        }

        /**
         * Returns a configuration value using dot notation
         *
         * @param string $key The configuration key, eg; `nested.value`
         * @param mixed $default The value to return if the key does not exist
         * @return mixed The configuration value or the default value
         */
        public static function get(string $key, mixed $default=null): mixed
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration->get($key, $default);
        }

        /**
         * Returns the entire configuration
         *
         * @return array The configuration
         */
        public static function getConfiguration(): array
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration->getConfiguration();
        }
    }
