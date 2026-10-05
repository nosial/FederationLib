<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EventType;
    use FederationLib\Enums\ExecutionPriority;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\Method;
    use FederationLib\Exceptions\ContentScanRejectedException;
    use FederationLib\Exceptions\PluginException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Interfaces\AuditLogEventHandlerInterface;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Interfaces\EvidenceClassifiedEventHandlerInterface;
    use FederationLib\Interfaces\RequestHandlerInterface;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\Plugin;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\MatchedRequestHandler;
    use FederationLib\Objects\Plugin\PluginRoute;
    use Throwable;

    class PluginManager
    {
        /** @var array<string, Plugin>|null */
        private static ?array $plugins = null;
        private static ?MatchedRequestHandler $currentHandler = null;
        private static bool $dispatchingAuditLog = false;

        /**
         * Imports and loads all the plugins configured in the plugins configuration, plugins are only loaded once.
         *
         * @return array<string, Plugin> The loaded plugins indexed by their package name
         * @throws PluginException If any of the configured plugins could not be loaded
         */
        public static function loadPlugins(): array
        {
            if(self::$plugins !== null)
            {
                return self::$plugins;
            }

            $plugins = [];
            foreach(Configuration::getPluginsConfiguration()->getPlugins() as $package)
            {
                $plugins[$package] = Plugin::load($package);
                Logger::log()->debug(sprintf('Loaded plugin %s=%s', $package, $plugins[$package]->getVersion() ?? 'unknown'));
            }

            self::$plugins = $plugins;
            return self::$plugins;
        }

        /**
         * Sets the loaded plugins, replacing any plugins that were previously loaded
         *
         * @param Plugin[] $plugins The plugins to use
         * @return void
         */
        public static function setPlugins(array $plugins): void
        {
            self::$plugins = [];
            foreach($plugins as $plugin)
            {
                self::$plugins[$plugin->getPackage()] = $plugin;
            }
        }

        /**
         * Returns the loaded plugins
         *
         * @return array<string, Plugin> The loaded plugins indexed by their package name
         * @throws PluginException If any of the configured plugins could not be loaded
         */
        public static function getPlugins(): array
        {
            return self::loadPlugins();
        }

        /**
         * Returns a loaded plugin by its package name
         *
         * @param string $package The plugin's package name, eg; net.nosial.test_plugin
         * @return Plugin|null The plugin, null if the plugin is not configured
         * @throws PluginException If any of the configured plugins could not be loaded
         */
        public static function getPlugin(string $package): ?Plugin
        {
            return self::loadPlugins()[$package] ?? null;
        }

        /**
         * Validates every configured plugin, unlike loadPlugins() all the issues are collected instead of failing on
         * the first issue so that they can be reported at once. On success the validated plugins become the loaded
         * plugins.
         *
         * @return array{errors: string[], warnings: string[]} The critical issues and the warnings that were found
         */
        public static function validate(): array
        {
            $errors = [];
            $warnings = [];
            $plugins = [];

            foreach(Configuration::getPluginsConfiguration()->getPlugins() as $package)
            {
                try
                {
                    $plugins[$package] = Plugin::load($package);
                }
                catch(PluginException $e)
                {
                    $errors[] = $e->getMessage();
                }
            }

            $result = self::validateRoutes($plugins);
            $errors = array_merge($errors, $result['errors']);
            $warnings = array_merge($warnings, $result['warnings']);

            if(count($errors) === 0)
            {
                self::$plugins = $plugins;
            }

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        /**
         * Validates the request handlers of the given plugins against FederationLib's routes and against each other.
         * Only literal paths (without placeholders or wildcards) can be checked ahead of time.
         *
         * @param Plugin[] $plugins The plugins to validate
         * @return array{errors: string[], warnings: string[]} The critical issues and the warnings that were found
         */
        public static function validateRoutes(array $plugins): array
        {
            $errors = [];
            $warnings = [];
            $requestHandlers = [];
            $overrideHandlers = [];

            foreach($plugins as $plugin)
            {
                foreach($plugin->getRequestHandlers() as $definition)
                {
                    if(!$definition->isLiteralPath())
                    {
                        continue;
                    }

                    foreach($definition->getRequestMethods() as $requestMethod)
                    {
                        $route = sprintf('%s %s', $requestMethod, $definition->getPath());
                        $builtInMethod = Method::matchHandle($requestMethod, $definition->getPath());

                        switch($definition->getExecutionPriority())
                        {
                            case null:
                                if($builtInMethod !== null)
                                {
                                    $errors[] = sprintf('The plugin "%s" defines the request handler "%s" for %s which is already handled by FederationLib (%s), an execution_priority of %s is required', $plugin->getPackage(), $definition->getClass(), $route, $builtInMethod->name, implode(', ', array_map(fn(ExecutionPriority $priority) => $priority->value, ExecutionPriority::cases())));
                                }
                                elseif(isset($requestHandlers[$route]))
                                {
                                    $errors[] = sprintf('The plugin "%s" defines the request handler "%s" for %s which is already handled by the plugin "%s"', $plugin->getPackage(), $definition->getClass(), $route, $requestHandlers[$route]);
                                }
                                else
                                {
                                    $requestHandlers[$route] = $plugin->getPackage();
                                }
                                break;

                            case ExecutionPriority::OVERRIDE:
                                if(isset($overrideHandlers[$route]))
                                {
                                    $errors[] = sprintf('The plugin "%s" defines the OVERRIDE request handler "%s" for %s which is already overridden by the plugin "%s"', $plugin->getPackage(), $definition->getClass(), $route, $overrideHandlers[$route]);
                                }
                                else
                                {
                                    $overrideHandlers[$route] = $plugin->getPackage();
                                }
                                break;

                            default:
                                break;
                        }
                    }
                }
            }

            // Warn about PRE_REQUEST and POST_REQUEST handlers that will never be executed because nothing handles the route
            foreach($plugins as $plugin)
            {
                foreach($plugin->getRequestHandlers() as $definition)
                {
                    if(!$definition->isLiteralPath() || $definition->getExecutionPriority() === null || $definition->getExecutionPriority() === ExecutionPriority::OVERRIDE)
                    {
                        continue;
                    }

                    foreach($definition->getRequestMethods() as $requestMethod)
                    {
                        if(Method::matchHandle($requestMethod, $definition->getPath()) !== null || self::matchRequestFor($plugins, $requestMethod, $definition->getPath())->hasHandler())
                        {
                            continue;
                        }

                        $warnings[] = sprintf('The plugin "%s" defines the %s request handler "%s" for %s %s, but no request handler exists for this route so it will never be executed', $plugin->getPackage(), $definition->getExecutionPriority()->value, $definition->getClass(), $requestMethod, $definition->getPath());
                    }
                }
            }

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        /**
         * Matches the given request against the request handlers of the loaded plugins
         *
         * @param string $requestMethod The request method, eg; GET
         * @param string $path The request path
         * @return PluginRoute The plugin request handlers that matched the request
         * @throws PluginException If any of the configured plugins could not be loaded
         */
        public static function matchRequest(string $requestMethod, string $path): PluginRoute
        {
            return self::matchRequestFor(self::loadPlugins(), $requestMethod, $path);
        }

        /**
         * Executes a matched plugin request handler, while the handler is executing the plugin and the path
         * parameters are available through getCurrentHandler() and PluginRequestHandler.
         *
         * @param MatchedRequestHandler $handler The matched request handler to execute
         * @return void
         */
        public static function execute(MatchedRequestHandler $handler): void
        {
            Logger::log()->debug(sprintf('Executing %s request handler %s of plugin %s', $handler->getDefinition()->getExecutionPriority()?->value ?? 'REQUEST', $handler->getDefinition()->getClass(), $handler->getPlugin()->getPackage()));

            $previousHandler = self::$currentHandler;
            self::$currentHandler = $handler;

            try
            {
                /** @var RequestHandlerInterface $class */
                $class = $handler->getDefinition()->getClass();
                $class::handleRequest();
            }
            finally
            {
                self::$currentHandler = $previousHandler;
            }
        }

        /**
         * Dispatches a created audit log entry to the AUDIT_LOG event handlers of the loaded plugins, in the order the
         * plugins are configured. Event handlers can never affect the audit log entry or the operation that produced
         * it, any failure is logged. Audit log entries created by an event handler are not dispatched again, which
         * prevents event handlers from triggering each other endlessly.
         *
         * @param AuditLog $auditLog The audit log entry that was created
         * @return void
         */
        public static function dispatchAuditLog(AuditLog $auditLog): void
        {
            if(self::$dispatchingAuditLog)
            {
                Logger::log()->debug(sprintf('Not dispatching the audit log entry %s, it was created by an AUDIT_LOG event handler', $auditLog->getUuid()));
                return;
            }

            try
            {
                $plugins = self::loadPlugins();
            }
            catch(PluginException $e)
            {
                Logger::log()->error(sprintf('Unable to dispatch the audit log entry %s to the plugins: %s', $auditLog->getUuid(), $e->getMessage()), $e);
                return;
            }

            self::$dispatchingAuditLog = true;

            try
            {
                foreach($plugins as $plugin)
                {
                    foreach($plugin->getEventHandlers(EventType::AUDIT_LOG) as $definition)
                    {
                        if(!$definition->matches($auditLog->getType()->value))
                        {
                            continue;
                        }

                        Logger::log()->debug(sprintf('Executing AUDIT_LOG event handler %s of plugin %s for %s', $definition->getClass(), $plugin->getPackage(), $auditLog->getUuid()));

                        try
                        {
                            /** @var AuditLogEventHandlerInterface $class */
                            $class = $definition->getClass();
                            $class::handleAuditLog($auditLog);
                        }
                        catch(Throwable $e)
                        {
                            Logger::log()->error(sprintf('The AUDIT_LOG event handler %s of plugin %s failed for %s: %s', $definition->getClass(), $plugin->getPackage(), $auditLog->getUuid(), $e->getMessage()), $e);
                        }
                    }
                }
            }
            finally
            {
                self::$dispatchingAuditLog = false;
            }
        }

        /**
         * Dispatches a classified evidence record to the EVIDENCE_CLASSIFIED event handlers of the loaded plugins, in
         * the order the plugins are configured. Event handlers can never affect the evidence record or the operation
         * that classified it, any failure is logged.
         *
         * @param string $evidenceUuid The UUID of the evidence record that was classified
         * @param ClassificationFlag $classification The classification assigned to the evidence record
         * @return void
         */
        public static function dispatchEvidenceClassified(string $evidenceUuid, ClassificationFlag $classification): void
        {
            try
            {
                $plugins = self::loadPlugins();
            }
            catch(PluginException $e)
            {
                Logger::log()->error(sprintf('Unable to dispatch the classified evidence %s to the plugins: %s', $evidenceUuid, $e->getMessage()), $e);
                return;
            }

            $handlers = [];
            foreach($plugins as $plugin)
            {
                foreach($plugin->getEventHandlers(EventType::EVIDENCE_CLASSIFIED) as $definition)
                {
                    if($definition->matches($classification->value))
                    {
                        $handlers[] = [$plugin, $definition];
                    }
                }
            }

            // The evidence record is only retrieved when there is an event handler to receive it
            if(count($handlers) === 0)
            {
                return;
            }

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
            }
            catch(Throwable $e)
            {
                Logger::log()->error(sprintf('Unable to retrieve the classified evidence %s for the plugins: %s', $evidenceUuid, $e->getMessage()), $e);
                return;
            }

            if($evidenceRecord === null)
            {
                Logger::log()->warning(sprintf('The classified evidence %s no longer exists, not dispatching it to the plugins', $evidenceUuid));
                return;
            }

            /** @var Plugin $plugin */
            foreach($handlers as [$plugin, $definition])
            {
                Logger::log()->debug(sprintf('Executing EVIDENCE_CLASSIFIED event handler %s of plugin %s for %s', $definition->getClass(), $plugin->getPackage(), $evidenceUuid));

                try
                {
                    /** @var EvidenceClassifiedEventHandlerInterface $class */
                    $class = $definition->getClass();
                    $class::handleEvidenceClassified($evidenceRecord, $classification);
                }
                catch(Throwable $e)
                {
                    Logger::log()->error(sprintf('The EVIDENCE_CLASSIFIED event handler %s of plugin %s failed for %s: %s', $definition->getClass(), $plugin->getPackage(), $evidenceUuid, $e->getMessage()), $e);
                }
            }
        }

        /**
         * Dispatches a content scan to the CONTENT_SCAN event handlers of the loaded plugins, in the order the plugins
         * are configured. Each event handler receives the same ContentScan and may add scanning rules to it or reject
         * the request. A rejection (or any RequestException) stops the remaining event handlers and is thrown to the
         * caller, any other failure of an event handler is logged and the scan continues without the scanning rules
         * and classifications that event handler added.
         *
         * @param ContentScan $contentScan The content scan
         * @return void
         * @throws RequestException If an event handler rejected the request, or the plugins could not be loaded
         */
        public static function dispatchContentScan(ContentScan $contentScan): void
        {
            try
            {
                $plugins = self::loadPlugins();
            }
            catch(PluginException $e)
            {
                // Fail closed, a plugin that is meant to reject content must never be skipped silently
                throw new RequestException('Unable to scan the content, the plugins could not be loaded', HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            foreach($plugins as $plugin)
            {
                foreach($plugin->getEventHandlers(EventType::CONTENT_SCAN) as $definition)
                {
                    Logger::log()->debug(sprintf('Executing CONTENT_SCAN event handler %s of plugin %s', $definition->getClass(), $plugin->getPackage()));
                    $state = $contentScan->getState();

                    try
                    {
                        /** @var ContentScanEventHandlerInterface $class */
                        $class = $definition->getClass();
                        $class::handleContentScan($contentScan);
                    }
                    catch(ContentScanRejectedException $e)
                    {
                        Logger::log()->info(sprintf('The content scan was rejected by the CONTENT_SCAN event handler %s of plugin %s: %s', $definition->getClass(), $plugin->getPackage(), $e->getMessage()));
                        throw $e;
                    }
                    catch(RequestException $e)
                    {
                        throw $e;
                    }
                    catch(Throwable $e)
                    {
                        Logger::log()->error(sprintf('The CONTENT_SCAN event handler %s of plugin %s failed: %s', $definition->getClass(), $plugin->getPackage(), $e->getMessage()), $e);

                        // Discard the scanning rules and classifications the failed event handler may have added
                        $contentScan->restoreState($state);
                    }
                }
            }
        }

        /**
         * Returns the plugin request handler that is currently executing
         *
         * @return MatchedRequestHandler|null The executing request handler, null if no plugin handler is executing
         */
        public static function getCurrentHandler(): ?MatchedRequestHandler
        {
            return self::$currentHandler;
        }

        /**
         * Matches the given request against the request handlers of the given plugins
         *
         * @param Plugin[] $plugins The plugins to match against, in order of precedence
         * @param string $requestMethod The request method, eg; GET
         * @param string $path The request path
         * @return PluginRoute The plugin request handlers that matched the request
         */
        private static function matchRequestFor(array $plugins, string $requestMethod, string $path): PluginRoute
        {
            $route = new PluginRoute();
            foreach($plugins as $plugin)
            {
                foreach($plugin->getRequestHandlers() as $definition)
                {
                    $pathParameters = $definition->match($requestMethod, $path);
                    if($pathParameters === null)
                    {
                        continue;
                    }

                    if(!$route->add(new MatchedRequestHandler($plugin, $definition, $pathParameters)))
                    {
                        Logger::log()->warning(sprintf('Ignoring the request handler %s of plugin %s for [%s] %s, another plugin request handler takes precedence', $definition->getClass(), $plugin->getPackage(), $requestMethod, $path));
                    }
                }
            }

            return $route;
        }
    }
