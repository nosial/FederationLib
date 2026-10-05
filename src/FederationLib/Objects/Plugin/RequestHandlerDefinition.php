<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Enums\ExecutionPriority;
    use FederationLib\Exceptions\PluginException;
    use FederationLib\Interfaces\RequestHandlerInterface;

    class RequestHandlerDefinition
    {
        public const array SUPPORTED_REQUEST_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'PUSH'];

        private string $path;
        private string $class;
        /** @var string[] */
        private array $requestMethods;
        private ?ExecutionPriority $executionPriority;
        private string $pattern;

        /**
         * RequestHandlerDefinition constructor.
         *
         * Paths may contain named placeholders such as `/foo/{uuid}` which match a single path segment, and may end
         * with a `*` segment (eg; `/foo/*`) which matches the remainder of the path.
         *
         * @param string $path The request path the handler is executed for, eg; `/foo/bar` or `/foo/{uuid}`
         * @param string $class The fully qualified class name of the request handler
         * @param string[] $requestMethods The request methods the handler is executed for, eg; ['GET', 'POST']
         * @param ExecutionPriority|null $executionPriority The execution priority, null if the handler provides a new route
         * @throws PluginException If any of the given values are invalid
         */
        public function __construct(string $path, string $class, array $requestMethods, ?ExecutionPriority $executionPriority=null)
        {
            $path = trim($path);
            if($path === '' || !str_starts_with($path, '/'))
            {
                throw new PluginException(sprintf('Invalid request handler path "%s", the path must start with "/"', $path));
            }

            // Normalize the class name, allowing the forms `\Foo\Bar`, `Foo\Bar` and `\Foo\Bar::class`
            $class = trim($class);
            if(str_ends_with($class, '::class'))
            {
                $class = substr($class, 0, -strlen('::class'));
            }

            $class = ltrim($class, '\\');
            if($class === '')
            {
                throw new PluginException(sprintf('The request handler for the path "%s" has no class', $path));
            }

            if(count($requestMethods) === 0)
            {
                throw new PluginException(sprintf('The request handler for the path "%s" has no request methods', $path));
            }

            $normalizedMethods = [];
            foreach($requestMethods as $requestMethod)
            {
                if(!is_string($requestMethod))
                {
                    throw new PluginException(sprintf('The request handler for the path "%s" has an invalid request method', $path));
                }

                $requestMethod = strtoupper(trim($requestMethod));
                if(!in_array($requestMethod, self::SUPPORTED_REQUEST_METHODS, true))
                {
                    throw new PluginException(sprintf('The request handler for the path "%s" has an unsupported request method "%s", supported methods are: %s', $path, $requestMethod, implode(', ', self::SUPPORTED_REQUEST_METHODS)));
                }

                $normalizedMethods[$requestMethod] = $requestMethod;
            }

            $this->path = $path;
            $this->class = $class;
            $this->requestMethods = array_values($normalizedMethods);
            $this->executionPriority = $executionPriority;
            $this->pattern = self::compilePattern($path);
        }

        /**
         * Constructs a RequestHandlerDefinition from a plugin's package options, eg;
         *
         *  path: /foo/bar
         *  class: \TestPlugin\RequestHandlers\FooHandler::class
         *  request_method: GET, POST
         *  execution_priority: PRE_REQUEST
         *
         * @param array $data The request handler definition
         * @return RequestHandlerDefinition The parsed definition
         * @throws PluginException If the definition is invalid
         */
        public static function fromArray(array $data): RequestHandlerDefinition
        {
            if(!isset($data['path']) || !is_string($data['path']))
            {
                throw new PluginException('A request handler is missing the required "path" property');
            }

            if(!isset($data['class']) || !is_string($data['class']))
            {
                throw new PluginException(sprintf('The request handler for the path "%s" is missing the required "class" property', $data['path']));
            }

            $requestMethods = $data['request_method'] ?? null;
            if(is_string($requestMethods))
            {
                $requestMethods = array_filter(array_map('trim', explode(',', $requestMethods)), fn(string $value) => $value !== '');
            }

            if(!is_array($requestMethods))
            {
                throw new PluginException(sprintf('The request handler for the path "%s" is missing the required "request_method" property', $data['path']));
            }

            $executionPriority = null;
            if(isset($data['execution_priority']))
            {
                if(!is_string($data['execution_priority']))
                {
                    throw new PluginException(sprintf('The request handler for the path "%s" has an invalid execution_priority', $data['path']));
                }

                $executionPriority = ExecutionPriority::tryFromCaseInsensitive($data['execution_priority']);
                if($executionPriority === null)
                {
                    throw new PluginException(sprintf('The request handler for the path "%s" has an unknown execution_priority "%s", expected one of: %s', $data['path'], $data['execution_priority'], implode(', ', array_map(fn(ExecutionPriority $priority) => $priority->value, ExecutionPriority::cases()))));
                }
            }

            return new RequestHandlerDefinition($data['path'], $data['class'], array_values($requestMethods), $executionPriority);
        }

        /**
         * Returns the request path the handler is executed for
         *
         * @return string The request path, eg; `/foo/{uuid}`
         */
        public function getPath(): string
        {
            return $this->path;
        }

        /**
         * Returns the fully qualified class name of the request handler
         *
         * @return string The class name without a leading backslash
         */
        public function getClass(): string
        {
            return $this->class;
        }

        /**
         * Returns the request methods the handler is executed for
         *
         * @return string[] The upper-case request methods
         */
        public function getRequestMethods(): array
        {
            return $this->requestMethods;
        }

        /**
         * Returns the execution priority of the handler
         *
         * @return ExecutionPriority|null The execution priority, null if the handler provides a new route
         */
        public function getExecutionPriority(): ?ExecutionPriority
        {
            return $this->executionPriority;
        }

        /**
         * Returns True if the path contains no placeholders or wildcards
         *
         * @return bool True if the path is a literal path
         */
        public function isLiteralPath(): bool
        {
            return !str_contains($this->path, '{') && !str_contains($this->path, '*');
        }

        /**
         * Validates that the handler class exists and implements RequestHandlerInterface, the plugin's package must be
         * imported beforehand so that the class can be autoloaded.
         *
         * @return void
         * @throws PluginException If the class does not exist or is not a request handler
         */
        public function validateClass(): void
        {
            if(!class_exists($this->class))
            {
                throw new PluginException(sprintf('The request handler class "%s" for the path "%s" does not exist', $this->class, $this->path));
            }

            if(!is_subclass_of($this->class, RequestHandlerInterface::class))
            {
                throw new PluginException(sprintf('The request handler class "%s" for the path "%s" must implement %s', $this->class, $this->path, RequestHandlerInterface::class));
            }
        }

        /**
         * Matches the given request against this handler
         *
         * @param string $requestMethod The request method, eg; GET
         * @param string $path The request path
         * @return array|null The named path parameters if the request matches, null otherwise
         */
        public function match(string $requestMethod, string $path): ?array
        {
            if(!in_array(strtoupper($requestMethod), $this->requestMethods, true))
            {
                return null;
            }

            if(!preg_match($this->pattern, $path, $matches))
            {
                return null;
            }

            return array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        }

        /**
         * Compiles the request path into a regular expression
         *
         * @param string $path The request path
         * @return string The compiled regular expression
         * @throws PluginException If the path contains an invalid placeholder or wildcard
         */
        private static function compilePattern(string $path): string
        {
            $segments = explode('/', $path);
            $lastIndex = count($segments) - 1;
            $compiled = [];
            $names = [];

            foreach($segments as $index => $segment)
            {
                if($segment === '*')
                {
                    if($index !== $lastIndex)
                    {
                        throw new PluginException(sprintf('Invalid request handler path "%s", the "*" wildcard may only be the last segment', $path));
                    }

                    // The wildcard also matches the parent path itself, eg; `/foo/*` matches `/foo`
                    $prefix = implode('/', $compiled);
                    return '#^' . $prefix . '(?:/.*)?$#';
                }

                if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)}$/', $segment, $matches))
                {
                    if(isset($names[$matches[1]]))
                    {
                        throw new PluginException(sprintf('Invalid request handler path "%s", the placeholder "%s" is used more than once', $path, $matches[1]));
                    }

                    $names[$matches[1]] = true;
                    $compiled[] = sprintf('(?P<%s>[^/]+)', $matches[1]);
                    continue;
                }

                if(str_contains($segment, '{') || str_contains($segment, '}') || str_contains($segment, '*'))
                {
                    throw new PluginException(sprintf('Invalid request handler path "%s", placeholders must occupy a whole segment, eg; "/foo/{name}"', $path));
                }

                $compiled[] = preg_quote($segment, '#');
            }

            return '#^' . implode('/', $compiled) . '$#';
        }
    }
