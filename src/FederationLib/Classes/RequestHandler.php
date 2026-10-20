<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestHandlerInterface;
    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Interfaces\StandardObjectInterface;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\SuccessResponse;
    use InvalidArgumentException;
    use Throwable;
    use Traversable;

    abstract class RequestHandler implements RequestHandlerInterface
    {
        private static ?array $decodedContent = null;
        private static ?string $inputContent = null;
        private static ?string $requestMethod = null;
        private static ?string $path = null;
        private static ?string $uri = null;
        private static ?array $parameters = null;
        private static bool $responseSent = false;

        /**
         * Handle the incoming request.
         *
         * This method should be implemented by subclasses to handle specific requests.
         * It is responsible for processing the request and returning a response.
         *
         * @return void
         * @throws RequestException
         */
        public static function handleRequest(): void
        {
            self::$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
            self::$uri = $_SERVER['REQUEST_URI'] ?? '/';
            $segments = array_map('rawurldecode', explode('/', parse_url(self::$uri, PHP_URL_PATH) ?? '/'));
            foreach($segments as $segment)
            {
                if(str_contains($segment, '/'))
                {
                    throw new RequestException('Invalid path segment', 400);
                }
            }

            self::$path = implode('/', $segments);
            self::$decodedContent = null;
            self::$parameters = [];

            // PHP only parses the form-encoded and multipart bodies of POST requests into $_POST and $_FILES, the body
            // of any other request is parsed here before it is read as php://input
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $bodyParameters = [];
            if(self::$requestMethod === 'POST')
            {
                $bodyParameters = $_POST;
            }
            elseif(str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded'))
            {
                try
                {
                    [$bodyParameters, $_FILES] = request_parse_body();
                }
                catch(\RequestParseBodyException $e)
                {
                    throw new RequestException('Invalid request body', 400, $e);
                }
            }

            self::$inputContent = file_get_contents('php://input') ?: '';

            // Decode the input content if it's JSON
            if (self::$inputContent && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'))
            {
                self::$decodedContent = json_decode(self::$inputContent, true);
                if (json_last_error() !== JSON_ERROR_NONE)
                {
                    throw new RequestException(json_last_error_msg(), 400);
                }
            }

            parse_str((string)parse_url(self::$uri, PHP_URL_QUERY), self::$parameters);
            self::$parameters = array_merge(self::$parameters, $bodyParameters);
        }

        /**
         * Get the parameters of the query string and of a form-encoded or multipart request body, the body taking
         * precedence over the query string.
         *
         * @return array The request parameters
         */
        protected static function getRequestParameters(): array
        {
            return self::$parameters ?? [];
        }

        /**
         * Get the decoded JSON content from the request body, if available.
         *
         * @return array|null Decoded JSON content or null if not available.
         */
        protected static function getDecodedContent(): ?array
        {
            return self::$decodedContent;
        }

        /**
         * Get the raw input content from the request body.
         *
         * @return string|null Raw input content or null if not available.
         */
        protected static function getInputContent(): ?string
        {
            return self::$inputContent;
        }

        /**
         * Get the HTTP request method (GET, POST, etc.).
         *
         * @return string|null HTTP request method or null if not available.
         */
        protected static function getRequestMethod(): ?string
        {
            return self::$requestMethod;
        }

        /**
         * Get the path component of the request URI.
         *
         * @return string|null Path or null if not available.
         */
        protected static function getPath(): ?string
        {
            return self::$path;
        }

        /**
         * Resolves any entity identifier form (UUID, SHA-256 identifier or entity address) to its entity record,
         * rejecting an identifier that is not valid with HTTP 400.
         *
         * @param string $identifier The entity identifier
         * @param string $invalidIdentifierMessage The error message to respond with when the identifier is not valid
         * @return EntityRecord|null The entity record, or null if no entity has the identifier
         * @throws RequestException If the identifier is not a valid entity identifier
         * @throws DatabaseOperationException If there is an error retrieving the entity
         */
        protected static function resolveEntityIdentifier(string $identifier, string $invalidIdentifierMessage): ?EntityRecord
        {
            try
            {
                $entityRecord = EntitiesManager::getEntityByIdentifier($identifier);
            }
            catch (InvalidArgumentException $e)
            {
                throw new RequestException($invalidIdentifierMessage, HttpResponseCode::BAD_REQUEST, $e);
            }

            // The UUIDs of entities appear in other records, so a hidden telephone entity identified by its UUID is
            // treated as though it did not exist; only its address or SHA-256 identifier, which require knowing the
            // number, resolve it (OFD "Telephone Entities")
            if($entityRecord !== null && $entityRecord->isTelephone() && Utilities::isUuid($identifier) && self::hideTelephoneEntities())
            {
                return null;
            }

            return $entityRecord;
        }

        /**
         * Parses a request whose parameters are a set of optional boolean members, of which at least one is supplied
         * (eg; a partial OperatorPermissions object). A JSON body may only contain the given members, each a JSON
         * boolean; form-encoded or query parameters carry booleans as the strings true and false (OFD "Data Formats").
         *
         * @param array|null $body The decoded JSON request body, null when the parameters are not JSON
         * @param string[] $members The members that may be supplied
         * @param string $errorMessage The error message of the client error when the request is invalid
         * @return array<string, bool> The supplied members and their values
         * @throws RequestException If no member is supplied, or a member is unknown or not a boolean
         */
        protected static function parseBooleanMembers(?array $body, array $members, string $errorMessage): array
        {
            $values = [];
            if($body !== null)
            {
                if(array_is_list($body) && count($body) > 0)
                {
                    throw new RequestException($errorMessage, HttpResponseCode::BAD_REQUEST);
                }

                foreach($body as $member => $value)
                {
                    if(!in_array($member, $members, true) || !is_bool($value))
                    {
                        throw new RequestException($errorMessage, HttpResponseCode::BAD_REQUEST);
                    }

                    $values[$member] = $value;
                }
            }
            else
            {
                foreach($members as $member)
                {
                    $value = FederationServer::getParameter($member);
                    if($value === null)
                    {
                        continue;
                    }

                    $value = is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
                    if($value === null)
                    {
                        throw new RequestException($errorMessage, HttpResponseCode::BAD_REQUEST);
                    }

                    $values[$member] = $value;
                }
            }

            if(count($values) === 0)
            {
                throw new RequestException($errorMessage, HttpResponseCode::BAD_REQUEST);
            }

            return $values;
        }

        /**
         * Checks whether telephone entities are hidden from the current request: they are hidden from unauthenticated
         * requests unless the host exposes them (OFD "Telephone Entities"). A hidden telephone entity is returned only
         * to a request that identifies it by its address or SHA-256 identifier, or submits it for scanning, and its
         * metadata is never returned.
         *
         * @return bool True if telephone entities are hidden from the current request
         * @throws RequestException Thrown if there was an authentication error
         */
        protected static function hideTelephoneEntities(): bool
        {
            return !Configuration::getServerConfiguration()->isTelephoneEntitiesPublic() && self::getAuthenticatedOperator(true) === null;
        }

        /**
         * Get the full request URI.
         *
         * @return string|null Request URI or null if not available.
         */
        protected static function getUri(): ?string
        {
            return self::$uri;
        }

        /**
         * Respond with a success message and data.
         *
         * @param mixed $data Data to include in the response.
         * @param int|HttpResponseCode $responseCode Optional. The response code of the success response
         * @return void
         */
        protected static function successResponse(mixed $data = null, int|HttpResponseCode $responseCode=200): void
        {
            if(!self::beginResponse())
            {
                return;
            }

            if($responseCode instanceof HttpResponseCode)
            {
                $responseCode = $responseCode->value;
            }

            if($data !== null)
            {
                $data = self::normalizeData($data);
            }

            http_response_code($responseCode);
            self::returnHeaders();

            if($data === null)
            {
                print(json_encode(new SuccessResponse()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            else
            {
                print(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }

        /**
         * Recursively normalize response data, preferring the standardized representation
         * (toStandardArray) when available and falling back to the full serialization
         * (toArray) otherwise. Applied to every object in the response so RequestHandlers
         * cannot bypass the standardized output (e.g. leaking the operator access_token).
         *
         * @param mixed $data Data to normalize.
         * @return mixed Normalized data.
         */
        private static function normalizeData(mixed $data): mixed
        {
            if($data instanceof StandardObjectInterface)
            {
                return $data->toStandardArray();
            }

            if($data instanceof SerializableInterface)
            {
                return $data->toArray();
            }

            if(is_array($data))
            {
                return array_map([self::class, 'normalizeData'], $data);
            }

            if($data instanceof Traversable)
            {
                return array_map([self::class, 'normalizeData'], iterator_to_array($data));
            }

            return $data;
        }

        /**
         * Respond with an error message.
         *
         * @param string $message Error message to include in the response.
         * @param int $code HTTP status code (default is 500).
         * @return void
         */
        protected static function errorResponse(string $message, int $code=500): void
        {
            if(!self::beginResponse())
            {
                return;
            }

            http_response_code($code);
            self::returnHeaders();
            print(json_encode(new ErrorResponse($code, $message)->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        /**
         * Handle a Throwable exception and return a JSON response.
         *
         * This method captures the exception details and returns a JSON response
         * with the error code and message.
         *
         * @param Throwable $e The exception to handle.
         * @return void
         */
        protected static function throwableResponse(Throwable $e): void
        {
            if(!self::beginResponse())
            {
                return;
            }

            http_response_code($e->getCode() ?: 500);
            self::returnHeaders();
            print(json_encode(new ErrorResponse($e->getCode() ?: 500, $e->getMessage())->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        /**
         * Returns True if a response has already been sent for the current request
         *
         * @return bool True if a response has been sent
         */
        public static function isResponseSent(): bool
        {
            return self::$responseSent;
        }

        /**
         * Marks the response of the current request as sent, request handlers that write their response directly
         * (instead of using the response helpers) can use this to prevent any further response from being written,
         * eg; a PRE_REQUEST plugin handler preventing the original request handler from executing.
         *
         * @return void
         */
        protected static function markResponseSent(): void
        {
            self::$responseSent = true;
        }

        /**
         * Marks the response as sent, returns False if a response was already sent so that a second response is never
         * appended to the first one.
         *
         * @return bool True if the response may be written
         */
        private static function beginResponse(): bool
        {
            if(self::$responseSent)
            {
                Logger::log()->warning(sprintf('Ignoring a response for [%s] %s, a response has already been sent', self::$requestMethod, self::$path));
                return false;
            }

            self::$responseSent = true;
            return true;
        }

        /**
         * Set the response headers for JSON output.
         *
         * This method sets the necessary headers for a JSON response,
         * including content type and CORS headers.
         *
         * @return void
         */
        protected static function returnHeaders(): void
        {
            header('Content-Type: application/json; charset=utf-8');
            self::returnCorsHeaders();
        }

        /**
         * Responds to a CORS preflight (OPTIONS) request with HTTP 204 No Content and the CORS headers, preflight
         * requests carry no access token and are never authenticated.
         *
         * @return void
         */
        protected static function preflightResponse(): void
        {
            if(!self::beginResponse())
            {
                return;
            }

            http_response_code(204);
            self::returnCorsHeaders();
        }

        /**
         * Set the CORS headers of the response, cross-origin requests are permitted only from origins whose host name
         * equals the host name of the configured base URL.
         *
         * @return void
         */
        private static function returnCorsHeaders(): void
        {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
            $baseUrl = Configuration::getServerConfiguration()->getBaseUrl();
            // Without a matching Origin, the origin of the base URL is used, which is the base URL without its path
            $allowedOrigin = self::getOrigin($baseUrl) ?? $baseUrl;

            if ($origin !== null)
            {
                $originHost = parse_url($origin, PHP_URL_HOST);
                $baseHost = parse_url($baseUrl, PHP_URL_HOST);
                if ($originHost === $baseHost)
                {
                    $allowedOrigin = $origin;
                }
                header('Access-Control-Allow-Origin: ' . $allowedOrigin);
                header('Vary: Origin');
            }
            else
            {
                header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            }

            header('Access-Control-Allow-Methods: POST, PUT, GET, DELETE, PATCH');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }

        /**
         * Returns the origin of a URL, which is its scheme, host and port without its path.
         *
         * @param string $url The URL
         * @return string|null The origin of the URL, or null if the URL has no scheme or host
         */
        private static function getOrigin(string $url): ?string
        {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            if(!is_string($scheme) || !is_string($host))
            {
                return null;
            }

            $port = parse_url($url, PHP_URL_PORT);
            return strtolower($scheme) . '://' . $host . ($port !== null ? ':' . $port : '');
        }

        /**
         * Get the authenticated operator based on the Access Token provided in the request.
         *
         * This method retrieves the Access Token from the request headers or query parameters,
         * validates it, and returns the corresponding OperatorRecord object if found and enabled.
         *
         * A request to a method that does not require authentication ignores a token that does not authenticate an
         * operator, so a malformed, unknown or disabled token is processed as an unauthenticated request instead of
         * being rejected, a valid token still authenticates the operator for information returned only to operators.
         *
         * @param bool $public True if the method is publicly accessible and does not require authentication
         * @return OperatorRecord|null Returns the authenticated OperatorRecord object or null if not found or disabled.
         * @throws RequestException If the Access Token is missing, invalid, or the operator is disabled.
         */
        protected static function getAuthenticatedOperator(bool $public=false): ?OperatorRecord
        {
            if(!$public)
            {
                return self::authenticateOperator();
            }

            try
            {
                return self::authenticateOperator();
            }
            catch(RequestException $e)
            {
                if($e->getCode() >= 500)
                {
                    throw $e;
                }

                return null;
            }
        }

        /**
         * Validates the Access Token provided in the request and returns the operator it authenticates.
         *
         * @return OperatorRecord|null Returns the authenticated OperatorRecord object or null if no token was given.
         * @throws RequestException If the Access Token is invalid, or the operator is disabled.
         */
        private static function authenticateOperator(): ?OperatorRecord
        {
            $accessToken = null;
            if (isset($_SERVER['HTTP_AUTHORIZATION']))
            {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
                if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches))
                {
                    $accessToken = $matches[1];

                }
            }

            if (empty($accessToken))
            {
                return null;
            }

            if (strlen($accessToken) !== 32)
            {
                throw new RequestException('Invalid Access Token', 400);
            }

            // If the given Access Token matches the master operator's Access Token, we can retrieve the master operator.
            $configuredAccessToken = Configuration::getServerConfiguration()->getAccessToken();
            if($configuredAccessToken !== null && hash_equals($configuredAccessToken, $accessToken))
            {
                // A master operator is automatically created if it does not exist.
                // This is useful for initial setup or if the master operator was deleted.
                // Master operators cannot be disabled, so we can safely return it.

                try
                {
                    return OperatorManager::getRootOperator();
                }
                catch (DatabaseOperationException $e)
                {
                    throw new RequestException('Internal Database Error', 500, $e);
                }
                catch(InvalidArgumentException $e)
                {
                    throw new RequestException('Invalid Access token Configuration', 500, $e);
                }
            }

            try
            {
                $operator = OperatorManager::getOperatorByAccessToken($accessToken);

                if ($operator === null)
                {
                    throw new RequestException('Invalid Access Token', 401);
                }

                // Even though we checked before, we're still checking here for sanity’s sake
                if($operator->getAccessToken() === 'none' || $operator->getName() === 'system')
                {
                    throw new RequestException('Operator access not allowed', 403);
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Database Error', 500, $e);
            }

            if ($operator->isDisabled())
            {
                throw new RequestException('Operator is disabled', 403);
            }

            return $operator;
        }

        /**
         * Checks whether entity metadata should be omitted from the response for unauthenticated users.
         *
         * @return bool True if entity metadata should be hidden from unauthenticated users, false otherwise.
         * @throws RequestException Thrown if there was an authentication error
         */
        protected static function omitEntityMetadata(): bool
        {
            return !Configuration::getServerConfiguration()->isEntityMetadataPublic() && self::getAuthenticatedOperator(true) === null;
        }

        /**
         * Checks whether a search should also match the columns of the extended search, which is limited to
         * authenticated operators for performance reasons.
         *
         * @return bool True if the extended search should be used, false otherwise.
         * @throws RequestException Thrown if there was an authentication error
         */
        protected static function useExtendedSearch(): bool
        {
            return Configuration::getSearchConfiguration()->isExtendedSearchEnabled() && self::getAuthenticatedOperator(true) !== null;
        }
    }

