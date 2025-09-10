<?php

    namespace FederationLib;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\Method;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Operator;
    use FederationLib\Objects\ServerInformation;
    use InvalidArgumentException;

    class FederationServer extends RequestHandler
    {
        /**
         * Handle incoming requests to the Federation Server.
         *
         * @return void
         */
        public static function handleRequest(): void
        {
            try
            {
                // Always call parent::handleRequest() to ensure the base request handling is done.
                parent::handleRequest();

                if (self::getPath() !== null && str_ends_with(self::getPath(), 'favicon.ico'))
                {
                    self::handleFaviconRequest();
                    return;
                }

                // Execute the request method
                $requestMethod = Method::matchHandle(self::getRequestMethod(), self::getPath());
                if($requestMethod === null)
                {
                    self::errorResponse('Invalid request method or path.', 400);
                    return;
                }

                // Handle the request based on the matched method.
                $requestMethod->handleRequest();
            }
            catch(InvalidArgumentException $e)
            {
                Logger::log()->warning($e->getMessage(), $e);
                self::throwableResponse(new RequestException($e->getMessage(), 400, $e));
            }
            catch (RequestException $e)
            {
                Logger::log()->error('Request Error: ' . $e->getMessage(), $e);
                self::throwableResponse($e);
            }
            catch(Exception $e)
            {
                Logger::log()->critical('Uncaught Exception:' . $e->getMessage(), $e);
                self::errorResponse('Internal Server Error');
            }
        }

        /**
         * Get a parameter from the request, checking POST, GET, and decoded JSON content.
         *
         * This method retrieves a parameter by name from the POST data, GET parameters,
         * or decoded JSON content if available. It trims the value and returns it.
         *
         * @param string $name The name of the parameter to retrieve.
         * @return mixed The value of the parameter or null if not found.
         */
        public static function getParameter(string $name): mixed
        {
            // Check if the parameter exists in the POST data.
            if (isset($_POST[$name]) && is_string($_POST[$name]))
            {
                return trim($_POST[$name]);
            }

            // If not found in POST, check the GET parameters.
            if (isset($_GET[$name]) && is_string($_GET[$name]))
            {
                return trim($_GET[$name]);
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
         * @return Operator|null The authenticated operator record or null if not authenticated.
         * @throws RequestException If authentication is provided but is invalid/operator is disabled.
         */
        public static function getAuthenticatedOperator(): ?Operator
        {
            return parent::getAuthenticatedOperator();
        }

        /**
         * Get the authenticated operator, throwing an exception if not authenticated.
         *
         * This method retrieves the currently authenticated operator. If no operator is authenticated,
         * it throws a RequestException with a 401 Unauthorized status code.
         *
         * @return Operator The authenticated operator record.
         * @throws RequestException If no operator is authenticated.
         */
        public static function requireAuthenticatedOperator(): Operator
        {
            $operator = self::getAuthenticatedOperator();
            if ($operator === null)
            {
                throw new RequestException('Authentication required', HttpResponseCode::UNAUTHORIZED);
            }

            return $operator;
        }

        /**
         * Returns information about the Federation server instance
         *
         * @return ServerInformation The server information object containing details about the server.
         * @throws RequestException If there is an error retrieving server information, such as a database operation failure.
         */
        public static function getServerInformation(): ServerInformation
        {
            $cacheKey = 'server_information';
            if(Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isSystemCachingEnabled())
            {
                try
                {
                    $cachedInfo = RedisConnection::getRecordFromCache($cacheKey);
                }
                catch (Exceptions\CacheOperationException $e)
                {
                    Logger::log()->error('Failed to retrieve server information from cache: ' . $e->getMessage(), $e);
                    // If caching is enabled but fails, we can still proceed to fetch the information
                    $cachedInfo = false;
                }

                if ($cachedInfo !== false)
                {
                    return ServerInformation::fromArray(json_decode($cachedInfo, true));
                }
            }

            try
            {
                $serverInformation = new ServerInformation([
                    'name' => Configuration::getServerConfiguration()->getName(),
                    'public_audit_logs' => Configuration::getServerConfiguration()->isAuditLogsPublic(),
                    'public_evidence' => Configuration::getServerConfiguration()->isEvidencePublic(),
                    'public_blacklist' => Configuration::getServerConfiguration()->isBlacklistPublic(),
                    'public_entities' => Configuration::getServerConfiguration()->isEntitiesPublic(),
                    'public_audit_logs_visibility' => array_map(
                        fn($type) => $type->value,
                        Configuration::getServerConfiguration()->getPublicAuditEntries()
                    ),
                    'audit_log_records' => AuditLogManager::countRecords(),
                    'blacklist_records' => BlacklistManager::countRecords(),
                    'known_entities' => EntitiesManager::countRecords(),
                    'evidence_records' => EvidenceManager::countRecords(),
                    'file_attachment_records' => FileAttachmentManager::countRecords(),
                    'operators' => OperatorManager::countRecords()
                ]);
            }
            catch (Exceptions\DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to retrieve server information: ' . $e->getMessage(), $e);
                throw new RequestException('Unable to retrieve server information', HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            if(Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isSystemCachingEnabled())
            {
                try
                {
                    RedisConnection::setCacheRecord($serverInformation, $cacheKey, 200); // Cache for 200 seconds
                }
                catch (Exceptions\CacheOperationException $e)
                {
                    Logger::log()->error('Failed to cache server information: ' . $e->getMessage(), $e);
                    // If caching fails, we can still return the server information without caching
                }
            }

            return $serverInformation;
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
