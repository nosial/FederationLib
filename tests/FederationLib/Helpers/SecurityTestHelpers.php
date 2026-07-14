<?php

    namespace FederationLib\Helpers;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
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
    trait SecurityTestHelpers
    {
        /**
         * Creates an operator with the requested permission bits and returns a client authenticated as that operator.
         */
        private function createLimitedOperator(string $namePrefix, bool $management = false, bool $operator = false, bool $client = false): FederationClient
        {
            $operatorName = substr(uniqid($namePrefix . '_'), 0, 32);
            $operatorUuid = $this->client->createOperator($operatorName);
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

            $record = $this->client->getOperator($operatorUuid);
            return new FederationClient(getenv('SERVER_ENDPOINT'), $record->getAccessToken());
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
         * Creates a blacklist record for an entity and registers it for cleanup.
         */
        private function createSecurityBlacklist(string $entityUuid, ?FederationClient $client = null): string
        {
            $client ??= $this->client;
            $evidenceUuid = $this->createSecurityEvidence($entityUuid, false, $client);
            $blacklistUuid = $client->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
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
            $submission = $client->submitReport($entityUuid, 'Security test report', IncidentType::SPAM);

            $reportUuid = $submission->getReport()->getUuid();
            $evidenceUuid = $submission->getEvidence()->getUuid();

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
