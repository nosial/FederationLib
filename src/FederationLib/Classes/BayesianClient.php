<?php

    namespace FederationLib\Classes;

    use CurlHandle;
    use FederationLib\Classes\Configuration\BayesianConfiguration;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\BayesianAnalytics;
    use FederationLib\Objects\BayesianClassification;
    use FederationLib\Objects\BayesianLearn;
    use FederationLib\Objects\BayesianServer;
    use InvalidArgumentException;

    class BayesianClient
    {
        private string $endpoint;

        /**
         * BayesianClient Constructor
         *
         * @param string|BayesianConfiguration $endpoint Endpoint URL or BayesianConfiguration instance
         * @throws InvalidArgumentException If the endpoint is not a valid URL
         */
        public function __construct(string|BayesianConfiguration $endpoint)
        {
            if($endpoint instanceof BayesianConfiguration)
            {
                $endpoint = sprintf('%s://%s:%d', ($endpoint->useSsl() ? 'https' : 'http'), $endpoint->getHost(), $endpoint->getPort());
            }

            $parsedUrl = parse_url($endpoint);
            if(empty($endpoint) || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host']))
            {
                throw new InvalidArgumentException("Endpoint must be a valid URL");
            }

            $this->endpoint = rtrim($endpoint, '/');
        }

        /**
         * Classifies text and returns per-label scores.
         *
         * @param string $text The text to classify (required)
         * @param int|null $topK Maximum number of labels to return; null or <=0 returns all
         * @param float|null $threshold Override for the multi-label decision threshold (0..1); null uses server default
         * @return BayesianClassification The classification result
         * @throws RequestException On request failure
         */
        public function classify(string $text, ?int $topK = null, ?float $threshold = null): BayesianClassification
        {
            $data = ['text' => $text];

            if($topK !== null)
            {
                $data['top_k'] = $topK;
            }

            if($threshold !== null)
            {
                $data['threshold'] = $threshold;
            }

            $response = $this->request('POST', '/', $data, [200], 'Classification failed');
            return BayesianClassification::fromArray($response);
        }

        /**
         * Submits one or more documents for asynchronous training.
         *
         * @param string $text The document text
         * @param string|array $labels A single label string or an array of label strings
         * @return BayesianLearn The learn response
         * @throws RequestException On request failure
         */
        public function learn(string $text, string|array $labels): BayesianLearn
        {
            if(is_string($labels))
            {
                $labels = [$labels];
            }

            $data = [
                'text' => $text,
                'labels' => $labels
            ];

            $response = $this->request('PUSH', '/', $data, [202, 503], 'Learn failed');
            return BayesianLearn::fromArray($response);
        }

        /**
         * Submits a batch of documents for asynchronous training.
         *
         * @param array $documents Array of documents, each with 'text' and 'labels' keys
         * @return BayesianLearn The learn response
         * @throws RequestException On request failure
         */
        public function learnBatch(array $documents): BayesianLearn
        {
            $normalized = [];

            foreach($documents as $doc)
            {
                $entry = ['text' => $doc['text']];

                if(isset($doc['label']))
                {
                    $entry['label'] = $doc['label'];
                }

                if(isset($doc['labels']))
                {
                    $entry['labels'] = $doc['labels'];
                }

                $normalized[] = $entry;
            }

            $data = ['documents' => $normalized];
            $response = $this->request('PUSH', '/', $data, [202, 503], 'Batch learn failed');

            return BayesianLearn::fromArray($response);
        }

        /**
         * Returns full model diagnostics and server information.
         *
         * @return BayesianServer Model and server statistics
         * @throws RequestException On request failure
         */
        public function getStatus(): BayesianServer
        {
            $response = $this->request('GET', '/', null, [200], 'Failed to get server status');

            return BayesianServer::fromArray($response);
        }

        /**
         * Lightweight liveness probe.
         *
         * @return bool True if the server is healthy
         * @throws RequestException On request failure
         */
        public function health(): bool
        {
            $response = $this->request('GET', '/health', null, [200], 'Health check failed');

            return isset($response['status']) && $response['status'] === true;
        }

        /**
         * Queries the analytics history using a JSON body (POST).
         *
         * @param array $filters Optional filters: type, language, label, from, to, success, limit, offset, sort
         * @return BayesianAnalytics Filtered analytics entries
         * @throws RequestException On request failure
         */
        public function queryAnalytics(array $filters = []): BayesianAnalytics
        {
            $allowed = ['type', 'language', 'label', 'from', 'to', 'success', 'limit', 'offset', 'sort'];
            $params = array_intersect_key($filters, array_flip($allowed));
            $response = $this->request('POST', '/analytics', $params, [200], 'Failed to query analytics');

            return BayesianAnalytics::fromArray($response);
        }

        /**
         * Makes an HTTP request to the BayesianServer.
         *
         * @param string $method HTTP method
         * @param string $path API path
         * @param array|null $data Request data
         * @param array $expectedStatusCodes Expected successful HTTP status codes
         * @param string $errorMessage Custom error message prefix
         * @return array Decoded response array
         * @throws RequestException On request failure
         */
        private function request(string $method, string $path, ?array $data = null, array $expectedStatusCodes = [200], string $errorMessage = 'Request failed'): array
        {
            $path = ltrim($path, '/');

            if(strtoupper($method) === 'GET' && !empty($data))
            {
                $queryString = http_build_query($data);
                $path .= '?' . $queryString;
            }

            Logger::log()->debug(sprintf("%s Request to %s", $method, $this->buildUrl($path)));

            $ch = $this->buildCurl($path);

            switch(strtoupper($method))
            {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, true);
                    if($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                    break;

                case 'PUT':
                case 'PUSH':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    if($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                    break;

                case 'GET':
                default:
                    break;
            }

            $response = curl_exec($ch);

            if(curl_errno($ch))
            {
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                throw new RequestException($errorMessage . ': ' . $curlError, $httpCode);
            }

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if(!in_array($responseCode, $expectedStatusCodes))
            {
                throw new RequestException(
                    $errorMessage . ' received response code: ' . $responseCode,
                    $responseCode
                );
            }

            return $this->decodeResponse($response, $responseCode, $errorMessage);
        }

        /**
         * Decodes the JSON response from the BayesianServer.
         *
         * The BayesianServer returns data directly on success,
         * or {"error": "...", "status": <code>} on failure.
         *
         * @param string $response Raw JSON response
         * @param int $responseCode HTTP status code
         * @param string $errorMessage Error message prefix
         * @return array Decoded response data
         * @throws RequestException On decode failure or server error
         */
        private function decodeResponse(string $response, int $responseCode, string $errorMessage): array
        {
            $decoded = json_decode($response, true);

            if(json_last_error() !== JSON_ERROR_NONE)
            {
                throw new RequestException($errorMessage . ': Failed to decode response: ' . json_last_error_msg(), $responseCode);
            }

            // Check for BayesianServer error format: {"error": "...", "status": <code>}
            if(isset($decoded['error']))
            {
                throw new RequestException($errorMessage . ': ' . $decoded['error'], $decoded['status'] ?? $responseCode);
            }

            return $decoded;
        }

        /**
         * Builds a cURL handle for the given path.
         *
         * @param string $path The API path
         * @return CurlHandle The constructed CurlHandle
         * @throws RequestException On request failure
         */
        private function buildCurl(string $path): CurlHandle
        {
            $ch = curl_init($this->buildUrl($path));

            if($ch === false)
            {
                throw new RequestException('Failed to initialize cURL handle for Bayesian server', HttpResponseCode::SERVICE_UNAVAILABLE);
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            return $ch;
        }

        /**
         * Builds the full URL for the given path.
         *
         * @param string $path The API path
         * @return string The full URL
         */
        private function buildUrl(string $path): string
        {
            return $this->endpoint . '/' . $path;
        }
    }
