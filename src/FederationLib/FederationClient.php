<?php

    namespace FederationLib;

    use CurlHandle;
    use FederationLib\Classes\Logger;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Interfaces\ResponseInterface;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Operator;
    use FederationLib\Objects\SuccessResponse;
    use InvalidArgumentException;

    class FederationClient
    {
        private string $endpoint;
        private ?string $token;

        /**
         * Constructor for FederationClient
         *
         * @param string $endpoint The endpoint URL for the federation server
         * @param string|null $token Optional token for authentication
         * @throws InvalidArgumentException If the endpoint is not a valid URL or if the token is an empty string
         */
        public function __construct(string $endpoint, ?string $token=null)
        {
            if(empty($endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL))
            {
                throw new InvalidArgumentException("Endpoint must be a valid URL");
            }

            if($token !== null && strlen($token) === 0)
            {
                throw new InvalidArgumentException("Token cannot be an empty string");
            }

            // Remove trailing slash from endpoint if present
            $endpoint = rtrim($endpoint, '/');

            $this->endpoint = $endpoint;
            $this->token = $token;
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
        public function getToken(): ?string
        {
            return $this->token;
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

        public function createOperator(string $operatorName): string
        {
            return $this->makeRequest('POST', 'operators', ['name' => $operatorName], [HttpResponseCode::CREATED],
                sprintf('Failed to create operator with name %s', $operatorName)
            );
        }

        public function deleteOperator(string $operatorUuid): void
        {
            $this->makeRequest('DELETE', 'operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete operator with UUID %s', $operatorUuid)
            );
        }

        public function disableOperator(string $operatorUuid): void
        {
            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/disable', null, [HttpResponseCode::OK],
                sprintf('Failed to disable operator with UUID %s', $operatorUuid)
            );
        }


        public function enableOperator(string $operatorUuid): void
        {
            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/enable', null, [HttpResponseCode::OK],
                sprintf('Failed to enable operator with UUID %s', $operatorUuid)
            );
        }

        public function getOperator(string $operatorUuid): Operator
        {
            return Operator::fromArray($this->makeRequest('GET', 'operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                'Failed to get operator'
            ));
        }

        public function getSelfOperator(): Operator
        {
            return Operator::fromArray($this->makeRequest('GET', 'operators/self', null, [HttpResponseCode::OK],
                'Failed to get self operator'
            ));
        }

        public function listOperators(int $page=1, int $limit=100): array
        {
            return array_map(
                fn($item) => Operator::fromArray($item),
                $this->makeRequest('GET', 'operators', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list operators, page: %d, limit: %d', $page, $limit)
                )
            );
        }

        public function listOperatorAuditLogs(string $operatorUuid, int $page=1, int $limit=100): array
        {
            return array_map(
                fn($item) => AuditLog::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/audit', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list audit logs for operator with UUID %s, page: %d, limit: %d', $operatorUuid, $page, $limit)
                )
            );
        }


        public function listOperatorEvidence(string $operatorUuid, int $page=1, int $limit=100): array
        {
            return array_map(
                fn($item) => EvidenceRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/evidence', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list evidence records for operator with UUID %s, page %d, limit: %d', $operatorUuid, $page, $limit)
                )
            );
        }

        public function listOperatorBlacklist(string $operatorUuid, int $page=1, int $limit=100): array
        {
            return array_map(
                fn($item) => BlacklistRecord::fromArray($item),
                $this->makeRequest('GET', 'operators/' . $operatorUuid . '/evidence', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list operator blacklist records with UUID %s, page %d, limit %d', $operatorUuid, $page, $limit)
                )
            );
        }


        public function setManageOperatorsPermission(string $operatorUuid, bool $manageOperators): void
        {
            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/manage_operators', ['enabled' => $manageOperators], [HttpResponseCode::OK],
                sprintf('Failed to %s the operator\'s permission to manage other operators', ($manageOperators ? 'enable' : 'disable'))
            );
        }

        public function setClientPermission(string $operatorUuid, bool $isClient): void
        {
            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/manage_client', ['enabled' => $isClient], [HttpResponseCode::OK],
                sprintf('Failed to %s the operator\'s client permissions', ($isClient ? 'enable' : 'disable'))
            );
        }


        public function setManageBlacklistPermission(string $operatorUuid, bool $manageBlacklist): void
        {
            $this->makeRequest('POST', 'operators/' . $operatorUuid . '/manage_blacklist', ['enabled' => $manageBlacklist], [HttpResponseCode::OK],
                sprintf('Failed to %s operator\'s blacklist management permission', ($manageBlacklist ? 'enable' : 'disable'))
            );
        }


        public function deleteEntity(string $entityIdentifier): void
        {
            $this->makeRequest(
                'DELETE', 'entities/' . $entityIdentifier, null, [HttpResponseCode::OK],
                sprintf('Failed to delete the entity %s', $entityIdentifier)
            );
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
                throw new RequestException('Failed to decode response: ' . json_last_error_msg());
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

            if($this->token !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $this->token;
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            return $ch;
        }

        private function buildUrl(string $path): string
        {
            return $this->endpoint . '/' . $path;
        }
    }

