<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\EventType;
    use FederationLib\Exceptions\PluginException;
    use FederationLib\Objects\Plugin\EventHandlerDefinition;
    use FederationLib\Objects\Plugin\RequestHandlerDefinition;
    use ncc\Runtime;
    use Throwable;

    class Plugin
    {
        /**
         * The name of the package option that contains the plugin's FederationLib properties
         */
        public const string PACKAGE_OPTION = 'federationlib';

        private string $package;
        private ?string $version;
        /** @var RequestHandlerDefinition[] */
        private array $requestHandlers;
        /** @var EventHandlerDefinition[] */
        private array $eventHandlers;

        /**
         * Plugin constructor.
         *
         * @param string $package The package name of the plugin, eg; net.nosial.test_plugin
         * @param array $properties The plugin's FederationLib properties (the `federationlib` package option)
         * @param string|null $version The version of the plugin's package
         * @throws PluginException If the plugin's properties are invalid
         */
        public function __construct(string $package, array $properties, ?string $version=null)
        {
            $this->package = $package;
            $this->version = $version;

            $requestHandlers = $properties['request_handlers'] ?? [];
            if(!is_array($requestHandlers))
            {
                throw new PluginException(sprintf('The plugin "%s" has an invalid "request_handlers" property, expected a list of request handlers', $package));
            }

            // Allow a single request handler to be defined without wrapping it in a list
            if(isset($requestHandlers['path']))
            {
                $requestHandlers = [$requestHandlers];
            }

            $this->requestHandlers = [];
            foreach($requestHandlers as $index => $requestHandler)
            {
                if(!is_array($requestHandler))
                {
                    throw new PluginException(sprintf('The plugin "%s" has an invalid request handler at index %s', $package, $index));
                }

                try
                {
                    $this->requestHandlers[] = RequestHandlerDefinition::fromArray($requestHandler);
                }
                catch(PluginException $e)
                {
                    throw new PluginException(sprintf('The plugin "%s" has an invalid request handler at index %s: %s', $package, $index, $e->getMessage()), 0, $e);
                }
            }

            $eventHandlers = $properties['event_handlers'] ?? [];
            if(!is_array($eventHandlers))
            {
                throw new PluginException(sprintf('The plugin "%s" has an invalid "event_handlers" property, expected a list of event handlers', $package));
            }

            // Allow a single event handler to be defined without wrapping it in a list
            if(isset($eventHandlers['event']))
            {
                $eventHandlers = [$eventHandlers];
            }

            $this->eventHandlers = [];
            foreach($eventHandlers as $index => $eventHandler)
            {
                if(!is_array($eventHandler))
                {
                    throw new PluginException(sprintf('The plugin "%s" has an invalid event handler at index %s', $package, $index));
                }

                try
                {
                    $this->eventHandlers[] = EventHandlerDefinition::fromArray($eventHandler);
                }
                catch(PluginException $e)
                {
                    throw new PluginException(sprintf('The plugin "%s" has an invalid event handler at index %s: %s', $package, $index, $e->getMessage()), 0, $e);
                }
            }
        }

        /**
         * Imports the plugin's ncc package and constructs the plugin from the package's `federationlib` option
         *
         * @param string $package The package name of the plugin, eg; net.nosial.test_plugin
         * @return Plugin The loaded plugin
         * @throws PluginException If the package could not be imported or the plugin is not correctly configured
         */
        public static function load(string $package): Plugin
        {
            if(!preg_match('/^[a-z0-9_\-]+(\.[a-z0-9_\-]+)+$/i', $package))
            {
                throw new PluginException(sprintf('Invalid plugin name "%s", plugins must be referenced by their ncc package name, eg; com.example.plugin', $package));
            }

            try
            {
                if(!Runtime::isImported($package))
                {
                    Runtime::import($package);
                }
            }
            catch(Throwable $e)
            {
                throw new PluginException(sprintf('Failed to import the plugin "%s", is the package installed? %s', $package, $e->getMessage()), 0, $e);
            }

            if(!Runtime::isImported($package))
            {
                throw new PluginException(sprintf('Failed to import the plugin "%s", the package was not registered after importing', $package));
            }

            $options = Runtime::getImportedPackageOptions($package);
            if(!isset($options[self::PACKAGE_OPTION]) || !is_array($options[self::PACKAGE_OPTION]))
            {
                throw new PluginException(sprintf('The package "%s" is not a FederationLib plugin, it must be built with a "%s" option', $package, self::PACKAGE_OPTION));
            }

            $plugin = new Plugin($package, $options[self::PACKAGE_OPTION], Runtime::getImportedPackage($package)?->getAssembly()->getVersion());
            foreach($plugin->getRequestHandlers() as $requestHandler)
            {
                try
                {
                    $requestHandler->validateClass();
                }
                catch(PluginException $e)
                {
                    throw new PluginException(sprintf('The plugin "%s" has an invalid request handler: %s', $package, $e->getMessage()), 0, $e);
                }
            }

            foreach($plugin->getEventHandlers() as $eventHandler)
            {
                try
                {
                    $eventHandler->validateClass();
                }
                catch(PluginException $e)
                {
                    throw new PluginException(sprintf('The plugin "%s" has an invalid event handler: %s', $package, $e->getMessage()), 0, $e);
                }
            }

            return $plugin;
        }

        /**
         * Returns the package name of the plugin
         *
         * @return string The package name, eg; net.nosial.test_plugin
         */
        public function getPackage(): string
        {
            return $this->package;
        }

        /**
         * Returns the version of the plugin's package
         *
         * @return string|null The version, null if unknown
         */
        public function getVersion(): ?string
        {
            return $this->version;
        }

        /**
         * Returns the request handlers of the plugin
         *
         * @return RequestHandlerDefinition[] The request handler definitions
         */
        public function getRequestHandlers(): array
        {
            return $this->requestHandlers;
        }

        /**
         * Returns the event handlers of the plugin
         *
         * @param EventType|null $event Optional. Only return the event handlers for the given event
         * @return EventHandlerDefinition[] The event handler definitions
         */
        public function getEventHandlers(?EventType $event=null): array
        {
            if($event === null)
            {
                return $this->eventHandlers;
            }

            return array_values(array_filter($this->eventHandlers, fn(EventHandlerDefinition $definition) => $definition->getEvent() === $event));
        }
    }
