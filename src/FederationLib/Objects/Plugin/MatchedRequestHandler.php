<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Objects\Plugin;

    class MatchedRequestHandler
    {
        private Plugin $plugin;
        private RequestHandlerDefinition $definition;
        private array $pathParameters;

        /**
         * MatchedRequestHandler constructor.
         *
         * @param Plugin $plugin The plugin that defines the request handler
         * @param RequestHandlerDefinition $definition The request handler that matched the request
         * @param array<string, string> $pathParameters The named path parameters captured from the request path
         */
        public function __construct(Plugin $plugin, RequestHandlerDefinition $definition, array $pathParameters)
        {
            $this->plugin = $plugin;
            $this->definition = $definition;
            $this->pathParameters = $pathParameters;
        }

        /**
         * Returns the plugin that defines the request handler
         *
         * @return Plugin The plugin
         */
        public function getPlugin(): Plugin
        {
            return $this->plugin;
        }

        /**
         * Returns the request handler definition that matched the request
         *
         * @return RequestHandlerDefinition The request handler definition
         */
        public function getDefinition(): RequestHandlerDefinition
        {
            return $this->definition;
        }

        /**
         * Returns the named path parameters captured from the request path
         *
         * @return array<string, string> The path parameters, eg; ['uuid' => '...'] for the path `/foo/{uuid}`
         */
        public function getPathParameters(): array
        {
            return $this->pathParameters;
        }
    }
