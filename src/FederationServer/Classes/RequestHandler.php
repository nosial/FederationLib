<?php

    namespace FederationServer\Classes;

    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\Interfaces\RequestHandlerInterface;
    use FederationServer\Interfaces\SerializableInterface;
    use FederationServer\Objects\OperatorRecord;
    use InvalidArgumentException;
    use Throwable;

    abstract class RequestHandler implements RequestHandlerInterface
    {
        private static ?array $decodedContent = null;
        private static ?string $inputContent = null;
        private static ?string $requestMethod = null;
        private static ?string $path = null;
        private static ?string $uri = null;
        private static ?array $parameters = null;

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
            self::$path = parse_url(self::$uri, PHP_URL_PATH) ?? '/';
            self::$inputContent = file_get_contents('php://input') ?: '';

            // Decode the input content if it's JSON
            if (self::$inputContent && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'))
            {
                self::$decodedContent = json_decode(self::$inputContent, true);
                if (json_last_error() !== JSON_ERROR_NONE)
                {
                    throw new RequestException('Invalid JSON input: ' . json_last_error_msg(), 400);
                }
            }

            // Parse parameters from the URI, POST data, or query string
            self::$parameters = [];

            if (self::$uri)
            {
                $query = parse_url(self::$uri, PHP_URL_QUERY);
                parse_str($query, self::$parameters);
            }

            if (self::$inputContent && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded'))
            {
                parse_str(self::$inputContent, $postParams);
                self::$parameters = array_merge(self::$parameters, $postParams);
            }

            if (self::$requestMethod === 'GET' && !empty($_GET))
            {
                self::$parameters = array_merge(self::$parameters, $_GET);
            }

            if (self::$requestMethod === 'POST' && !empty($_POST))
            {
                self::$parameters = array_merge(self::$parameters, $_POST);
            }
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
         * @return void
         */
        protected static function successResponse(mixed $data=null): void
        {
            if($data instanceof SerializableInterface)
            {
                $data = $data->toArray();
            }

            http_response_code(200);
            self::returnHeaders();
            print(json_encode([
                'success' => true,
                'results' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
            http_response_code($code);
            self::returnHeaders();
            print(json_encode([
                'success' => false,
                'code' => $code,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
            http_response_code($e->getCode() ?: 500);
            self::returnHeaders();
            print(json_encode([
                'success' => false,
                'code' => $e->getCode() ?: 500,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, PUT, GET, DELETE');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }

        /**
         * Get the authenticated operator based on the API key provided in the request.
         *
         * This method retrieves the API key from the request headers or query parameters,
         * validates it, and returns the corresponding OperatorRecord object if found and enabled.
         *
         * @return OperatorRecord|null Returns the authenticated OperatorRecord object or null if not found or disabled.
         * @throws RequestException If the API key is missing, invalid, or the operator is disabled.
         */
        protected static function getAuthenticatedOperator(): ?OperatorRecord
        {
            $apiKey = null;
            if (isset($_SERVER['HTTP_AUTHORIZATION']))
            {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
                if (preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches))
                {
                    $apiKey = $matches[1];

                }
            }

            if (empty($apiKey))
            {
                return null;
            }

            if (strlen($apiKey) !== 32)
            {
                throw new RequestException('Invalid API key', 400);
            }

            // If the given API key matches the master operator's API key, we can retrieve the master operator.
            if(Configuration::getServerConfiguration()->getApiKey() !== null && $apiKey === Configuration::getServerConfiguration()->getApiKey())
            {
                // A master operator is automatically created if it does not exist.
                // This is useful for initial setup or if the master operator was deleted.
                // Master operators cannot be disabled, so we can safely return it.

                try
                {
                    return OperatorManager::getMasterOperator();
                }
                catch (DatabaseOperationException $e)
                {
                    throw new RequestException('Internal Database Error', 500, $e);
                }
                catch(InvalidArgumentException $e)
                {
                    throw new RequestException('Invalid API Key Configuration', 500, $e);
                }
            }

            try
            {
                $operator = OperatorManager::getOperatorByApiKey($apiKey);
                if ($operator === null)
                {
                    throw new RequestException('Invalid API key', 401);
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
    }

