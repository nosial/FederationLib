<?php

    namespace FederationServer;

    use CurlHandle;
    use FederationServer\Classes\Enums\HttpResponseCode;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\Interfaces\ResponseInterface;
    use FederationServer\Objects\ErrorResponse;
    use FederationServer\Objects\Responses\OperatorRecord;
    use FederationServer\Objects\SuccessResponse;
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
            // Convert any H

            $ch = $this->buildCurl($path);

            // Set HTTP method and data
            switch (strtoupper($method))
            {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    }
                    break;

                case 'PUT':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    if ($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
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

            // Check if response code is expected
            if (!in_array($responseCode, $expectedStatusCodes))
            {
                throw new RequestException($errorMessage . ', received response code: ' . $responseCode, $responseCode);
            }

            // Decode and validate response
            $decodedResponse = $this->decodeResponse($response);
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

        /**
         * Create a new operator
         *
         * @param string $operatorName The name of the operator to create
         * @return string The UUID of the created operator
         * @throws RequestException Throws an exception if the request fails or the operator cannot be created
         */
        public function createOperator(string $operatorName): string
        {
            return $this->makeRequest('POST', '/operators', ['name' => $operatorName], [HttpResponseCode::CREATED],
                sprintf('Failed to create operator with name %s', $operatorName)
            );
        }

        /**
         * Delete an operator
         *
         * @param string $operatorUuid The UUID of the operator to delete
         * @return void
         * @throws RequestException Throws an exception if the request fails or the operator cannot be deleted
         */
        public function deleteOperator(string $operatorUuid): void
        {
            $this->makeRequest('DELETE', '/operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                sprintf('Failed to delete operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Enable an operator
         *
         * @param string $operatorUuid The UUID of the operator to enable
         * @throws RequestException Throws an exception if the request fails or the operator cannot be enabled
         */
        public function enableOperator(string $operatorUuid): void
        {
            $this->makeRequest('POST', '/operators/' . $operatorUuid . '/enable', null, [HttpResponseCode::OK],
                sprintf('Failed to enable operator with UUID %s', $operatorUuid)
            );
        }

        /**
         * Disable an operator
         *
         * @param string $operatorUuid The UUID of the operator to disable
         * @return void
         * @throws RequestException Throws an exception if the request fails or the operator cannot be disabled
         */
        public function disableOperator(string $operatorUuid): void
        {
            $this->makeRequest('POST', '/operators/' . $operatorUuid . '/disable', null, [HttpResponseCode::OK],
                sprintf('Failed to disable operator with UUID %s', $operatorUuid)
            );
        }


        /**
         * Get an operator by UUID
         *
         * @param string $operatorUuid The UUID of the operator to retrieve
         * @return mixed
         * @throws RequestException Throws an exception if the request fails or the operator cannot be found
         */
        public function getOperator(string $operatorUuid): OperatorRecord
        {
            return OperatorRecord::fromArray($this->makeRequest('GET', '/operators/' . $operatorUuid, null, [HttpResponseCode::OK],
                'Failed to get operator'
            ));
        }

        /**
         * List operators with pagination
         *
         * @param int $page The page number to retrieve (default is 1)
         * @param int $limit The number of operators per page (default is 100)
         * @return OperatorRecord[] An array of OperatorRecord objects
         * @throws RequestException Throws an exception if the request fails or if there is an error retrieving the operators
         */
        public function listOperators(int $page=1, int $limit=100): array
        {
            return array_map(
                fn($item) => OperatorRecord::fromArray($item),
                $this->makeRequest('GET', '/operators', ['page' => $page, 'limit' => $limit], [HttpResponseCode::OK],
                    sprintf('Failed to list operators, page: %d, limit: %d', $page, $limit)
                )
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

            if(!isset($decoded['success']) || !$decoded['success'])
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
            $ch = curl_init($this->endpoint . $path);

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
    }

