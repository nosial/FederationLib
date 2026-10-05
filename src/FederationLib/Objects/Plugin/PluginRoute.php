<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Enums\ExecutionPriority;

    class PluginRoute
    {
        /** @var MatchedRequestHandler[] */
        private array $preRequestHandlers = [];
        /** @var MatchedRequestHandler[] */
        private array $postRequestHandlers = [];
        private ?MatchedRequestHandler $overrideHandler = null;
        private ?MatchedRequestHandler $requestHandler = null;

        /**
         * Adds a matched request handler to the route based on its execution priority, the first OVERRIDE handler
         * and the first request handler without an execution priority take precedence.
         *
         * @param MatchedRequestHandler $handler The matched request handler
         * @return bool False if the handler was ignored because another handler already took precedence
         */
        public function add(MatchedRequestHandler $handler): bool
        {
            switch($handler->getDefinition()->getExecutionPriority())
            {
                case ExecutionPriority::PRE_REQUEST:
                    $this->preRequestHandlers[] = $handler;
                    return true;

                case ExecutionPriority::POST_REQUEST:
                    $this->postRequestHandlers[] = $handler;
                    return true;

                case ExecutionPriority::OVERRIDE:
                    if($this->overrideHandler !== null)
                    {
                        return false;
                    }

                    $this->overrideHandler = $handler;
                    return true;

                default:
                    if($this->requestHandler !== null)
                    {
                        return false;
                    }

                    $this->requestHandler = $handler;
                    return true;
            }
        }

        /**
         * Returns the PRE_REQUEST handlers in execution order
         *
         * @return MatchedRequestHandler[]
         */
        public function getPreRequestHandlers(): array
        {
            return $this->preRequestHandlers;
        }

        /**
         * Returns the POST_REQUEST handlers in execution order
         *
         * @return MatchedRequestHandler[]
         */
        public function getPostRequestHandlers(): array
        {
            return $this->postRequestHandlers;
        }

        /**
         * Returns the OVERRIDE handler which replaces the original request handler
         *
         * @return MatchedRequestHandler|null The OVERRIDE handler, null if none matched
         */
        public function getOverrideHandler(): ?MatchedRequestHandler
        {
            return $this->overrideHandler;
        }

        /**
         * Returns the request handler without an execution priority, which provides a new route
         *
         * @return MatchedRequestHandler|null The request handler, null if none matched
         */
        public function getRequestHandler(): ?MatchedRequestHandler
        {
            return $this->requestHandler;
        }

        /**
         * Returns True if a plugin can handle the request on its own (an OVERRIDE handler or a new route)
         *
         * @return bool True if a plugin provides the request handler
         */
        public function hasHandler(): bool
        {
            return $this->overrideHandler !== null || $this->requestHandler !== null;
        }
    }
