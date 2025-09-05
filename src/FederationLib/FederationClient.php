<?php

    namespace FederationLib;

    use CURLFile;
    use CurlHandle;
    use FederationLib\Classes\Logger;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Interfaces\ResponseInterface;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\Entity;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Operator;
    use FederationLib\Objects\ServerInformation;
    use FederationLib\Objects\SuccessResponse;
    use FederationLib\Objects\UploadResult;
    use InvalidArgumentException;

    class FederationClient
    {
        private string $endpoint;
        private ?string $apiKey;

        /**
         * Constructor for FederationClient
         *
         * @param string $endpoint The endpoint URL for the federation server
         * @param string|null $apiKey Optional token for authentication
         * @throws InvalidArgumentException If the endpoint is not a valid URL or if the token is an empty string
         */
        public function __construct(string $endpoint, ?string $apiKey=null)
        {
            if(empty($endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL))
            {
                throw new InvalidArgumentException("Endpoint must be a valid URL");
            }

            if($apiKey !== null && strlen($apiKey) === 0)
            {
                throw new InvalidArgumentException("Token cannot be an empty string");
            }

            $endpoint = rtrim($endpoint, '/');
            $this->endpoint = $endpoint;
            $this->apiKey = $apiKey;
        }

        /**
         * Get the endpoint URL
         *
         * @return string The endpoint URL
         */
        public function getEndpoint(): string
        {
            return $this->endpoint;
        }

        /**
         * Get the authentication token
         *
         * @return string|null The authentication token or null if not set
         */
        public function getApiKey(): ?string
        {
            return $this->apiKey;
        }

        /**
         * Makes an HTTP request to the specified endpoint
         *
         * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
         * @param string $path API endpoint path
         * @param array|null $data Request data (for POST/PUT requests)
         * @param array $expectedStatusCodes Expected successful HTTP status codes
         * @param string $errorMessage Custom error message prefix
         * @return mixed Response data from successful request
         * @throws RequestException On request failure or unexpected status code
         */
        private function makeRequest(string $method, string $path, ?array $data = null, array $expectedStatusCodes = [200], string $errorMessage='Request failed'): mixed
        {
            // Remove leading slash from path if present
            $path = ltrim($path, '/');

            // For GET requests, append data as query parameters
            if (strtoupper($method) === 'GET' && !empty($data))
            {
                $queryString = http_build_query($data);
                $path .= '?' . $queryString;
            }

            // Convert any ResponseCode enums to their integer values
            $expectedStatusCodes = array_map(fn($code) => $code instanceof HttpResponseCode ? $code->value : $code, $expectedStatusCodes);

            Logger::log()->debug(sprintf("%s Request to %s", $method, $this->buildUrl($path)));

            $ch = $this->buildCurl($path);
            switch (strtoupper($method))
            {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                    break;

                case 'PUT':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    if ($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                    break;

                case 'DELETE':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    break;

                case 'GET':
                default:
                    // GET is default, no additional setup needed
                    break;
            }

            $response = curl_exec($ch);

            // Handle cURL errors
            if (curl_errno($ch))
            {
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                throw new RequestException($errorMessage . ': ' . $curlError, HttpResponseCode::from($httpCode));
            }

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $decodedResponse = $this->decodeResponse($response);

            // Check if response code is expected
            if (!in_array($responseCode, $expectedStatusCodes))
            {
                $errorMsg = $errorMessage . ' received response code: ' . $responseCode;
                if ($decodedResponse instanceof ErrorResponse) {
                    $errorMsg = $errorMessage . ', ' . $decodedResponse->getMessage() . ' received response code: ' . $responseCode;
                }
                throw new RequestException($errorMsg, $responseCode);
            }

            if ($decodedResponse instanceof ErrorResponse)
            {
                throw new RequestException(
                    $decodedResponse->getMessage(),
                    $decodedResponse->getCode()
                );
            }

            /** @var SuccessResponse $decodedResponse */
            return $decodedResponse->getData();
        }

        // OPERATOR METHODS

        /**
         * Retrieves server information.
         *
         * @return ServerInformation The server information object
         * @throws RequestException If the request fails or the response is invalid
         */
        public function getServerInformation(): ServerInformation
        {
            return ServerInformation::fromArray($this->makeRequest('GET', 'info', null, [HttpResponseCode::OK],
                'Failed to get server information'
            ));
        }

        /**
         * Creates a new operator with the given name, the name can be anything but must not be empty. The name
         * is not required to be unique.
         *
         * @param string $operatorName The name of the operator to create
         * @return string The UUID of the created operator
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator name is empty
         */
        public function createOperator(string $operatorName): string
        {
            if(empty($operatorName))
            {
                throw new InvalidArgumentException('Operator name cannot be empty');
            }

            return $this->makeRequest('POST', 'operators', ['name' => $operatorName], [HttpResponseCode::CREATED],
                sprintf('Failed to create operator with name %s', $operatorName)
            );
        }

        /**
         * Deletes the operator with the given UUID.
         *
         * @param string $operatorUuid The UUID of the operator to delete
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function deleteOperator(string $operatorUuid): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('DELETE', 'operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Disables the operator with the given UUID.
         *
         * @param string $operatorUuid The UUID of the operator to disable
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function disableOperator(string $operatorUuid): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/disable', null, [HttpResponseCode::OK],
                sprintf('Failed to disable operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Enables the operator with the given UUID.
         *
         * @param string $operatorUuid The UUID of the operator to enable
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function enableOperator(string $operatorUuid): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/enable', null, [HttpResponseCode::OK],
                sprintf('Failed to enable operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Retrieves the operator with the given UUID.
         *
         * @param string $operatorUuid The UUID of the operator to retrieve
         * @return Operator The retrieved operator object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function getOperator(string $operatorUuid): Operator
        {
            IF(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            return Operator::fromArray($this->makeRequest('GET', 'operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                'Failed to get operator'
            ));
        }

        /**
         * Retrieves the operator associated with the current authentication token.
         *
         * @return Operator The retrieved operator object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the authentication token is not set
         */
        public function getSelf(): Operator
        {
            return Operator::fromArray($this->makeRequest('GET', 'operators/self', null, [HttpResponseCode::OK],
                'Failed to get self operator'
            ));
        }

        /**
         * Lists operators with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of operators per page (default is 100)
         * @return Operator[] An array of Operator objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listOperators(int $page=1, int $limit=100): array
        {
            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => Operator::fromArray($item),
                $this->makeRequest('GET', 'operators', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list operators, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Lists audit logs for a specific operator with pagination support.
         *
         * @param string $operatorUuid The UUID of the operator whose audit logs are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of audit logs per page (default is 100)
         * @return AuditLog[] An array of AuditLog objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty or if the page or limit parameters are invalid
         */
        public function listOperatorAuditLogs(string $operatorUuid, int $page=1, int $limit=100): array
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => AuditLog::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/audit', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list audit logs for operator with UUID %s, page: %d, limit: %d', $operatorUuid, $page, $limit)
                )
            );
        }

        /**
         * Lists evidence records for a specific operator with pagination support.
         *
         * @param string $operatorUuid The UUID of the operator whose evidence records are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of evidence records per page (default is 100)
         * @param bool $includeConfidential if True, confidential results are included if you have permission to view them
         * @return EvidenceRecord[] An array of EvidenceRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty or if the page or limit parameters are invalid
         */
        public function listOperatorEvidence(string $operatorUuid, int $page=1, int $limit=100, bool $includeConfidential=false): array
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => EvidenceRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/evidence', ['page' => $page, 'limit' => $limit, 'include_confidential' => $includeConfidential], [HttpResponseCode::OK],
                    sprintf('Failed to list evidence records for operator with UUID %s, page %d, limit: %d', $operatorUuid, $page, $limit)
                )
            );
        }

        /**
         * Lists blacklist records for a specific operator with pagination support.
         *
         * @param string $operatorUuid The UUID of the operator whose blacklist records are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of blacklist records per page (default is 100)
         * @param bool $includeLifted If True, lifted blacklist records are included in the result
         * @return BlacklistRecord[] An array of BlacklistRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty or if the page or limit parameters are invalid
         */
        public function listOperatorBlacklist(string $operatorUuid, int $page=1, int $limit=100, bool $includeLifted=false): array
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => BlacklistRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/evidence', ['page' => $page, 'limit' => $limit, 'include_lifted' => $includeLifted], [HttpResponseCode::OK],
                    sprintf('Failed to list operator blacklist records with UUID %s, page %d, limit %d', $operatorUuid, $page, $limit)
                )
            );
        }

        /**
         * Sets the permission for an operator to manage other operators.
         *
         * @param string $operatorUuid The UUID of the operator whose permission is to be set
         * @param bool $manageOperators True to enable the permission, false to disable it
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function setManageOperatorsPermission(string $operatorUuid, bool $manageOperators): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/manage_operators', ['enabled' => $manageOperators], [HttpResponseCode::OK],
                sprintf('Failed to %s the operator\'s permission to manage other operators', ($manageOperators ? 'enable' : 'disable'))
            );
        }

        /**
         * Sets the permission for an operator to manage clients.
         *
         * @param string $operatorUuid The UUID of the operator whose permission is to be set
         * @param bool $isClient True to enable the permission, false to disable it
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function setClientPermission(string $operatorUuid, bool $isClient): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/manage_client', ['enabled' => $isClient], [HttpResponseCode::OK],
                sprintf('Failed to %s the operator\'s client permissions', ($isClient ? 'enable' : 'disable'))
            );
        }

        /**
         * Sets the permission for an operator to manage the blacklist.
         *
         * @param string $operatorUuid The UUID of the operator whose permission is to be set
         * @param bool $manageBlacklist True to enable the permission, false to disable it
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function setManageBlacklistPermission(string $operatorUuid, bool $manageBlacklist): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/manage_blacklist', ['enabled' => $manageBlacklist], [HttpResponseCode::OK],
                sprintf('Failed to %s operator\'s blacklist management permission', ($manageBlacklist ? 'enable' : 'disable'))
            );
        }

        /**
         * Refreshes the authentication token.
         *
         * @param bool $update If true, updates the client's token with the new token (default is true)
         * @return string The new authentication token
         * @throws RequestException If the request fails or the response is invalid
         */
        public function refreshApiKey(bool $update=True): string
        {
            $newToken = $this->makeRequest('POST', 'operators/refresh', null, [HttpResponseCode::OK],
                'Failed to refresh API Key'
            );

            if($update)
            {
                $this->apiKey = $newToken;
            }

            return $this->apiKey;
        }

        /**
         * Refreshes the API key for a specific operator.
         *
         * @param string $operatorUuid The UUID of the operator whose API key is to be refreshed
         * @return string The new API key for the operator
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function refreshOperatorApiKey(string $operatorUuid): string
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            return $this->makeRequest('POST', 'operators/' . $operatorUuid . '/refresh', null,  [HttpResponseCode::OK],
                sprintf('Failed to refresh API Key for operator with UUID %s', $operatorUuid)
            );
        }

        // ENTITY METHODS

        /**
         * Deletes an entity with the given identifier.
         *
         * @param string $entityIdentifier The entity UUID or entity hash to delete
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function deleteEntity(string $entityIdentifier): void
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            $this->makeRequest('DELETE', 'entities/' . $entityIdentifier, null, [HttpResponseCode::OK],
                sprintf('Failed to delete the entity %s', $entityIdentifier)
            );
        }

        /**
         * Retrieves an entity record with the given identifier.
         *
         * @param string $entityIdentifier The entity UUID or entity hash to retrieve
         * @return Entity The retrieved entity object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function getEntityRecord(string $entityIdentifier): Entity
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            return Entity::fromArray($this->makeRequest('GET', 'entities/' . $entityIdentifier, null, [HttpResponseCode::OK],
                sprintf('Failed to get the entity record for %s', $entityIdentifier)
            ));
        }

        /**
         * Lists entities with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of entities per page (default is 100)
         * @return Entity[] An array of Entity objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listEntities(int $page=1, int $limit=100): array
        {
            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => Entity::fromArray($item),
                $this->makeRequest('GET', 'entities', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list entities, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Lists evidence records for a specific entity with pagination support.
         *
         * @param string $entityIdentifier The entity UUID or entity hash whose evidence records are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of evidence records per page (default is 100)
         * @return EvidenceRecord[] An array of EvidenceRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty or if the page or limit parameters are invalid
         */
        public function listEntityAuditLogs(string $entityIdentifier, int $page=1, int $limit=100): array
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => AuditLog::fromArray($item),
                $this->makeRequest('GET', 'entities/' . $entityIdentifier . '/audit', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list audit logs for entity %s, page: %d, limit: %d', $entityIdentifier, $page, $limit)
                )
            );
        }

        /**
         * Lists evidence records for a specific entity with pagination support.
         *
         * @param string $entityIdentifier The entity UUID or entity hash whose evidence records are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of evidence records per page (default is 100)
         * @param bool $includeLifted Optional. If True, lifted records will be included in the results
         * @return EvidenceRecord[] An array of EvidenceRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty or if the page or limit parameters are invalid
         */
        public function listEntityBlacklistRecords(string $entityIdentifier, int $page=1, int $limit=100, bool $includeLifted=false): array
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => BlacklistRecord::fromArray($item),
                $this->makeRequest('GET', 'entities/' . $entityIdentifier . '/blacklist', ['page' => $page, 'limit' => $limit, 'include_lifted' => $includeLifted], [HttpResponseCode::OK],
                    sprintf('Failed to list blacklist records for entity %s, page: %d, limit: %d', $entityIdentifier, $page, $limit)
                )
            );
        }

        /**
         * Lists evidence records for a specific entity with pagination support.
         *
         * @param string $entityIdentifier The entity UUID or entity hash whose evidence records are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of evidence records per page (default is 100)
         * @param bool $includeConfidential Optional. If True, confidential records will be included in the results
         * @return EvidenceRecord[] An array of EvidenceRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty or if the page or limit parameters are invalid
         */
        public function listEntityEvidenceRecords(string $entityIdentifier, int $page=1, int $limit=100, bool $includeConfidential=false): array
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => EvidenceRecord::fromArray($item),
                $this->makeRequest('GET', 'entities/' . $entityIdentifier . '/evidence',
                    ['page' => $page, 'limit' => $limit, 'include_confidential' => $includeConfidential], [HttpResponseCode::OK],
                    sprintf('Failed to list evidence records for entity %s, page: %d, limit: %d', $entityIdentifier, $page, $limit)
                )
            );
        }

        /**
         * Pushes a new entity to the federation network.
         *
         * @param string $domain The domain of the entity to push
         * @param string|null $id Optional. ID of the entity to push if it belongs to the specified domain
         * @return string The UUID of the pushed entity
         * @throws RequestException If the request fails or the response is invalid
         */
        public function pushEntity(string $domain, ?string $id=null): string
        {
            if($id !== null && empty($id))
            {
                throw new InvalidArgumentException('Entity ID cannot be empty');
            }

            if(empty($domain))
            {
                throw new InvalidArgumentException('Domain cannot be an empty string');
            }

            return $this->makeRequest('POST', 'entities', ['domain' => $domain, 'id' => $id], [HttpResponseCode::CREATED, HttpResponseCode::OK],
                sprintf('Failed to push entity with domain %s', $domain)
            );
        }

        /**
         * Queries information about an entity from the federation network.
         *
         * @param string $entityIdentifier The entity UUID or entity hash to query
         * @param bool $includeConfidential if True, confidential results are shown if you have permission to access the records
         * @param bool $includeLifted if True, lifted blacklist results are show  if you have permission to access the records
         * @return EntityQueryResult The result of the entity query
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function queryEntity(string $entityIdentifier, bool $includeConfidential=false, bool $includeLifted=false): EntityQueryResult
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            return EntityQueryResult::fromArray($this->makeRequest('GET', 'entities/' . $entityIdentifier . '/query',
                ['include_confidential' => $includeConfidential, 'include_lifted' => $includeLifted], [HttpResponseCode::OK],
                sprintf('Failed to query entity %s', $entityIdentifier)
            ));
        }

        // EVIDENCE METHODS

        /**
         * Deletes an evidence record with the given UUID.
         *
         * @param string $evidenceUuid The UUID of the evidence record to delete
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID is empty
         */
        public function deleteEvidence(String $evidenceUuid): void
        {
            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            $this->makeRequest('DELETE', 'evidence/' . $evidenceUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete evidence with UUID %s', $evidenceUuid)
            );
        }

        /**
         * Retrieves an evidence record with the given UUID.
         *
         * @param string $evidenceUuid The UUID of the evidence record to retrieve
         * @return EvidenceRecord The retrieved evidence record object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID is empty
         */
        public function getEvidenceRecord(string $evidenceUuid): EvidenceRecord
        {
            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            return EvidenceRecord::fromArray($this->makeRequest('GET', 'evidence/' . $evidenceUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to get evidence record with UUID %s', $evidenceUuid)
            ));
        }

        /**
         * Lists evidence records with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of evidence records per page (default is 100)
         * @param bool $includeConfidential if True, confidential results are included if you have permission to view them
         * @return EvidenceRecord[] An array of EvidenceRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listEvidence(int $page=1, int $limit=100, bool $includeConfidential=false): array
        {
            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            return array_map(
                fn($item) => EvidenceRecord::fromArray($item),
                $this->makeRequest('GET', 'evidence', ['page' => $page, 'limit' => $limit, 'include_confidential' => $includeConfidential], [HttpResponseCode::OK],
                    sprintf('Failed to list evidence records, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Submits new evidence for a specific entity.
         *
         * @param string $entityUuid The UUID of the entity the evidence is associated with
         * @param string|null $textContent Optional. The textual content of the evidence
         * @param string|null $note Optional. An optional note about the evidence
         * @param bool $confidential Optional. If true, the evidence is marked as confidential (default is false)
         * @return string The UUID of the created evidence record
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity UUID is empty
         */
        public function submitEvidence(string $entityUuid, ?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false): string
        {
            if(empty($entityUuid))
            {
                throw new InvalidArgumentException('Entity UUID cannot be empty');
            }

            $parameters = ['entity_uuid' => $entityUuid, 'confidential' => $confidential];

            if($textContent !== null)
            {
                $parameters['text_content'] = $textContent;
            }

            if($note !== null)
            {
                $parameters['note'] = $note;
            }

            if($tag !== null)
            {
                $parameters['tag'] = $tag;
            }

            return $this->makeRequest('POST', 'evidence', $parameters, [HttpResponseCode::CREATED],
                sprintf('Failed to submit evidence for entity with UUID %s', $entityUuid)
            );
        }

        /**
         * Updates the confidentiality flag of an existing Evidence record
         *
         * @param string $evidenceUuid The Evidence UUID record to update
         * @param bool $confidential True if the evidence is confidential, False otherwise
         * @throws RequestException The request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID is empty
         */
        public function updateEvidenceConfidentiality(string $evidenceUuid, bool $confidential): void
        {
            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            $this->makeRequest('POST', 'evidence/' . $evidenceUuid . '/update_confidentiality', ['confidential' => $confidential], [HttpResponseCode::OK],
                sprintf('Failed to %s confidentiality for evidence with UUID %s', ($confidential ? 'set' : 'unset'), $evidenceUuid)
            );
        }

        // ATTACHMENT METHODS

        public function deleteAttachment(string $attachmentUuid): void
        {
            if(empty($attachmentUuid))
            {
                throw new InvalidArgumentException('Attachment UUID cannot be empty');
            }

            $this->makeRequest('DELETE', 'attachments/' . $attachmentUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete attachment with UUID %s', $attachmentUuid)
            );
        }

        public function downloadAttachment(string $attachmentUuid, string $filePath): void
        {
            if(empty($attachmentUuid))
            {
                throw new InvalidArgumentException('Attachment UUID cannot be empty');
            }

            if(empty($filePath))
            {
                throw new InvalidArgumentException('File path cannot be empty');
            }

            $ch = $this->buildCurl('attachments/' . $attachmentUuid);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in output to extract filename
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if($response === false)
            {
                throw new RequestException('Curl error while downloading attachment: ' . $curlError);
            }

            if($httpCode !== HttpResponseCode::OK->value)
            {
                throw new RequestException('Failed to download attachment, HTTP code: ' . $httpCode, HttpResponseCode::from($httpCode));
            }

            // Extract headers and body
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            // Extract filename from Content-Disposition header
            $suggestedFilename = $this->extractFilenameFromHeaders($headers);
            
            // Extract MIME type from Content-Type header
            $mimeType = $this->extractMimeTypeFromHeaders($headers);

            // Determine the final file path
            $finalFilePath = $this->determineFinalFilePath($filePath, $suggestedFilename, $mimeType, $attachmentUuid);

            // Write the file
            $fileHandle = fopen($finalFilePath, 'wb');
            if($fileHandle === false)
            {
                throw new InvalidArgumentException('Failed to open file for writing: ' . $finalFilePath);
            }

            if(fwrite($fileHandle, $body) === false)
            {
                fclose($fileHandle);
                unlink($finalFilePath); // Remove incomplete file
                throw new RequestException('Failed to write attachment data to file: ' . $finalFilePath);
            }

            fclose($fileHandle);
        }

        /**
         * Uploads a file attachment from local disk to the federation server
         *
         * @param string $evidenceUuid The UUID of the evidence record to attach the file to
         * @param string $localFilePath Path to the local file to upload
         * @return UploadResult The upload result containing UUID and download URL
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the file doesn't exist or evidence UUID is invalid
         */
        public function uploadFileAttachment(string $evidenceUuid, string $localFilePath): UploadResult
        {
            if (empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            if (!file_exists($localFilePath))
            {
                throw new InvalidArgumentException('File does not exist: ' . $localFilePath);
            }

            if (!is_readable($localFilePath))
            {
                throw new InvalidArgumentException('File is not readable: ' . $localFilePath);
            }

            $fileSize = filesize($localFilePath);
            if ($fileSize === false || $fileSize === 0)
            {
                throw new InvalidArgumentException('Invalid file or empty file: ' . $localFilePath);
            }

            // Remove leading slash from path
            $path = ltrim('attachments', '/');
            Logger::log()->debug(sprintf("PUT Request to %s for file upload", $this->buildUrl($path)));

            $ch = curl_init($this->buildUrl($path));

            // Set up headers for file upload
            $headers = [
                'Accept: application/json'
            ];

            if ($this->apiKey !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            }

            // Create CURLFile for the upload
            $file = new CURLFile($localFilePath, mime_content_type($localFilePath) ?: 'application/octet-stream', basename($localFilePath));

            // Prepare multipart form data
            $postData = [
                'evidence_uuid' => $evidenceUuid,
                'file' => $file
            ];

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_TIMEOUT => 300, // 5 minutes timeout for large files
                CURLOPT_UPLOAD_BUFFERSIZE => 65536, // 64KB buffer for better performance
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded)
                {
                    if ($upload_size > 0)
                    {
                        $percent = round(($uploaded / $upload_size) * 100, 2);
                        Logger::log()->debug("Upload progress: {$percent}% ({$uploaded}/{$upload_size} bytes)");
                    }
                    return 0; // Continue upload
                }
            ]);

            $response = curl_exec($ch);

            // Handle cURL errors
            if (curl_errno($ch))
            {
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                throw new RequestException('File upload failed: ' . $curlError, HttpResponseCode::from($httpCode));
            }

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $decodedResponse = $this->decodeResponse($response);

            // Check if response code is expected (201 Created for successful upload)
            $expectedStatusCodes = [201, HttpResponseCode::CREATED->value];
            if (!in_array($responseCode, $expectedStatusCodes))
            {
                $errorMsg = 'File upload failed, received response code: ' . $responseCode;
                if ($decodedResponse instanceof ErrorResponse)
                {
                    $errorMsg = 'File upload failed: ' . $decodedResponse->getMessage() . ' (response code: ' . $responseCode . ')';
                }
                throw new RequestException($errorMsg, $responseCode);
            }

            if ($decodedResponse instanceof ErrorResponse)
            {
                throw new RequestException('File upload failed: ' . $decodedResponse->getMessage(), $decodedResponse->getCode());
            }

            /** @var SuccessResponse $decodedResponse */
            return UploadResult::fromArray($decodedResponse->getData());
        }

        /**
         * Uploads a file attachment from a URL to the federation server
         *
         * @param string $evidenceUuid The UUID of the evidence record to attach the file to
         * @param string $fileUrl URL of the file to download and upload
         * @param int $maxFileSize Maximum file size in bytes (default: 50MB)
         * @return UploadResult The upload result containing UUID and download URL
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the URL is invalid or evidence UUID is invalid
         */
        public function uploadFileAttachmentFromUrl(string $evidenceUuid, string $fileUrl, int $maxFileSize = 52428800): UploadResult
        {
            if (empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            if (empty($fileUrl) || !filter_var($fileUrl, FILTER_VALIDATE_URL))
            {
                throw new InvalidArgumentException('Invalid URL provided: ' . $fileUrl);
            }

            if ($maxFileSize <= 0)
            {
                throw new InvalidArgumentException('Maximum file size must be greater than 0');
            }

            Logger::log()->debug(sprintf("Downloading file from URL: %s", $fileUrl));

            // Create a temporary file to store the downloaded content
            $tempFile = tempnam(sys_get_temp_dir(), 'federation_upload_');
            if ($tempFile === false)
            {
                throw new RequestException('Failed to create temporary file for download');
            }

            try
            {
                // Download the file with streaming to handle large files
                $downloadCh = curl_init();
                $fileHandle = fopen($tempFile, 'wb');

                if ($fileHandle === false)
                {
                    throw new RequestException('Failed to open temporary file for writing');
                }

                curl_setopt_array($downloadCh, [
                    CURLOPT_URL => $fileUrl,
                    CURLOPT_FILE => $fileHandle,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => 600, // 10 minutes timeout for download
                    CURLOPT_USERAGENT => 'FederationLib/1.0 File Downloader',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($maxFileSize) {
                        // Check file size limit during download
                        if ($downloaded > $maxFileSize)
                        {
                            Logger::log()->warning("Download exceeded maximum file size limit");
                            return 1; // Abort download
                        }

                        if ($download_size > 0)
                        {
                            $percent = round(($downloaded / $download_size) * 100, 2);
                            Logger::log()->debug("Download progress: {$percent}% ({$downloaded}/{$download_size} bytes)");
                        }
                        return 0; // Continue download
                    }
                ]);

                $downloadResult = curl_exec($downloadCh);
                $downloadHttpCode = curl_getinfo($downloadCh, CURLINFO_RESPONSE_CODE);
                $downloadError = curl_error($downloadCh);

                curl_close($downloadCh);
                fclose($fileHandle);

                if ($downloadResult === false || !empty($downloadError))
                {
                    throw new RequestException('Failed to download file from URL: ' . ($downloadError ?: 'Unknown error'));
                }

                if ($downloadHttpCode !== 200)
                {
                    throw new RequestException('Failed to download file, HTTP response code: ' . $downloadHttpCode);
                }

                // Verify the downloaded file
                $fileSize = filesize($tempFile);
                if ($fileSize === false || $fileSize === 0)
                {
                    throw new RequestException('Downloaded file is empty or invalid');
                }

                if ($fileSize > $maxFileSize) {
                    throw new RequestException('Downloaded file exceeds maximum size limit of ' . $maxFileSize . ' bytes');
                }

                Logger::log()->debug(sprintf("Successfully downloaded file (%d bytes), uploading to server", $fileSize));

                // Get filename from URL or use a default
                $filename = basename(parse_url($fileUrl, PHP_URL_PATH)) ?: 'downloaded_file';

                // Detect MIME type
                $mimeType = 'application/octet-stream';
                if (function_exists('finfo_open'))
                {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $detectedMime = finfo_file($finfo, $tempFile);
                    finfo_close($finfo);
                    if ($detectedMime)
                    {
                        $mimeType = $detectedMime;
                    }
                } elseif (function_exists('mime_content_type'))
                {
                    $detectedMime = mime_content_type($tempFile);
                    if ($detectedMime)
                    {
                        $mimeType = $detectedMime;
                    }
                }

                // Upload the downloaded file
                $path = ltrim('attachments', '/');
                Logger::log()->debug(sprintf("PUT Request to %s for URL-based file upload", $this->buildUrl($path)));
                $ch = curl_init($this->buildUrl($path));

                // Set up headers for file upload
                $headers = [
                    'Accept: application/json'
                ];

                if ($this->apiKey !== null)
                {
                    $headers[] = 'Authorization: Bearer ' . $this->apiKey;
                }

                // Create CURLFile for the upload
                $file = new CURLFile($tempFile, $mimeType, $filename);

                // Prepare multipart form data
                $postData = [
                    'evidence_uuid' => $evidenceUuid,
                    'file' => $file
                ];

                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_TIMEOUT => 300, // 5 minutes timeout for upload
                    CURLOPT_UPLOAD_BUFFERSIZE => 65536, // 64KB buffer for better performance
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded)
                    {
                        if ($upload_size > 0)
                        {
                            $percent = round(($uploaded / $upload_size) * 100, 2);
                            Logger::log()->debug("Upload progress: {$percent}% ({$uploaded}/{$upload_size} bytes)");
                        }

                        return 0; // Continue upload
                    }
                ]);

                $response = curl_exec($ch);

                // Handle cURL errors
                if (curl_errno($ch))
                {
                    $curlError = curl_error($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    curl_close($ch);
                    throw new RequestException('File upload failed: ' . $curlError, HttpResponseCode::from($httpCode));
                }

                $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                $decodedResponse = $this->decodeResponse($response);

                // Check if response code is expected (201 Created for successful upload)
                $expectedStatusCodes = [201, HttpResponseCode::CREATED->value];
                if (!in_array($responseCode, $expectedStatusCodes))
                {
                    $errorMsg = 'File upload failed, received response code: ' . $responseCode;
                    if ($decodedResponse instanceof ErrorResponse)
                    {
                        $errorMsg = 'File upload failed: ' . $decodedResponse->getMessage() . ' (response code: ' . $responseCode . ')';
                    }

                    throw new RequestException($errorMsg, $responseCode);
                }

                if ($decodedResponse instanceof ErrorResponse)
                {
                    throw new RequestException('File upload failed: ' . $decodedResponse->getMessage(), $decodedResponse->getCode());
                }

                /** @var SuccessResponse $decodedResponse */
                return UploadResult::fromArray($decodedResponse->getData());

            }
            finally
            {
                // Clean up temporary file
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }
        }

        /**
         * Extract filename from Content-Disposition header
         *
         * @param string $headers The HTTP headers
         * @return string|null The extracted filename or null if not found
         */
        private function extractFilenameFromHeaders(string $headers): ?string
        {
            if(preg_match('/Content-Disposition:.*filename="([^"]+)"/i', $headers, $matches))
            {
                return $matches[1];
            }

            // Try without quotes
            if(preg_match('/Content-Disposition:.*filename=([^\s;]+)/i', $headers, $matches))
            {
                return trim($matches[1]);
            }

            return null;
        }

        /**
         * Extract MIME type from Content-Type header
         *
         * @param string $headers The HTTP headers
         * @return string|null The extracted MIME type or null if not found
         */
        private function extractMimeTypeFromHeaders(string $headers): ?string
        {
            if(preg_match('/Content-Type:\s*([^\s;]+)/i', $headers, $matches))
            {
                return trim($matches[1]);
            }

            return null;
        }

        /**
         * Determine the final file path based on user input and server suggestions
         *
         * @param string $userFilePath The path provided by the user
         * @param string|null $suggestedFilename The filename suggested by the server
         * @param string|null $mimeType The MIME type from server
         * @param string $attachmentUuid The attachment UUID as fallback
         * @return string The final file path to use
         */
        private function determineFinalFilePath(string $userFilePath, ?string $suggestedFilename, ?string $mimeType, string $attachmentUuid): string
        {
            // If user provided a full file path (has extension or doesn't end with separator), use it as-is
            if(pathinfo($userFilePath, PATHINFO_EXTENSION) !== '' || !str_ends_with($userFilePath, DIRECTORY_SEPARATOR))
            {
                return $userFilePath;
            }

            // User provided a directory path, so we need to determine the filename
            if($suggestedFilename !== null)
            {
                // Use the server's suggested filename
                $filename = $suggestedFilename;
            }
            elseif($mimeType !== null)
            {
                // Generate filename based on MIME type
                $extension = Classes\Utilities::getExtensionFromMimeType($mimeType);
                $filename = $attachmentUuid . $extension;
            }
            else
            {
                // Last resort: use attachment UUID as filename
                $filename = $attachmentUuid;
            }

            return rtrim($userFilePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        }


        /**
         * Decodes the given raw JSON input and decodes it into a SuccessResponse or a ErrorResponse depending on the
         * `success` variable of the response, in both objects they can be referenced as ResponseInterface.
         *
         * @param string $response The raw JSON response from the server
         * @return ResponseInterface The decoded response object, either SuccessResponse or ErrorResponse
         * @throws RequestException If the response cannot be decoded or if the response indicates an error
         */
        private function decodeResponse(string $response): ResponseInterface
        {
            $decoded = json_decode($response, true);
            if(json_last_error() !== JSON_ERROR_NONE)
            {
                throw new RequestException('Failed to decode response: ' . json_last_error_msg() . "\n\n" . $response);
            }

            if(!isset($decoded['success']))
            {
                throw new RequestException('Request failed: got unknown response from server; ' . $response, HttpResponseCode::from($decoded['code'] ?? HttpResponseCode::INTERNAL_SERVER_ERROR));
            }

            if($decoded['success'] === true)
            {
                return SuccessResponse::fromArray($decoded);
            }
            else
            {
                return ErrorResponse::fromArray($decoded);
            }
        }

        /**
         * Builds a curl client to the given path, used to minimize boilerplate code.
         *
         * @param string $path The path to make the request to
         * @return CurlHandle The constructed CurlHandle
         */
        private function buildCurl(string $path): CurlHandle
        {
            $ch = curl_init($this->buildUrl($path));

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json'
            ];

            if($this->apiKey !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            return $ch;
        }

        /**
         * Builds the full URL for the given path by appending it to the endpoint.
         *
         * @param string $path The API endpoint path
         * @return string The full URL
         */
        private function buildUrl(string $path): string
        {
            return $this->endpoint . '/' . $path;
        }
    }

