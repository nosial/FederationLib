<?php

    namespace FederationLib\Classes;

    use FederationLib\Enums\ExecutionPriority;
    use FederationLib\Objects\Plugin;
    use LogicException;

    /**
     * Base class for plugin request handlers, plugin request handlers have access to everything FederationLib's own
     * request handlers have access to (the response helpers, authentication, the request parameters through
     * FederationServer, etc.) in addition to the plugin and the path parameters of the request. Plugins are responsible
     * for their own configuration, eg; with their own ConfigLib instance.
     *
     * PRE_REQUEST handlers prevent the original request handler from executing by sending a response
     * (successResponse/errorResponse) or by throwing a RequestException, otherwise the request continues.
     */
    abstract class PluginRequestHandler extends RequestHandler
    {
        /**
         * Returns the plugin the executing request handler belongs to
         *
         * @return Plugin The plugin
         * @throws LogicException If the request handler is not being executed by the PluginManager
         */
        protected static function getPlugin(): Plugin
        {
            $handler = PluginManager::getCurrentHandler();
            if($handler === null)
            {
                throw new LogicException(sprintf('%s is not being executed as a plugin request handler', static::class));
            }

            return $handler->getPlugin();
        }

        /**
         * Returns the named path parameters captured from the request path, eg; ['uuid' => '...'] for `/foo/{uuid}`
         *
         * @return array<string, string> The path parameters
         */
        protected static function getPathParameters(): array
        {
            return PluginManager::getCurrentHandler()?->getPathParameters() ?? [];
        }

        /**
         * Returns a named path parameter captured from the request path
         *
         * @param string $name The name of the placeholder, eg; `uuid` for `/foo/{uuid}`
         * @return string|null The value, null if the parameter does not exist
         */
        protected static function getPathParameter(string $name): ?string
        {
            return self::getPathParameters()[$name] ?? null;
        }

        /**
         * Returns the execution priority the request handler is being executed with
         *
         * @return ExecutionPriority|null The execution priority, null if the handler provides a new route
         */
        protected static function getExecutionPriority(): ?ExecutionPriority
        {
            return PluginManager::getCurrentHandler()?->getDefinition()->getExecutionPriority();
        }
    }
