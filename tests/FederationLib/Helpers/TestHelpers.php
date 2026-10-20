<?php

    namespace FederationLib\Helpers;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Objects\ContentInput;
    use Symfony\Component\Uid\Uuid;

    /**
     * Reusable helpers for security, permission, and abuse tests.
     *
     * Classes using this trait must expose:
     *   - FederationClient $client
     *   - array $createdOperators
     *   - array $createdEntities
     *   - array $createdEvidenceRecords
     *   - array $createdBlacklistRecords
     *   - array $createdReports
     *   - array $tempFiles
     */
    trait TestHelpers
    {
        /**
         * Creates an operator with the requested permission bits and returns a client authenticated as that operator.
         */
        private function createLimitedOperator(string $namePrefix, bool $management = false, bool $operator = false, bool $client = false): FederationClient
        {
            $operatorName = substr(uniqid($namePrefix . '_'), 0, 32);
            $createdOperator = $this->client->createOperator($operatorName);
            $operatorUuid = $createdOperator->getUuid();
            $this->createdOperators[] = $operatorUuid;

            if ($management)
            {
                $this->client->setManagementPermissions($operatorUuid, true);
            }

            if ($operator)
            {
                $this->client->setOperatorPermissions($operatorUuid, true);
            }

            if ($client)
            {
                $this->client->setClientPermissions($operatorUuid, true);
            }

            return new FederationClient(getenv('SERVER_ENDPOINT'), $createdOperator->getAccessToken());
        }

        /**
         * Creates a simple entity and registers it for cleanup.
         */
        private function createSecurityEntity(?FederationClient $client = null): string
        {
            $client ??= $this->client;
            $entityUuid = $client->pushEntity(uniqid('security-test-') . '.com', 'user_' . uniqid());
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        /**
         * Creates evidence for an entity and registers it for cleanup.
         */
        private function createSecurityEvidence(string $entityUuid, bool $confidential = false, ?FederationClient $client = null): string
        {
            $client ??= $this->client;
            $evidenceUuid = $client->submitEvidence($entityUuid, 'Security test evidence', 'security note', 'security', $confidential);
            $this->createdEvidenceRecords[] = $evidenceUuid;
            return $evidenceUuid;
        }

        /**
         * Creates a report (with a single evidence record) for an existing entity and registers both for cleanup.
         */
        private function createReportForEntity(string $entityUuid, ?FederationClient $client = null, string $content = 'Security test report'): string
        {
            $client ??= $this->client;
            $submission = $client->submitReport($entityUuid, new ContentInput($content), IncidentType::SPAM);

            $reportUuid = $submission->getReport()->getUuid();
            $this->createdReports[] = $reportUuid;

            foreach($submission->getEvidence() as $evidenceRecord)
            {
                $this->createdEvidenceRecords[] = $evidenceRecord->getUuid();
            }

            return $reportUuid;
        }

        /**
         * Creates a blacklist record for an entity and registers it for cleanup.
         */
        private function createSecurityBlacklist(string $entityUuid, ?FederationClient $client = null): string
        {
            $client ??= $this->client;
            $reportUuid = $this->createReportForEntity($entityUuid, $client);
            $blacklistUuid = $client->blacklistEntity($entityUuid, $reportUuid, IncidentType::SPAM, time() + 3600);
            $this->createdBlacklistRecords[] = $blacklistUuid;
            return $blacklistUuid;
        }

        /**
         * Creates a report and registers related records for cleanup.
         *
         * @return array{report: string, entity: string, evidence: string}
         */
        private function createSecurityReport(?FederationClient $client = null): array
        {
            $client ??= $this->client;
            $entityUuid = $this->createSecurityEntity($client);
            $submission = $client->submitReport($entityUuid, new ContentInput('Security test report'), IncidentType::SPAM);

            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()[0]->getUuid();

            $this->createdReports[] = $reportUuid;
            $this->createdEvidenceRecords[] = $evidenceUuid;

            return ['report' => $reportUuid, 'entity' => $entityUuid, 'evidence' => $evidenceUuid];
        }

        /**
         * Asserts that a callable triggers a RequestException with one of the allowed HTTP codes.
         */
        private function expectRequestFailure(callable $callback, array $allowedCodes, string $message = ''): void
        {
            try
            {
                $callback();
                $this->fail($message ?: 'Expected RequestException but the operation succeeded');
            }
            catch (RequestException $e)
            {
                $this->assertContains(
                    $e->getCode(),
                    $allowedCodes,
                    'Unexpected HTTP status code: ' . $e->getCode() . ' - ' . $e->getMessage()
                );
            }
        }

        /**
         * Creates a temporary file and registers it for cleanup.
         */
        private function createSecurityTempFile(string $content, string $suffix = 'txt'): string
        {
            $path = tempnam(sys_get_temp_dir(), 'security_') . '.' . $suffix;
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;
            return $path;
        }

        /**
         * Performs a raw HTTP request against the test server.
         *
         * @return array{0: int, 1: string}
         */
        private function rawRequest(string $method, string $path, ?string $token = null, ?string $body = null, array $extraHeaders = []): array
        {
            $url = rtrim(getenv('SERVER_ENDPOINT'), '/') . '/' . ltrim($path, '/');
            $ch = curl_init($url);

            $headers = ['Accept: application/json'];
            if ($body !== null)
            {
                $headers[] = 'Content-Type: application/json';
            }

            if ($token !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            $headers = array_merge($headers, $extraHeaders);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headers,
            ]);

            if ($body !== null)
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return [(int)$code, (string)$response];
        }

        /**
         * Performs multiple raw HTTP requests against the test server concurrently.
         *
         * @param array<int, array{method: string, path: string, token?: string|null, body?: string|null, headers?: array<string>}> $requests
         * @return array<int, array{0: int, 1: string}>
         */
        private function concurrentRawRequests(array $requests): array
        {
            $baseUrl = rtrim(getenv('SERVER_ENDPOINT'), '/');
            $handles = [];
            $multiHandle = curl_multi_init();

            foreach ($requests as $index => $request)
            {
                $url = $baseUrl . '/' . ltrim($request['path'], '/');
                $ch = curl_init($url);

                $headers = ['Accept: application/json'];
                if (isset($request['body']) && $request['body'] !== null)
                {
                    $headers[] = 'Content-Type: application/json';
                }

                $token = $request['token'] ?? null;
                if ($token !== null)
                {
                    $headers[] = 'Authorization: Bearer ' . $token;
                }

                if (isset($request['headers']))
                {
                    $headers = array_merge($headers, $request['headers']);
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => strtoupper($request['method']),
                    CURLOPT_HTTPHEADER => $headers,
                ]);

                if (isset($request['body']) && $request['body'] !== null)
                {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
                }

                curl_multi_add_handle($multiHandle, $ch);
                $handles[$index] = $ch;
            }

            $running = null;
            do
            {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            }
            while ($running > 0);

            $results = [];
            foreach ($handles as $index => $ch)
            {
                $results[$index] = [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)curl_multi_getcontent($ch)];
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }

            curl_multi_close($multiHandle);
            return $results;
        }

        /**
         * Deletes the records registered for cleanup, dependent records first, and clears the collections. Failures are
         * logged, a record may already have been removed by the test or along with the record it depended on.
         */
        private function cleanupRecords(): void
        {
            $collections = [
                'blacklist record' => [&$this->createdBlacklistRecords, fn(string $uuid) => $this->client->deleteBlacklistRecord($uuid)],
                'report' => [&$this->createdReports, fn(string $uuid) => $this->client->deleteReport($uuid)],
                'evidence record' => [&$this->createdEvidenceRecords, fn(string $uuid) => $this->client->deleteEvidence($uuid)],
                'entity' => [&$this->createdEntities, fn(string $uuid) => $this->client->deleteEntity($uuid)],
                'operator' => [&$this->createdOperators, fn(string $uuid) => $this->client->deleteOperator($uuid)],
            ];

            foreach($collections as $name => [&$uuids, $delete])
            {
                foreach(array_reverse($uuids) as $uuid)
                {
                    try
                    {
                        $delete($uuid);
                    }
                    catch (RequestException $e)
                    {
                        Logger::getLogger()->warning(sprintf('Failed to delete %s %s: %s', $name, $uuid, $e->getMessage()));
                    }
                }

                $uuids = [];
            }

            foreach($this->tempFiles as $path)
            {
                @unlink($path);
            }

            $this->tempFiles = [];
        }

        /**
         * Performs a raw HTTP request and decodes its JSON response
         *
         * @return array{0: int, 1: mixed} The HTTP status code and the decoded response
         */
        private function jsonRequest(string $method, string $path, ?string $token = null, ?array $body = null): array
        {
            [$code, $response] = $this->rawRequest($method, $path, $token, $body !== null ? json_encode($body) : null);
            return [$code, json_decode($response, true)];
        }

        /**
         * Returns the access token of the root operator
         */
        private function rootToken(): string
        {
            return (string)getenv('SERVER_ACCESS_TOKEN');
        }

        /**
         * Returns a unique telephone entity local part of a valid number (+1 650-NXX-XXXX), so that it is found in
         * content by libphonenumber. The exchange never ends in 11 and is never 555.
         */
        private function uniqueTelephoneNumber(): string
        {
            do
            {
                $exchange = random_int(2, 8) * 100 + random_int(20, 99);
            }
            while($exchange === 555);

            return '1650' . $exchange . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        }

        /**
         * Formats a telephone entity local part of uniqueTelephoneNumber() as written in a message, eg; +1 650-253-0000
         */
        private function formatTelephoneNumber(string $localPart): string
        {
            return sprintf('+1 %s-%s-%s', substr($localPart, 1, 3), substr($localPart, 4, 3), substr($localPart, 7));
        }

        /**
         * Returns a unique host that does not exist
         */
        private function uniqueHost(string $prefix = 'test'): string
        {
            return strtolower(uniqid($prefix . '-')) . '.com';
        }

        /**
         * Removes a UUID from a cleanup collection.
         */
        private function removeFromCleanup(array &$collection, string $uuid): void
        {
            $index = array_search($uuid, $collection, true);
            if ($index !== false)
            {
                array_splice($collection, $index, 1);
            }
        }

        /**
         * Returns a random v4 UUID string.
         */
        private function randomUuid(): string
        {
            return Uuid::v7()->toRfc4122();
        }
    }
