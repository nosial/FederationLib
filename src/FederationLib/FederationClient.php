<?php

    namespace FederationLib;

    use CURLFile;
    use CurlHandle;
    use FederationLib\Classes\Logger;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ReportRecord;
    use FederationLib\Objects\ReportSubmission;
    use FederationLib\Objects\SearchResult;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ServerInformation;
    use FederationLib\Objects\UploadResult;
    use InvalidArgumentException;
    use RuntimeException;

    class FederationClient
    {
        private string $endpoint;
        private ?string $accessToken;

        /**
         * Constructor for FederationClient
         *
         * @param string $endpoint The endpoint URL for the federation server
         * @param string|null $accessToken Optional token for authentication
         * @throws InvalidArgumentException If the endpoint is not a valid URL or if the token is an empty string
         */
        public function __construct(string $endpoint, ?string $accessToken=null)
        {
            $parsedUrl = parse_url($endpoint);
            if(empty($endpoint) || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host']))
            {
                throw new InvalidArgumentException("Endpoint must be a valid URL");
            }

            $endpoint = rtrim($endpoint, '/');
            $this->endpoint = $endpoint;
            $this->setAccessToken($accessToken);
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
        public function getAccessToken(): ?string
        {
            return $this->accessToken;
        }

        /**
         * Set the authentication token
         *
         * @param string|null $accessToken The authentication token to set, or null to unset the token
         * @throws InvalidArgumentException If the token is an empty string or contains whitespace
         */
        public function setAccessToken(?string $accessToken): void
        {
            if($accessToken !== null)
            {
                if(strlen($accessToken) === 0)
                {
                    throw new InvalidArgumentException("Token cannot be an empty string");
                }

                // Check for empty whitespace in the access-token
                if (preg_match('/\s/', $accessToken))
                {
                    throw new InvalidArgumentException("Token cannot contain whitespace");
                }
            }

            $this->accessToken = $accessToken;
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

                case 'PATCH':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
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
                throw new RequestException($errorMessage . ': ' . $curlError, HttpResponseCode::from($httpCode));
            }

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

            // Check the response Content-Type and ensure it's application/json
            if (!str_contains($contentType, 'application/json'))
            {
                $responseLen = strlen($response);
                $responsePreview = $responseLen > 200 ? substr($response, 0, 200) . '...' : $response;
                $errorMsg = $errorMessage . ': Expected JSON response but received Content-Type: ' . ($contentType ?: 'none') 
                    . sprintf('. Response length: %d bytes. Response preview: [%s]', $responseLen, bin2hex($responsePreview));
                throw new RequestException($errorMsg, $responseCode);
            }

            $decodedResponse = $this->decodeResponse($response);

            // Check if response code is expected
            if (!in_array($responseCode, $expectedStatusCodes))
            {
                $errorMsg = $errorMessage . ' received response code: ' . $responseCode;
                if (is_array($decodedResponse) && isset($decodedResponse['message']))
                {
                    $errorMsg = $errorMessage . ', ' . $decodedResponse['message'] . ' received response code: ' . $responseCode;
                }
                throw new RequestException($errorMsg, $responseCode);
            }

            return $decodedResponse;
        }

        /**
         * Scans the given text content and attempts to identify entities within the text content such as URLs
         * email addresses, ip addresses, etc.
         *
         * @param string $content The text content to scan
         * @param string|null $author Optional author identifier (UUID, SHA-256, or entity address)
         * @param int|null $topK Optional. Number of top classifications to return
         * @param float|null $threshold Optional. Confidence threshold for classification
         * @param array|null $metadata Optional. Metadata to associate with the evidence record
         * @return ScannedContent The scanned content result
         * @throws RequestException Thrown if the request fails
         */
        public function scanContent(string $content, ?string $author = null, ?int $topK = null, ?float $threshold = null, ?array $metadata = null): ScannedContent
        {
            if(empty($content))
            {
                throw new InvalidArgumentException('Content cannot be empty');
            }

            $params = ['content' => $content];
            if($author !== null)
            {
                $params['author'] = $author;
            }

            if($topK !== null)
            {
                $params['top_k'] = $topK;
            }

            if($threshold !== null)
            {
                $params['threshold'] = $threshold;
            }

            if($metadata !== null)
            {
                $params['metadata'] = $metadata;
            }

            return ScannedContent::fromArray($this->makeRequest('POST', 'scan', $params, [HttpResponseCode::OK],
                'Failed to scan content'
            ));
        }

        /**
         * Lists audit logs with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of audit logs per page (default is 100)
         * @return AuditLog[] An array of AuditLog objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listAuditLogs(int $page=1, int $limit=100): array
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
                fn($item) => AuditLog::fromArray($item),
                $this->makeRequest('GET', '', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list audit logs, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Retrieves an audit log record with the given UUID.
         *
         * @param string $auditLogUuid The UUID of the audit log to retrieve
         * @return AuditLog The retrieved AuditLog object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the audit log UUID is empty
         */
        public function getAuditLogRecord(string $auditLogUuid): AuditLog
        {
            if(empty($auditLogUuid))
            {
                throw new InvalidArgumentException('Audit log UUID cannot be empty');
            }

            return AuditLog::fromArray($this->makeRequest('GET', 'audit/' . $auditLogUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to get audit log record for UUID %s', $auditLogUuid)
            ));
        }

        /**
         * Searches audit logs by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return AuditLog[] An array of matching AuditLog objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchAuditLogs(string $query, int $page=1, int $limit=10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                $this->makeRequest('GET', 'audit/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search audit logs, query: %s', $query)
                )
            );
        }

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
         * Searches across multiple resource types simultaneously.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param string[]|null $types Optional array of RecordType values to restrict search to specific types
         * @param int $page The page number (default 1)
         * @param int $limit The number of results per type (default 10)
         * @return SearchResult[] An array of SearchResult objects containing typed records
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function search(string $query, ?array $types=null, int $page=1, int $limit=10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than 0');
            }

            $params = ['q' => $query, 'page' => $page, 'limit' => $limit];
            if(!empty($types))
            {
                $params['type'] = implode(',', $types);
            }

            $results = $this->makeRequest('GET', 'search', $params, [HttpResponseCode::OK],
                sprintf('Failed to search, query: %s', $query)
            );

            return array_map(function(array $item): SearchResult
            {
                $recordType = RecordType::from($item['type']);
                $record = match($recordType)
                {
                    RecordType::ENTITY => EntityRecord::fromArray($item['record']),
                    RecordType::EVIDENCE => EvidenceRecord::fromArray($item['record']),
                    RecordType::BLACKLIST => BlacklistRecord::fromArray($item['record']),
                    RecordType::REPORT => ReportRecord::fromArray($item['record']),
                    RecordType::ATTACHMENT => FileAttachmentRecord::fromArray($item['record']),
                    RecordType::AUDIT_LOG => AuditLog::fromArray($item['record']),
                    RecordType::OPERATOR => OperatorRecord::fromArray($item['record']),
                };
                return new SearchResult($recordType, $record);
            }, $results);
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

            $this->makeRequest('PATCH', 'operators/' . $operatorUuid . '/disable', null, [HttpResponseCode::OK],
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

            $this->makeRequest('PATCH', 'operators/' . $operatorUuid . '/enable', null, [HttpResponseCode::OK],
                sprintf('Failed to enable operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Retrieves the operator with the given UUID.
         *
         * @param string $operatorUuid The UUID of the operator to retrieve
         * @return OperatorRecord The retrieved operator object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function getOperator(string $operatorUuid): OperatorRecord
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            return OperatorRecord::fromArray($this->makeRequest('GET', 'operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                'Failed to get operator'
            ));
        }

        /**
         * Retrieves the operator associated with the current authentication token.
         *
         * @return OperatorRecord The retrieved operator object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the authentication token is not set
         */
        public function getSelf(): OperatorRecord
        {
            return OperatorRecord::fromArray($this->makeRequest('GET', 'operators/self', null, [HttpResponseCode::OK],
                'Failed to get self operator'
            ));
        }

        /**
         * Lists operators with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of operators per page (default is 100)
         * @return OperatorRecord[] An array of Operator objects
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

            return
                array_map(
                fn($item) => OperatorRecord::fromArray($item),
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
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/blacklist', ['page' => $page, 'limit' => $limit, 'include_lifted' => $includeLifted], [HttpResponseCode::OK],
                    sprintf('Failed to list operator blacklist records with UUID %s, page %d, limit %d', $operatorUuid, $page, $limit)
                )
            );
        }

        /**
         * Sets the operator permissions for an operator.
         *
         * @param string $operatorUuid The UUID of the operator whose permission is to be set
         * @param bool $hasOperatorPermissions True to enable the permission, false to disable it
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function setOperatorPermissions(string $operatorUuid, bool $hasOperatorPermissions): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'operators/' . $operatorUuid . '/operator_permissions', ['enabled' => $hasOperatorPermissions], [HttpResponseCode::OK],
                sprintf('Failed to %s the operator\'s operator permissions', ($hasOperatorPermissions ? 'enable' : 'disable'))
            );
        }

        /**
         * Sets the client permissions for an operator.
         *
         * @param string $operatorUuid The UUID of the operator whose permission is to be set
         * @param bool $hasClientPermissions True to enable the permission, false to disable it
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function setClientPermissions(string $operatorUuid, bool $hasClientPermissions): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'operators/' . $operatorUuid . '/client_permissions', ['enabled' => $hasClientPermissions], [HttpResponseCode::OK],
                sprintf('Failed to %s the operator\'s client permissions', ($hasClientPermissions ? 'enable' : 'disable'))
            );
        }

        /**
         * Sets the management permissions for an operator.
         *
         * @param string $operatorUuid The UUID of the operator whose permission is to be set
         * @param bool $hasManagementPermissions True to enable the permission, false to disable it
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function setManagementPermissions(string $operatorUuid, bool $hasManagementPermissions): void
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'operators/' . $operatorUuid . '/management_permissions', ['enabled' => $hasManagementPermissions], [HttpResponseCode::OK],
                sprintf('Failed to %s operator\'s management permission', ($hasManagementPermissions ? 'enable' : 'disable'))
            );
        }

        /**
         * Generates a new authentication token for the current operator.
         *
         * @param bool $update If true, updates the client's token with the new token (default is true)
         * @return string The new authentication token
         * @throws RequestException If the request fails or the response is invalid
         */
        public function generateAccessToken(bool $update=True): string
        {
            $newToken = $this->makeRequest('POST', 'operators/refresh', null, [HttpResponseCode::OK],
                'Failed to generate Access token'
            );

            if($update)
            {
                $this->accessToken = $newToken;
            }

            return $newToken;
        }

        /**
         * Generates a new Access Token for a specific operator.
         *
         * @param string $operatorUuid The UUID of the operator whose Access Token is to be generated
         * @return string The new Access Token for the operator
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty
         */
        public function generateOperatorAccessToken(string $operatorUuid): string
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            return $this->makeRequest('POST', 'operators/' . $operatorUuid . '/refresh', null,  [HttpResponseCode::OK],
                sprintf('Failed to generate Access token for operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Searches operators by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return OperatorRecord[] An array of matching OperatorRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchOperators(string $query, int $page = 1, int $limit = 10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                fn($item) => OperatorRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search operators, query: %s', $query)
                )
            );
        }

        // ENTITY METHODS

        /**
         * Deletes an entity with the given identifier.
         *
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) to delete
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
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) to retrieve
         * @return EntityRecord The retrieved entity object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function getEntityRecord(string $entityIdentifier): EntityRecord
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            return EntityRecord::fromArray($this->makeRequest('GET', 'entities/' . $entityIdentifier, null, [HttpResponseCode::OK],
                sprintf('Failed to get the entity record for %s', $entityIdentifier)
            ));
        }

        /**
         * Lists entities with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of entities per page (default is 100)
         * @return EntityRecord[] An array of Entity objects
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
                fn($item) => EntityRecord::fromArray($item),
                $this->makeRequest('GET', 'entities', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list entities, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Lists evidence records for a specific entity with pagination support.
         *
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) whose evidence records are to be retrieved
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
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) whose evidence records are to be retrieved
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
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) whose evidence records are to be retrieved
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
         * @param string $host The host/domain of the entity to push
         * @param string|null $id Optional. ID of the entity to push if it belongs to the specified domain
         * @param array|null $metadata Optional. Metadata to associate with the entity
         * @return string The UUID of the pushed entity
         * @throws RequestException If the request fails or the response is invalid
         */
        public function pushEntity(string $host, ?string $id=null, ?array $metadata=null): string
        {
            if($id !== null && empty($id))
            {
                throw new InvalidArgumentException('Entity ID cannot be empty');
            }

            if(empty($host))
            {
                throw new InvalidArgumentException('Host cannot be an empty string');
            }

            $params = ['host' => $host, 'id' => $id];
            if($metadata !== null)
            {
                $params['metadata'] = $metadata;
            }

            return $this->makeRequest('POST', 'entities', $params, [HttpResponseCode::CREATED, HttpResponseCode::OK],
                sprintf('Failed to push entity with domain %s', $host)
            );
        }

        /**
         * Queries an entity with the given UUID, including evidence and blacklist records.
         *
         * @param string $entityUuid The UUID of the entity to query
         * @param bool $includeConfidential Whether to include confidential evidence records
         * @param bool $includeLifted Whether to include lifted blacklist records
         * @return mixed The query result containing entity record, evidence, and blacklist data
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity UUID is empty
         */
        public function queryEntity(string $entityUuid, bool $includeConfidential = false, bool $includeLifted = false): mixed
        {
            if(empty($entityUuid))
            {
                throw new InvalidArgumentException('Entity UUID cannot be empty');
            }

            return $this->makeRequest('GET', 'entities/' . $entityUuid . '/query',
                ['include_confidential' => $includeConfidential, 'include_lifted' => $includeLifted],
                [HttpResponseCode::OK],
                sprintf('Failed to query entity %s', $entityUuid)
            );
        }

        /**
         * Clears the reputation of the given entity.
         *
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) whose reputation to clear
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function clearEntityReputation(string $entityIdentifier): void
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            $this->makeRequest('PATCH', 'entities/' . $entityIdentifier . '/clearReputation', null, [HttpResponseCode::OK],
                sprintf('Failed to clear reputation for entity %s', $entityIdentifier)
            );
        }

        /**
         * Sets a relationship between an entity and a target entity.
         *
         * @param string $entityIdentifier The entity UUID, hash, or address to set the relationship for
         * @param string $targetEntityUuid The UUID of the target entity (the parent entity in the relationship)
         * @param EntityRelationshipType $relationshipType The type of relationship to set
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function setEntityRelationship(string $entityIdentifier, string $targetEntityUuid, EntityRelationshipType $relationshipType): void
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            if(empty($targetEntityUuid))
            {
                throw new InvalidArgumentException('Target entity UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'entities/' . $entityIdentifier . '/relationship',
                ['target_entity_uuid' => $targetEntityUuid, 'relationship_type' => $relationshipType->value], [HttpResponseCode::OK],
                sprintf('Failed to set relationship for entity %s', $entityIdentifier)
            );
        }

        /**
         * Clears the relationship for an entity.
         *
         * @param string $entityIdentifier The entity UUID, hash, or address to clear the relationship for
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function clearEntityRelationship(string $entityIdentifier): void
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            $this->makeRequest('DELETE', 'entities/' . $entityIdentifier . '/relationship', null, [HttpResponseCode::OK],
                sprintf('Failed to clear relationship for entity %s', $entityIdentifier)
            );
        }

        /**
         * Searches entities by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return EntityRecord[] An array of matching EntityRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchEntities(string $query, int $page = 1, int $limit = 10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                fn($item) => EntityRecord::fromArray($item),
                $this->makeRequest('GET', 'entities/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search entities, query: %s', $query)
                )
            );
        }

        // EVIDENCE METHODS

        /**
         * Deletes an evidence record with the given UUID.
         *
         * @param string $evidenceUuid The UUID of the evidence record to delete
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID is empty
         */
        public function deleteEvidence(string $evidenceUuid): void
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
         * Retrieves attachments associated with a specific evidence record.
         *
         * @param string $evidenceUuid The UUID of the evidence record whose attachments are to be retrieved
         * @return FileAttachmentRecord[] An array of EvidenceAttachment objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID is empty
         */
        public function getEvidenceAttachments(string $evidenceUuid): array
        {
            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            return array_map(
                fn($item) => FileAttachmentRecord::fromArray($item),
                $this->makeRequest('GET', 'evidence/' . $evidenceUuid . '/attachments', null, [HttpResponseCode::OK],
                    sprintf('Failed to get evidence attachments for evidence with UUID %s', $evidenceUuid)
                )
            );
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
         * @param string $entityIdentifier The entity UUID, entity hash, or entity address (email) to submit evidence for
         * @param string|null $textContent Optional. The textual content of the evidence
         * @param string|null $note Optional. An optional note about the evidence
         * @param bool $confidential Optional. If true, the evidence is marked as confidential (default is false)
         * @return string The UUID of the created evidence record
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty
         */
        public function submitEvidence(string $entityIdentifier, ?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false, ?array $metadata=null): string
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('Entity identifier cannot be empty');
            }

            $parameters = ['entity_identifier' => $entityIdentifier, 'confidential' => $confidential];

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

            if($metadata !== null)
            {
                $parameters['metadata'] = $metadata;
            }

            return $this->makeRequest('POST', 'evidence', $parameters, [HttpResponseCode::CREATED],
                sprintf('Failed to submit evidence for entity %s', $entityIdentifier)
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

            $this->makeRequest('PATCH', 'evidence/' . $evidenceUuid . '/update_confidentiality', ['confidential' => $confidential], [HttpResponseCode::OK],
                sprintf('Failed to %s confidentiality for evidence with UUID %s', ($confidential ? 'set' : 'unset'), $evidenceUuid)
            );
        }

        /**
         * Updates the tag of an existing evidence record.
         *
         * @param string $evidenceUuid The UUID of the evidence record to update
         * @param string $tag The new tag to set
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID is empty
         */
        public function updateEvidenceTag(string $evidenceUuid, string $tag): void
        {
            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            if(empty($tag))
            {
                throw new InvalidArgumentException('Tag cannot be empty');
            }

            $this->makeRequest('PATCH', 'evidence/' . $evidenceUuid . '/update_tag', ['tag' => $tag], [HttpResponseCode::OK],
                sprintf('Failed to update tag for evidence with UUID %s', $evidenceUuid)
            );
        }

        /**
         * Searches evidence by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return EvidenceRecord[] An array of matching EvidenceRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchEvidence(string $query, int $page = 1, int $limit = 10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                $this->makeRequest('GET', 'evidence/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search evidence, query: %s', $query)
                )
            );
        }

        /**
         * Links an existing evidence record to a report.
         *
         * @param string $evidenceUuid The UUID of the evidence record to link
         * @param string $reportUuid The UUID of the report to link the evidence to
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence or report UUID is empty
         */
        public function addEvidenceToReport(string $evidenceUuid, string $reportUuid): void
        {
            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            if(empty($reportUuid))
            {
                throw new InvalidArgumentException('Report UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'evidence/' . $evidenceUuid . '/link_report', ['report_uuid' => $reportUuid], [HttpResponseCode::OK],
                sprintf('Failed to link evidence %s to report %s', $evidenceUuid, $reportUuid)
            );
        }

        /**
         * Deletes a report record by its UUID.
         *
         * @param string $reportUuid The UUID of the report record to delete
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the report UUID is empty
         */
        public function deleteReport(string $reportUuid): void
        {
            if(empty($reportUuid))
            {
                throw new InvalidArgumentException('Report UUID cannot be empty');
            }

            $this->makeRequest('DELETE', 'reports/' . $reportUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete report %s', $reportUuid)
            );
        }

        /**
         * Submits a new report.
         *
         * @param string $reportingEntity The UUID, SHA-256 hash, or entity address of the entity being reported
         * @param string $content The content/message of the report
         * @param IncidentType $incidentType The type of incident being reported
         * @param string|null $reportMessage Optional message for the report
         * @param string|null $evidenceTag Optional tag for the evidence
         * @return ReportSubmission The created report submission
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If required parameters are invalid
         */
        public function submitReport(string $reportingEntity, string $content, IncidentType $incidentType, ?string $reportMessage = null, ?string $evidenceTag = null): ReportSubmission
        {
            if(empty($reportingEntity))
            {
                throw new InvalidArgumentException('Reporting entity identifier cannot be empty');
            }

            if(empty($content))
            {
                throw new InvalidArgumentException('Content cannot be empty');
            }

            $params = [
                'reporting_entity' => $reportingEntity,
                'content' => $content,
                'incident_type' => $incidentType->value,
            ];

            if($reportMessage !== null)
            {
                $params['report_message'] = $reportMessage;
            }

            if($evidenceTag !== null)
            {
                $params['evidence_tag'] = $evidenceTag;
            }

            return ReportSubmission::fromArray($this->makeRequest('POST', 'reports', $params, [HttpResponseCode::OK],
                'Failed to submit report'
            ));
        }

        /**
         * Lists reports with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of reports per page (default is 100)
         * @return ReportRecord[] An array of ReportRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listReports(int $page = 1, int $limit = 100): array
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
                fn($item) => ReportRecord::fromArray($item),
                $this->makeRequest('GET', 'reports', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list reports, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Retrieves a report by its UUID.
         *
         * @param string $reportUuid The UUID of the report to retrieve
         * @return ReportRecord The retrieved report record
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the report UUID is empty
         */
        public function getReport(string $reportUuid): ReportRecord
        {
            if(empty($reportUuid))
            {
                throw new InvalidArgumentException('Report UUID cannot be empty');
            }

            return ReportRecord::fromArray($this->makeRequest('GET', 'reports/' . $reportUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to get report %s', $reportUuid)
            ));
        }

        /**
         * Closes a report by its UUID.
         *
         * @param string $reportUuid The UUID of the report to close
         * @param ClassificationFlag|null $classification Optional. The classification flag to identify the content as
         * @throws RequestException If the request fails or the response is invalid
         */
        public function closeReport(string $reportUuid, ?ClassificationFlag $classification=null): void
        {
            if(empty($reportUuid))
            {
                throw new InvalidArgumentException('Report UUID cannot be empty');
            }

            $params = [];
            if($classification !== null)
            {
                // Send both parameter names for compatibility with server builds that
                // read either 'classification' or 'classification_flag'.
                $params['classification_flag'] = $classification->value;
                $params['classification'] = $classification->value;
            }
            $this->makeRequest('PATCH', 'reports/' . $reportUuid . '/close', $params, [HttpResponseCode::OK],
                sprintf('Failed to close report %s', $reportUuid)
            );
        }

        /**
         * Assigns an operator to a report.
         *
         * @param string $reportUuid The UUID of the report to assign
         * @param string $operatorUuid The UUID of the operator to assign
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the report or operator UUID is empty
         */
        public function assignOperatorToReport(string $reportUuid, string $operatorUuid): void
        {
            if(empty($reportUuid))
            {
                throw new InvalidArgumentException('Report UUID cannot be empty');
            }

            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'reports/' . $reportUuid . '/assign', ['operator' => $operatorUuid], [HttpResponseCode::OK],
                sprintf('Failed to assign operator %s to report %s', $operatorUuid, $reportUuid)
            );
        }

        /**
         * Lists reports for a specific operator with pagination support.
         *
         * @param string $operatorUuid The UUID of the operator whose reports are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of reports per page (default is 100)
         * @return ReportRecord[] An array of ReportRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty or page/limit are invalid
         */
        public function listOperatorReports(string $operatorUuid, int $page = 1, int $limit = 100): array
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
                fn($item) => ReportRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/reports', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list reports for operator %s, page: %d, limit: %d', $operatorUuid, $page, $limit)
                )
            );
        }

        /**
         * Lists reports for a specific entity with pagination support.
         *
         * @param string $entityIdentifier The entity UUID, hash, or address whose reports are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of reports per page (default is 100)
         * @return ReportRecord[] An array of ReportRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the entity identifier is empty or page/limit are invalid
         */
        public function listEntityReports(string $entityIdentifier, int $page = 1, int $limit = 100): array
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
                fn($item) => ReportRecord::fromArray($item),
                $this->makeRequest('GET', 'entities/' . $entityIdentifier . '/reports', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list reports for entity %s, page: %d, limit: %d', $entityIdentifier, $page, $limit)
                )
            );
        }

        /**
         * Lists assigned reports for a specific operator with pagination support.
         *
         * @param string $operatorUuid The UUID of the operator whose assigned reports are to be retrieved
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of reports per page (default is 100)
         * @return ReportRecord[] An array of ReportRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the operator UUID is empty or page/limit are invalid
         */
        public function listAssignedOperatorReports(string $operatorUuid, int $page = 1, int $limit = 100): array
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
                fn($item) => ReportRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/reports/assigned', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list assigned reports for operator %s, page: %d, limit: %d', $operatorUuid, $page, $limit)
                )
            );
        }

        /**
         * Searches reports by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return ReportRecord[] An array of matching ReportRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchReports(string $query, int $page = 1, int $limit = 10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                fn($item) => ReportRecord::fromArray($item),
                $this->makeRequest('GET', 'reports/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search reports, query: %s', $query)
                )
            );
        }

        /**
         * Retrieves the OpenAPI specification from the server.
         *
         * @return array The OpenAPI specification as an associative array
         * @throws RequestException If the request fails or the response is invalid
         */
        public function getSpecification(): array
        {
            return $this->makeRequest('GET', 'specification', null, [HttpResponseCode::OK],
                'Failed to get specification'
            );
        }

        // BLACKLIST METHODS

        /**
         * Blacklists an entity with the given identifier using specified evidence and type.
         *
         * @param string $entityIdentifier The UUID, hash, or entity address (email) of the entity to blacklist
         * @param string $evidenceUuid The UUID of the evidence record supporting the blacklist action
         * @param IncidentType $type The type of blacklist action (e.g., SPAM, MALWARE)
         * @param int|null $expires Optional. Expiration time in seconds for the blacklist entry (null for permanent)
         * @return string The UUID of the created blacklist record
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If any of the parameters are invalid
         */
        public function blacklistEntity(string $entityIdentifier, string $evidenceUuid, IncidentType $type, ?int $expires=null): string
        {
            if(empty($entityIdentifier))
            {
                throw new InvalidArgumentException('The entity identifier must not be empty');
            }

            if(empty($evidenceUuid))
            {
                throw new InvalidArgumentException('The evidence UUID must not be empty');
            }

            if($expires !== null && $expires < 0)
            {
                throw new InvalidArgumentException('The expires parameter must be a positive integer or null');
            }

            return $this->makeRequest('POST', 'blacklist', [
                'entity_identifier' => $entityIdentifier,
                'evidence_uuid' => $evidenceUuid,
                'type' => $type->value,
                'expires' => $expires
            ], [HttpResponseCode::CREATED], sprintf('Failed to blacklist entity %s', $entityIdentifier));
        }

        /**
         * Deletes a blacklist record with the given UUID.
         *
         * @param string $blacklistRecordUuid The UUID of the blacklist record to delete
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the blacklist record UUID is empty
         */
        public function deleteBlacklistRecord(string $blacklistRecordUuid): void
        {
            if(empty($blacklistRecordUuid))
            {
                throw new InvalidArgumentException('Blacklist record UUID cannot be empty');
            }

            $this->makeRequest('DELETE', 'blacklist/' . $blacklistRecordUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete blacklist record with UUID %s', $blacklistRecordUuid)
            );
        }

        /**
         * Retrieves a blacklist record with the given UUID.
         *
         * @param string $blacklistRecordUuid The UUID of the blacklist record to retrieve
         * @return BlacklistRecord The retrieved blacklist record object
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the blacklist record UUID is empty
         */
        public function getBlacklistRecord(string $blacklistRecordUuid): BlacklistRecord
        {
            if(empty($blacklistRecordUuid))
            {
                throw new InvalidArgumentException('Blacklist record UUID cannot be empty');
            }

            return BlacklistRecord::fromArray($this->makeRequest('GET', 'blacklist/' . $blacklistRecordUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to get blacklist record with UUID %s', $blacklistRecordUuid)
            ));
        }

        /**
         * Lifts (removes) a blacklist record with the given UUID.
         *
         * @param string $blacklistRecordUuid The UUID of the blacklist record to lift
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the blacklist record UUID is empty
         */
        public function liftBlacklistRecord(string $blacklistRecordUuid): void
        {
            if(empty($blacklistRecordUuid))
            {
                throw new InvalidArgumentException('Blacklist record UUID cannot be empty');
            }

            $this->makeRequest('PATCH', 'blacklist/' . $blacklistRecordUuid . '/lift', null, [HttpResponseCode::OK],
                sprintf('Failed to lift blacklist record with UUID %s', $blacklistRecordUuid)
            );
        }

        /**
         * Lists blacklist records with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of blacklist records per page (default is 100)
         * @param bool $includeLifted if True, lifted blacklist records are included if you have permission to view them
         * @return BlacklistRecord[] An array of BlacklistRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listBlacklistRecords(int $page=1, int $limit=100, bool $includeLifted=false): array
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
                fn($item) => BlacklistRecord::fromArray($item),
                $this->makeRequest('GET', 'blacklist', ['page' => $page, 'limit' => $limit, 'include_lifted' => $includeLifted], [HttpResponseCode::OK],
                    sprintf('Failed to list blacklist records, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Searches blacklist records by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return BlacklistRecord[] An array of matching BlacklistRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchBlacklist(string $query, int $page = 1, int $limit = 10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                $this->makeRequest('GET', 'blacklist/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search blacklist records, query: %s', $query)
                )
            );
        }

        // ATTACHMENT METHODS

        /**
         * Returns the FileAttachmentRecord of an existing attachment without downloading the file
         *
         * @param string $attachmentUuid The Attachment UUID to query
         * @return FileAttachmentRecord The FileAttachmentRecord object
         * @throws RequestException The request fails or the response is invalid
         * @throws InvalidArgumentException If the attachment UUID is empty
         */
        public function getAttachmentInfo(string $attachmentUuid): FileAttachmentRecord
        {
            if(empty($attachmentUuid))
            {
                throw new InvalidArgumentException('Attachment UUID cannot be empty');
            }

            return FileAttachmentRecord::fromArray($this->makeRequest('GET', 'attachments/' . $attachmentUuid . '/info', null, [HttpResponseCode::OK],
                sprintf('Failed to get attachment information with UUID %s', $attachmentUuid)
            ));
        }

        /**
         * Lists all file attachment records with pagination support.
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of attachment records per page (default is 100)
         * @return FileAttachmentRecord[] An array of FileAttachmentRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the page or limit parameters are invalid
         */
        public function listAttachments(int $page=1, int $limit=100): array
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
                fn($item) => FileAttachmentRecord::fromArray($item),
                $this->makeRequest('GET', 'attachments', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list attachments, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        /**
         * Deletes an existing attachment record and it's file off the server
         *
         * @param string $attachmentUuid The attachment UUID to delete
         * @throws RequestException The request fails or the response is invalid
         * @throws InvalidArgumentException If the attachment UUID is empty
         */
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

        /**
         * Downloads an attachment file to the specified directory.
         *
         * The filename is determined by the server's Content-Disposition header.
         * If no filename is provided by the server, the attachment UUID is used as the filename.
         *
         * @param string $attachmentUuid The UUID of the attachment to download
         * @param string $directoryPath The directory to save the downloaded file in
         * @return string The full path to the downloaded file
         * @throws InvalidArgumentException If the attachment UUID is empty, directory path is empty,
         *                                  or the directory does not exist or is not writable
         * @throws RequestException If the download fails
         */
        public function downloadAttachment(string $attachmentUuid, string $directoryPath): string
        {
            if(empty($attachmentUuid))
            {
                throw new InvalidArgumentException('Attachment UUID cannot be empty');
            }

            if(empty($directoryPath))
            {
                throw new InvalidArgumentException('Directory path cannot be empty');
            }

            $directoryPath = rtrim($directoryPath, DIRECTORY_SEPARATOR);

            if(!is_dir($directoryPath))
            {
                throw new InvalidArgumentException('Directory does not exist: ' . $directoryPath);
            }

            if(!is_writable($directoryPath))
            {
                throw new InvalidArgumentException('Directory is not writable: ' . $directoryPath);
            }

            $ch = $this->buildCurl('attachments/' . $attachmentUuid);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError = curl_error($ch);

            if($response === false)
            {
                throw new RequestException('Curl error while downloading attachment: ' . $curlError);
            }

            if($httpCode !== HttpResponseCode::OK->value)
            {
                throw new RequestException('Failed to download attachment, HTTP code: ' . $httpCode, HttpResponseCode::from($httpCode));
            }

            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            $suggestedFilename = $this->extractFilenameFromHeaders($headers);
            $filename = !empty($suggestedFilename) ? $suggestedFilename : $attachmentUuid;
            $finalFilePath = $directoryPath . DIRECTORY_SEPARATOR . $filename;

            $fileHandle = fopen($finalFilePath, 'wb');
            if($fileHandle === false)
            {
                throw new InvalidArgumentException('Failed to open file for writing: ' . $finalFilePath);
            }

            if(fwrite($fileHandle, $body) === false)
            {
                fclose($fileHandle);
                unlink($finalFilePath);
                throw new RequestException('Failed to write attachment data to file: ' . $finalFilePath);
            }

            fclose($fileHandle);
            return $finalFilePath;
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
        public function uploadFileAttachment(string $evidenceUuid, string $localFilePath, ?string $fileName = null): UploadResult
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
            Logger::log()->debug(sprintf("POST Request to %s for file upload", $this->buildUrl($path)));

            $ch = curl_init($this->buildUrl($path));

            // Set up headers for file upload
            $headers = [
                'Accept: application/json'
            ];

            if ($this->accessToken !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $this->accessToken;
            }

            // Create CURLFile for the upload; server-side FederationLib handles sanitization and fallback
            $uploadName = $fileName ?? basename($localFilePath);
            $file = new CURLFile($localFilePath, mime_content_type($localFilePath) ?: 'application/octet-stream', $uploadName);

            // Prepare multipart form data
            $postData = [
                'evidence_uuid' => $evidenceUuid,
                'file' => $file
            ];

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
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
                throw new RequestException('File upload failed: ' . $curlError, HttpResponseCode::from($httpCode));
            }

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $decodedResponse = $this->decodeResponse($response);

            // Check if response code is expected (201 Created for successful upload)
            $expectedStatusCodes = [201, HttpResponseCode::CREATED->value];
            if (!in_array($responseCode, $expectedStatusCodes))
            {
                $errorMsg = 'File upload failed, received response code: ' . $responseCode;
                if (is_array($decodedResponse) && isset($decodedResponse['message']))
                {
                    $errorMsg = 'File upload failed: ' . $decodedResponse['message'] . ' (response code: ' . $responseCode . ')';
                }
                throw new RequestException($errorMsg, $responseCode);
            }

            return UploadResult::fromArray($decodedResponse);
        }

        /**
         * Uploads a note as a .txt file attachment in-memory without creating a physical file
         *
         * @param string $evidenceUuid The UUID of the evidence record to attach the file to
         * @param string $fileName The name of the file (without extension, or with .txt)
         * @param string $content The text content of the note
         * @return UploadResult The upload result containing UUID and download URL
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the evidence UUID, file name, or content is invalid
         * @throws RuntimeException If temporary file creation fails
         */
        public function uploadNoteAttachment(string $evidenceUuid, string $fileName, string $content): UploadResult
        {
            if (empty($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID cannot be empty');
            }

            if (empty($fileName))
            {
                throw new InvalidArgumentException('File name cannot be empty');
            }

            if (empty($content))
            {
                throw new InvalidArgumentException('Content cannot be empty');
            }

            if (!str_ends_with($fileName, '.txt'))
            {
                $fileName .= '.txt';
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'fed_');
            if ($tempFile === false)
            {
                throw new RuntimeException('Failed to create temporary file');
            }

            $bytesWritten = file_put_contents($tempFile, $content);
            if ($bytesWritten === false)
            {
                unlink($tempFile);
                throw new RuntimeException('Failed to write content to temporary file');
            }

            try
            {
                $path = ltrim('attachments', '/');
                Logger::log()->debug(sprintf("POST Request to %s for note upload", $this->buildUrl($path)));

                $ch = curl_init($this->buildUrl($path));

                $headers = [
                    'Accept: application/json'
                ];

                if ($this->accessToken !== null)
                {
                    $headers[] = 'Authorization: Bearer ' . $this->accessToken;
                }

                $file = new CURLFile($tempFile, 'text/plain', $fileName);

                $postData = [
                    'evidence_uuid' => $evidenceUuid,
                    'file' => $file
                ];

                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_UPLOAD_BUFFERSIZE => 65536,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded)
                    {
                        if ($upload_size > 0)
                        {
                            $percent = round(($uploaded / $upload_size) * 100, 2);
                            Logger::log()->debug("Note upload progress: {$percent}% ({$uploaded}/{$upload_size} bytes)");
                        }
                        return 0;
                    }
                ]);

                $response = curl_exec($ch);

                if (curl_errno($ch))
                {
                    $curlError = curl_error($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    throw new RequestException('Note upload failed: ' . $curlError, HttpResponseCode::from($httpCode));
                }

                $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $decodedResponse = $this->decodeResponse($response);

                $expectedStatusCodes = [201, HttpResponseCode::CREATED->value];
                if (!in_array($responseCode, $expectedStatusCodes))
                {
                    $errorMsg = 'Note upload failed, received response code: ' . $responseCode;
                    if (is_array($decodedResponse) && isset($decodedResponse['message']))
                    {
                        $errorMsg = 'Note upload failed: ' . $decodedResponse['message'] . ' (response code: ' . $responseCode . ')';
                    }
                    throw new RequestException($errorMsg, $responseCode);
                }

                return UploadResult::fromArray($decodedResponse);
            }
            finally
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }
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

                // Ensure temp file is readable
                if (!is_readable($tempFile))
                {
                    throw new RequestException('Downloaded temporary file is not readable');
                }

                // Get filename from URL or use a default
                $filename = basename(parse_url($fileUrl, PHP_URL_PATH)) ?: 'downloaded_file';

                // Detect MIME type
                $mimeType = 'application/octet-stream';
                if (function_exists('finfo_open'))
                {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $detectedMime = finfo_file($finfo, $tempFile);
                    if ($detectedMime)
                    {
                        $mimeType = $detectedMime;
                    }
                }
                elseif (function_exists('mime_content_type'))
                {
                    $detectedMime = mime_content_type($tempFile);
                    if ($detectedMime)
                    {
                        $mimeType = $detectedMime;
                    }
                }

                // Upload the downloaded file
                $path = ltrim('attachments', '/');
                Logger::log()->debug(sprintf("POST Request to %s for URL-based file upload", $this->buildUrl($path)));
                $ch = curl_init($this->buildUrl($path));

                // Set up headers for file upload
                $headers = [
                    'Accept: application/json'
                ];

                if ($this->accessToken !== null)
                {
                    $headers[] = 'Authorization: Bearer ' . $this->accessToken;
                }

                // Create CURLFile for the upload
                $file = new CURLFile($tempFile, $mimeType, $filename);

                // Prepare multipart form data
                $postData = [
                    'evidence_uuid' => $evidenceUuid,
                    'file' => $file
                ];

                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
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
                    throw new RequestException('File upload failed: ' . $curlError, HttpResponseCode::from($httpCode));
                }

                $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

                $decodedResponse = $this->decodeResponse($response);

                // Check if response code is expected (201 Created for successful upload)
                $expectedStatusCodes = [201, HttpResponseCode::CREATED->value];
                if (!in_array($responseCode, $expectedStatusCodes))
                {
                    $errorMsg = 'File upload failed, received response code: ' . $responseCode;
                    if (is_array($decodedResponse) && isset($decodedResponse['message']))
                    {
                        $errorMsg = 'File upload failed: ' . $decodedResponse['message'] . ' (response code: ' . $responseCode . ')';
                    }

                    throw new RequestException($errorMsg, $responseCode);
                }

                return UploadResult::fromArray($decodedResponse);

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
         * Searches file attachments by a query string.
         *
         * @param string $query The search query (minimum 2 characters)
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of results per page (default is 10)
         * @return FileAttachmentRecord[] An array of matching FileAttachmentRecord objects
         * @throws RequestException If the request fails or the response is invalid
         * @throws InvalidArgumentException If the query is too short or parameters are invalid
         */
        public function searchAttachments(string $query, int $page = 1, int $limit = 10): array
        {
            if(strlen($query) < 2)
            {
                throw new InvalidArgumentException('Search query must be at least 2 characters');
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
                fn($item) => FileAttachmentRecord::fromArray($item),
                $this->makeRequest('GET', 'attachments/search', ['q' => $query, 'page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to search attachments, query: %s', $query)
                )
            );
        }

        // INTERNAL METHODS

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
         * Decodes the given raw JSON response from the server.
         *
         * @param string $response The raw JSON response from the server
         * @return mixed The decoded response data
         * @throws RequestException If the response cannot be decoded
         */
        private function decodeResponse(string $response): mixed
        {
            $decoded = json_decode($response, true);

            if(json_last_error() !== JSON_ERROR_NONE)
            {
                throw new RequestException('Failed to decode response: ' . json_last_error_msg() . "\n\n" . $response);
            }

            return $decoded;
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

            if($this->accessToken !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $this->accessToken;
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