<?php

    namespace FederationLib\Classes\Configuration;

    class PluginsConfiguration
    {
        /** @var string[] */
        private array $plugins;

        /**
         * PluginsConfiguration constructor.
         *
         * The configuration is a list of the ncc package names of the plugins to load, plugins are responsible for
         * their own configuration. The list may also be a comma-separated string (eg; from FEDERATION_PLUGINS), eg;
         *
         *  plugins:
         *      - net.nosial.test_plugin
         *
         * @param array|string|null $configuration The package names of the plugins to load
         */
        public function __construct(array|string|null $configuration)
        {
            if(is_string($configuration))
            {
                $configuration = explode(',', $configuration);
            }

            $this->plugins = [];
            foreach($configuration ?? [] as $package)
            {
                if(!is_string($package))
                {
                    continue;
                }

                $package = trim($package);
                if($package !== '' && !in_array($package, $this->plugins, true))
                {
                    $this->plugins[] = $package;
                }
            }
        }

        /**
         * Returns the package names of the plugins to load
         *
         * @return string[] The plugin package names
         */
        public function getPlugins(): array
        {
            return $this->plugins;
        }

        /**
         * Returns True if any plugins are configured
         *
         * @return bool True if at least one plugin is configured
         */
        public function hasPlugins(): bool
        {
            return count($this->plugins) > 0;
        }
    }
