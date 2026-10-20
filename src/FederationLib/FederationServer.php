<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Enums\RecordType;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\Method;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ServerInformation;
    use InvalidArgumentException;
    use Throwable;

    class FederationServer extends RequestHandler
    {
        /**
         * Handle incoming requests to the Federation Server.
         *
         * @return void
         */
        public static function handleRequest(): void
        {
            $postRequestHandlers = [];

            try
            {
                // Always call parent::handleRequest() to ensure the base request handling is done.
                parent::handleRequest();

                if (self::getPath() !== null && str_ends_with(self::getPath(), 'favicon.ico'))
                {
                    self::handleFaviconRequest();
                    return;
                }

                // Import the configured plugins, a plugin that is not correctly configured fails the request.
                $pluginRoute = PluginManager::matchRequest(self::getRequestMethod(), self::getPath());

                // Execute the request method
                $requestMethod = Method::matchHandle(self::getRequestMethod(), self::getPath());
                if($requestMethod === null && !$pluginRoute->hasHandler())
                {
                    self::errorResponse('Invalid request method or path.', 400);
                    return;
                }

                $postRequestHandlers = $pluginRoute->getPostRequestHandlers();

                // PRE_REQUEST handlers prevent the request handler from executing by responding (or throwing)
                foreach($pluginRoute->getPreRequestHandlers() as $preRequestHandler)
                {
                    PluginManager::execute($preRequestHandler);
                    if(self::isResponseSent())
                    {
                        break;
                    }
                }

                if(!self::isResponseSent())
                {
                    if($pluginRoute->getOverrideHandler() !== null)
                    {
                        // An OVERRIDE handler replaces the original request handler
                        PluginManager::execute($pluginRoute->getOverrideHandler());
                    }
                    elseif($requestMethod !== null)
                    {
                        // Handle the request based on the matched method.
                        $requestMethod->handleRequest();
                    }
                    else
                    {
                        // A route provided by a plugin
                        PluginManager::execute($pluginRoute->getRequestHandler());
                    }
                }
            }
            catch(InvalidArgumentException $e)
            {
                Logger::log()->debug('Request Error: ' . $e->getMessage());
                self::throwableResponse(new RequestException($e->getMessage(), 400, $e));
            }
            catch (RequestException $e)
            {
                if($e->getCode() >= 500)
                {
                    Logger::log()->error('Request Error: ' . $e->getMessage(), $e);
                }
                else
                {
                    Logger::log()->debug('Request Error: ' . $e->getMessage());
                }

                self::throwableResponse($e);
            }
            catch(Exception $e)
            {
                Logger::log()->critical('Uncaught Exception:' . $e->getMessage(), $e);
                self::errorResponse('Internal Server Error');
            }
            catch(Throwable $e)
            {
                Logger::log()->critical('Uncaught Throwable:' . $e->getMessage(), $e);
                self::errorResponse('Internal Server Error');
            }

            if(count($postRequestHandlers) === 0)
            {
                return;
            }

            // POST_REQUEST handlers execute after the response, so they can no longer write a response and their
            // errors can only be logged.
            self::markResponseSent();
            foreach($postRequestHandlers as $postRequestHandler)
            {
                try
                {
                    PluginManager::execute($postRequestHandler);
                }
                catch(Throwable $e)
                {
                    Logger::log()->error(sprintf('POST_REQUEST handler %s of plugin %s failed: %s', $postRequestHandler->getDefinition()->getClass(), $postRequestHandler->getPlugin()->getPackage(), $e->getMessage()), $e);
                }
            }
        }

        /**
         * Get a parameter from the request, checking the form-encoded or multipart body, the query string, and the
         * decoded JSON content.
         *
         * This method retrieves a parameter by name from the form-encoded or multipart body, the query string,
         * or decoded JSON content if available. Values are returned as-is so that whitespace
         * content is preserved exactly.
         *
         * @param string $name The name of the parameter to retrieve.
         * @return mixed The value of the parameter or null if not found.
         */
        public static function getParameter(string $name): mixed
        {
            $parameters = self::getRequestParameters();
            if (isset($parameters[$name]) && is_string($parameters[$name]))
            {
                return $parameters[$name];
            }

            $decodedContent = self::getDecodedContent();
            if($decodedContent !== null && is_array($decodedContent))
            {
                // If the parameter is found in the decoded JSON content, return it.
                if (isset($decodedContent[$name]))
                {
                    return $decodedContent[$name];
                }
            }

            // If the parameter is not found in any of the sources, return null.
            return null;
        }

        /**
         * Get a string parameter from the request. A value of another type, such as a JSON array or number, is a
         * client error, unless it is ignored like an unrecognized value (OFD "Pagination, Filtering, and Ordering"
         * ignores unrecognized category, by and order values).
         *
         * @param string $name The name of the parameter
         * @param bool $ignoreInvalid True to treat a value that is not a string as absent instead of rejecting it
         * @return string|null The value of the parameter, or null if it is absent
         * @throws RequestException If the parameter is not a string and is not ignored
         */
        public static function getStringParameter(string $name, bool $ignoreInvalid=false): ?string
        {
            $value = self::getParameter($name);
            if($value === null || is_string($value))
            {
                return $value;
            }

            if($ignoreInvalid)
            {
                return null;
            }

            throw new RequestException(sprintf('The %s parameter must be a string', $name), 400);
        }

        /**
         * Get an integer parameter from the request, given as a JSON integer or as a string of decimal digits in a
         * query string or form body. Any other value, such as 1.5 or 10abc, is a client error rather than being cast.
         *
         * @param string $name The name of the parameter
         * @return int|null The value of the parameter, or null if it is absent
         * @throws RequestException If the parameter is not an integer
         */
        public static function getIntegerParameter(string $name): ?int
        {
            $value = self::getParameter($name);
            if($value === null || is_int($value))
            {
                return $value;
            }

            if(is_string($value) && preg_match('/^-?[0-9]{1,18}$/', $value) === 1)
            {
                return (int)$value;
            }

            throw new RequestException(sprintf('The %s parameter must be an integer', $name), 400);
        }

        /**
         * Get a boolean parameter from the request, given as a JSON boolean or as the string true or false in a query
         * string or form body (OFD "Data Formats"), case-insensitively; 1 and 0 are also accepted, as produced by
         * http_build_query(). Any other value is a client error rather than being read as false.
         *
         * @param string $name The name of the parameter
         * @return bool|null The value of the parameter, or null if it is absent
         * @throws RequestException If the parameter is not a boolean
         */
        public static function getBooleanParameter(string $name): ?bool
        {
            $value = self::getParameter($name);
            if($value === null || is_bool($value))
            {
                return $value;
            }

            $boolean = match(is_string($value) || is_int($value) ? strtolower((string)$value) : null)
            {
                'true', '1' => true,
                'false', '0' => false,
                default => null
            };

            if($boolean === null)
            {
                throw new RequestException(sprintf('The %s parameter must be a boolean', $name), 400);
            }

            return $boolean;
        }

        /**
         * @inheritDoc
         */
        public static function getDecodedContent(): ?array
        {
            return parent::getDecodedContent();
        }

        /**
         * @inheritDoc
         */
        public static function getInputContent(): ?string
        {
            return parent::getInputContent();
        }

        /**
         * @inheritDoc
         */
        public static function getRequestMethod(): ?string
        {
            return parent::getRequestMethod();
        }

        /**
         * @inheritDoc
         */
        public static function getPath(): ?string
        {
            return parent::getPath();
        }

        /**
         * @inheritDoc
         */
        public static function getUri(): ?string
        {
            return parent::getUri();
        }

        /**
         * Get the currently authenticated operator.
         *
         * This method retrieves the currently authenticated operator, if any.
         * If no operator is authenticated, it returns null.
         *
         * @param bool $public True if the method is publicly accessible, an invalid token is then ignored
         * @return OperatorRecord|null The authenticated operator record or null if not authenticated.
         * @throws RequestException If authentication is provided but is invalid/operator is disabled.
         */
        public static function getAuthenticatedOperator(bool $public=false): ?OperatorRecord
        {
            return parent::getAuthenticatedOperator($public);
        }

        /**
         * Get the authenticated operator, throwing an exception if not authenticated.
         *
         * This method retrieves the currently authenticated operator. If no operator is authenticated,
         * it throws a RequestException with a 401 Unauthorized status code.
         *
         * @return OperatorRecord The authenticated operator record.
         * @throws RequestException If no operator is authenticated.
         */
        public static function requireAuthenticatedOperator(): OperatorRecord
        {
            $operator = self::getAuthenticatedOperator();
            if ($operator === null)
            {
                throw new RequestException('Authentication required', HttpResponseCode::UNAUTHORIZED);
            }

            return $operator;
        }

        /**
         * Returns whether a loaded plugin declares that it stores the content submitted for scanning. When the plugins
         * cannot be loaded, it is assumed that they may, so that the server never understates what it collects.
         *
         * @return bool True if a plugin stores scanned content, or the plugins could not be loaded
         */
        private static function pluginsCollectScanContent(): bool
        {
            try
            {
                return array_any(PluginManager::getPlugins(), fn(Plugin $plugin) => $plugin->collectsScanContent());
            }
            catch(Throwable $e)
            {
                Logger::log()->warning('Failed to load the plugins to determine whether they collect scanned content: ' . $e->getMessage(), $e);
                return true;
            }
        }

        /**
         * Returns information about the Federation server instance
         *
         * @return ServerInformation The server information object containing details about the server.
         * @throws RequestException If there is an error retrieving server information, such as a database operation failure.
         */
        public static function getServerInformation(): ServerInformation
        {
            try
            {
                $serverInformation = new ServerInformation([
                    'name' => Configuration::getServerConfiguration()->getName(),
                    'public_audit_logs' => Configuration::getServerConfiguration()->isAuditLogsPublic(),
                    'public_evidence' => Configuration::getServerConfiguration()->isEvidencePublic(),
                    'public_blacklist' => Configuration::getServerConfiguration()->isBlacklistPublic(),
                    'public_entities' => Configuration::getServerConfiguration()->isEntitiesPublic(),
                    'public_reports' => Configuration::getServerConfiguration()->isReportsPublic(),
                    'public_entity_metadata' => Configuration::getServerConfiguration()->isEntityMetadataPublic(),
                    'public_scan_content' => Configuration::getServerConfiguration()->isScanContentPublic(),
                    'collects_public_scan_content' => Configuration::getScanningConfiguration()->collectsPublicScanContent(
                        Configuration::getServerConfiguration()->isScanContentPublic(),
                        self::pluginsCollectScanContent()
                    ),
                    'public_query_entity' => Configuration::getServerConfiguration()->isQueryEntityPublic(),
                    'allow_illegal_content' => Configuration::getServerConfiguration()->isIllegalContentAllowed(),
                    'allow_url_uploads' => Configuration::getRemoteRetrievalConfiguration()->isUploadsEnabled(),
                    'public_telephone_entities' => Configuration::getServerConfiguration()->isTelephoneEntitiesPublic(),
                    'scan_content_types' => array_map(fn(ContentType $type) => $type->value, ContentType::cases()),
                    'concerns' => array_map(fn(IncidentType $type) => $type->value, Configuration::getScanningConfiguration()->getConcerns()),
                    'default_concerns' => array_map(fn(IncidentType $type) => $type->value, Configuration::getScanningConfiguration()->getDefaultConcerns()),
                    'search_enabled' => Configuration::getSearchConfiguration()->isEnabled(),
                    'public_search' => Configuration::getSearchConfiguration()->isPublicSearch(),
                    'search_types' => array_map(
                        fn(RecordType $type) => $type->value,
                        self::getSearchTypes()
                    ),
                    'public_search_types' => array_map(
                        fn(RecordType $type) => $type->value,
                        self::getPublicSearchTypes()
                    ),
                    'public_audit_logs_visibility' => array_map(
                        fn($type) => $type->value,
                        Configuration::getServerConfiguration()->getPublicAuditEntries()
                    ),
                    'audit_log_records' => AuditLogManager::countRecords(),
                    'blacklist_records' => BlacklistManager::countRecords(),
                    'known_entities' => EntitiesManager::countRecords(),
                    'evidence_records' => EvidenceManager::countRecords(),
                    'file_attachment_records' => FileAttachmentManager::countRecords(),
                    'operators' => OperatorManager::countRecords(),
                    'reports' => ReportManager::countRecords()
                ]);
            }
            catch (Exceptions\DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to retrieve server information: ' . $e->getMessage(), $e);
                throw new RequestException('Unable to retrieve server information', HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            return $serverInformation;
        }
        /**
         * Returns record types with enabled dedicated search endpoints.
         *
         * @return RecordType[]
         */
        private static function getSearchTypes(): array
        {
            $searchConfiguration = Configuration::getSearchConfiguration();
            if (!$searchConfiguration->isEnabled())
            {
                return [];
            }

            $types = [];
            if ($searchConfiguration->isEntitiesEnabled())
            {
                $types[] = RecordType::ENTITY;
            }
            if ($searchConfiguration->isEvidenceEnabled())
            {
                $types[] = RecordType::EVIDENCE;
            }
            if ($searchConfiguration->isBlacklistEnabled())
            {
                $types[] = RecordType::BLACKLIST;
            }
            if ($searchConfiguration->isReportsEnabled())
            {
                $types[] = RecordType::REPORT;
            }
            if ($searchConfiguration->isAttachmentsEnabled())
            {
                $types[] = RecordType::ATTACHMENT;
            }
            if ($searchConfiguration->isAuditLogsEnabled())
            {
                $types[] = RecordType::AUDIT_LOG;
            }
            if ($searchConfiguration->isOperatorsEnabled())
            {
                $types[] = RecordType::OPERATOR;
            }

            return $types;
        }

        /**
         * Returns record types whose dedicated search endpoints are publicly accessible.
         *
         * @return RecordType[]
         */
        private static function getPublicSearchTypes(): array
        {
            $searchConfiguration = Configuration::getSearchConfiguration();
            $serverConfiguration = Configuration::getServerConfiguration();
            if (!$searchConfiguration->isEnabled())
            {
                return [];
            }

            $types = [];
            if ($searchConfiguration->isEntitiesEnabled() && $serverConfiguration->isEntitiesPublic())
            {
                $types[] = RecordType::ENTITY;
            }
            if ($searchConfiguration->isEvidenceEnabled() && $serverConfiguration->isEvidencePublic())
            {
                $types[] = RecordType::EVIDENCE;
            }
            if ($searchConfiguration->isBlacklistEnabled() && $serverConfiguration->isBlacklistPublic())
            {
                $types[] = RecordType::BLACKLIST;
            }
            if ($searchConfiguration->isReportsEnabled() && $serverConfiguration->isReportsPublic())
            {
                $types[] = RecordType::REPORT;
            }
            if ($searchConfiguration->isAuditLogsEnabled() && $serverConfiguration->isAuditLogsPublic())
            {
                $types[] = RecordType::AUDIT_LOG;
            }
            // Operator records are always public
            if ($searchConfiguration->isOperatorsEnabled())
            {
                $types[] = RecordType::OPERATOR;
            }

            return $types;
        }

        /**
         * Handle requests for the favicon.ico file.
         *
         * This method serves the favicon.ico file located in the Resources directory.
         * If the file does not exist, it responds with a 404 Not Found error.
         *
         * @return void
         */
        private static function handleFaviconRequest(): void
        {
            $faviconPath = __DIR__ . DIRECTORY_SEPARATOR . 'Classes' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'favicon.ico';
            if (file_exists($faviconPath))
            {
                header('Content-Type: image/x-icon');
                header('Content-Length: ' . filesize($faviconPath));
                readfile($faviconPath);
            }
        }
    }
