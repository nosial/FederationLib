<?php

    namespace FederationServer;

    use Exception;
    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Enums\HttpResponseCode;
    use FederationServer\Classes\Enums\Method;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\Objects\OperatorRecord;
    use FederationServer\Objects\ServerInformation;

    class FederationServer extends RequestHandler
    {
        private static ?string $decodedContent = null;

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
            catch (RequestException $e)
            {
                Logger::log()->verbose('Request Error: ' . $e->getMessage());
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

            if(self::$decodedContent !== null && is_array(self::$decodedContent))
            {
                // If the parameter is found in the decoded JSON content, return it.
                if (isset(self::$decodedContent[$name]))
                {
                    return self::$decodedContent[$name];
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
         * @return OperatorRecord|null The authenticated operator record or null if not authenticated.
         * @throws RequestException If authentication is provided but is invalid/operator is disabled.
         */
        public static function getAuthenticatedOperator(): ?OperatorRecord
        {
            return parent::getAuthenticatedOperator();
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
         * Returns information about the Federation server instance
         *
         * @return ServerInformation The server information object containing details about the server.
         */
        public static function getServerInformation(): ServerInformation
        {
            return new ServerInformation([
                'name' => Configuration::getServerConfiguration()->getName(),
                'public_audit_logs' => Configuration::getServerConfiguration()->isAuditLogsPublic(),
                'public_evidence' => Configuration::getServerConfiguration()->isEvidencePublic(),
                'public_blacklist' => Configuration::getServerConfiguration()->isBlacklistPublic(),
                'public_entities' => Configuration::getServerConfiguration()->isEntitiesPublic(),
            ]);
        }
    }
