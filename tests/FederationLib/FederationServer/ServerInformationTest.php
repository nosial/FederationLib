<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\FederationServer;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class ServerInformationTest extends TestCase
    {
        private FederationClient $client;

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        protected function tearDown(): void
        {
            Logger::unregisterHandlers();
        }

        public function testGetServerInformation(): void
        {
            $serverInfo = $this->client->getServerInformation();

            $this->assertNotNull($serverInfo);
            $this->assertIsString($serverInfo->getServerName());
            $this->assertNotEmpty($serverInfo->getServerName());
            $this->assertIsString($serverInfo->getApiVersion());
            $this->assertNotEmpty($serverInfo->getApiVersion());
            $this->assertIsBool($serverInfo->isPublicEntities());
            $this->assertIsBool($serverInfo->isPublicEvidence());
        }

        public function testServerInformationConsistency(): void
        {
            $serverInfo1 = $this->client->getServerInformation();

            for ($i = 0; $i < 5; $i++)
            {
                $serverInfo = $this->client->getServerInformation();
                $this->assertEquals($serverInfo1->getServerName(), $serverInfo->getServerName());
                $this->assertEquals($serverInfo1->getApiVersion(), $serverInfo->getApiVersion());
                $this->assertEquals($serverInfo1->isPublicEntities(), $serverInfo->isPublicEntities());
                $this->assertEquals($serverInfo1->isPublicEvidence(), $serverInfo->isPublicEvidence());
            }
        }

        public function testServerInformationWithAuthentication(): void
        {
            $unauthenticatedInfo = $this->client->getServerInformation();

            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $authenticatedInfo = $authenticatedClient->getServerInformation();

            $this->assertEquals($unauthenticatedInfo->getServerName(), $authenticatedInfo->getServerName());
            $this->assertEquals($unauthenticatedInfo->getApiVersion(), $authenticatedInfo->getApiVersion());
            $this->assertEquals($unauthenticatedInfo->isPublicEntities(), $authenticatedInfo->isPublicEntities());
            $this->assertEquals($unauthenticatedInfo->isPublicEvidence(), $authenticatedInfo->isPublicEvidence());
        }

        public function testApiVersionFormat(): void
        {
            $serverInfo = $this->client->getServerInformation();
            $apiVersion = $serverInfo->getApiVersion();

            $this->assertNotEmpty(trim($apiVersion));
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+(\.\d+)?(-[a-zA-Z0-9\-.]+)?(\+[a-zA-Z0-9\-.]+)?$/',
                $apiVersion,
                'API version should follow semantic versioning format'
            );
        }

        public function testServerInformationPerformance(): void
        {
            $requestCount = 10;
            $maxResponseTime = 5.0;

            for ($i = 0; $i < $requestCount; $i++)
            {
                $startTime = microtime(true);
                $serverInfo = $this->client->getServerInformation();
                $responseTime = microtime(true) - $startTime;

                $this->assertLessThan($maxResponseTime, $responseTime, "Server information request took too long: {$responseTime}s");
                $this->assertNotNull($serverInfo);
            }
        }

        public function testConcurrentServerInformationRequests(): void
        {
            $clients = [];
            $results = [];

            for ($i = 0; $i < 5; $i++)
            {
                $clients[] = new FederationClient(getenv('SERVER_ENDPOINT'));
            }

            foreach ($clients as $client)
            {
                $serverInfo = $client->getServerInformation();
                $results[] = [
                    'server_name' => $serverInfo->getServerName(),
                    'api_version' => $serverInfo->getApiVersion(),
                    'public_entities' => $serverInfo->isPublicEntities(),
                    'public_evidence' => $serverInfo->isPublicEvidence()
                ];
            }

            $firstResult = reset($results);
            foreach ($results as $index => $result)
            {
                $this->assertEquals($firstResult['server_name'], $result['server_name'], "Server name mismatch for client $index");
                $this->assertEquals($firstResult['api_version'], $result['api_version'], "API version mismatch for client $index");
                $this->assertEquals($firstResult['public_entities'], $result['public_entities'], "Public entities setting mismatch for client $index");
                $this->assertEquals($firstResult['public_evidence'], $result['public_evidence'], "Public evidence setting mismatch for client $index");
            }
        }

        public function testSpecificationStructure(): void
        {
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $specification = $authenticatedClient->getSpecification();

            $this->assertArrayHasKey('openapi', $specification);
            $this->assertArrayHasKey('info', $specification);
            $this->assertArrayHasKey('paths', $specification);
            $this->assertMatchesRegularExpression('/^3\./', $specification['openapi']);
            $this->assertArrayHasKey('title', $specification['info']);
            $this->assertNotEmpty($specification['info']['title']);
            $this->assertIsArray($specification['paths']);
            $this->assertNotEmpty($specification['paths']);
        }

        public function testServerInformationCountAccuracyAfterOperatorMutation(): void
        {
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $before = $authenticatedClient->getServerInformation();
            $this->assertIsInt($before->getOperators());

            $operatorUuid = $authenticatedClient->createOperator('count-accuracy-operator');
            $afterCreate = $authenticatedClient->getServerInformation();
            $this->assertEquals($before->getOperators() + 1, $afterCreate->getOperators());

            $authenticatedClient->deleteOperator($operatorUuid);
            $afterDelete = $authenticatedClient->getServerInformation();
            $this->assertEquals($before->getOperators(), $afterDelete->getOperators());
        }

        public function testServerInformationCountAccuracyAfterEntityAndEvidenceMutation(): void
        {
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $before = $authenticatedClient->getServerInformation();

            $entityUuid = $authenticatedClient->pushEntity('count-accuracy.com', 'count_user');
            $afterEntity = $authenticatedClient->getServerInformation();
            $this->assertEquals($before->getKnownEntities() + 1, $afterEntity->getKnownEntities());

            $evidenceUuid = $authenticatedClient->submitEvidence($entityUuid, 'Count accuracy evidence', 'Note', 'count');
            $afterEvidence = $authenticatedClient->getServerInformation();
            $this->assertEquals($before->getEvidenceRecords() + 1, $afterEvidence->getEvidenceRecords());

            $blacklistUuid = $authenticatedClient->blacklistEntity($entityUuid, $evidenceUuid, IncidentType::SPAM, time() + 3600);
            $afterBlacklist = $authenticatedClient->getServerInformation();
            $this->assertEquals($before->getBlacklistRecords() + 1, $afterBlacklist->getBlacklistRecords());

            // Cleanup in reverse order of foreign-key dependencies.
            $authenticatedClient->deleteBlacklistRecord($blacklistUuid);
            $authenticatedClient->deleteEvidence($evidenceUuid);
            $authenticatedClient->deleteEntity($entityUuid);

            $afterCleanup = $authenticatedClient->getServerInformation();
            $this->assertEquals($before->getKnownEntities(), $afterCleanup->getKnownEntities());
            $this->assertEquals($before->getEvidenceRecords(), $afterCleanup->getEvidenceRecords());
            $this->assertEquals($before->getBlacklistRecords(), $afterCleanup->getBlacklistRecords());
        }

        public function testServerInformationConsistencyUnderRapidMutations(): void
        {
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $baseline = $authenticatedClient->getServerInformation();

            $entityUuids = [];
            for ($i = 0; $i < 5; $i++)
            {
                $entityUuids[] = $authenticatedClient->pushEntity("rapid-mutation-$i.com", "user_$i");
            }

            $afterCreate = $authenticatedClient->getServerInformation();
            $this->assertEquals($baseline->getKnownEntities() + 5, $afterCreate->getKnownEntities());

            foreach ($entityUuids as $uuid)
            {
                $authenticatedClient->deleteEntity($uuid);
            }

            $afterDelete = $authenticatedClient->getServerInformation();
            $this->assertEquals($baseline->getKnownEntities(), $afterDelete->getKnownEntities());
        }

        public function testServerInformationUnauthenticatedMatchesAuthenticated(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));

            $public = $unauthenticatedClient->getServerInformation();
            $private = $authenticatedClient->getServerInformation();

            $this->assertEquals($private->getServerName(), $public->getServerName());
            $this->assertEquals($private->getApiVersion(), $public->getApiVersion());
            $this->assertEquals($private->isPublicAuditLogs(), $public->isPublicAuditLogs());
            $this->assertEquals($private->isPublicEvidence(), $public->isPublicEvidence());
            $this->assertEquals($private->isPublicBlacklist(), $public->isPublicBlacklist());
            $this->assertEquals($private->isPublicEntities(), $public->isPublicEntities());
            $this->assertEquals($private->getOperators(), $public->getOperators());
            $this->assertEquals($private->getKnownEntities(), $public->getKnownEntities());
        }

        public function testSpecificationContainsCorePaths(): void
        {
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $specification = $authenticatedClient->getSpecification();

            $expectedPaths = [
                '/',
                '/info',
                '/specification',
                '/scan',
                '/entities',
                '/entities/{identifier}',
                '/evidence',
                '/evidence/{uuid}',
                '/blacklist',
                '/blacklist/{uuid}',
                '/reports',
                '/reports/{uuid}',
                '/operators',
                '/operators/{uuid}',
                '/attachments',
                '/attachments/{uuid}',
                '/audit/{uuid}',
            ];

            foreach ($expectedPaths as $path)
            {
                $this->assertArrayHasKey(
                    $path,
                    $specification['paths'],
                    "Specification should document path $path"
                );
            }
        }

        public function testPublicFlagsControlAnonymousReadAccess(): void
        {
            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $serverInfo = $authenticatedClient->getServerInformation();

            // Create a representative set of records to test visibility.
            $entityUuid = $authenticatedClient->pushEntity('public-flag-test.com', 'public_flag_user');
            $evidenceUuid = $authenticatedClient->submitEvidence($entityUuid, 'Public flag evidence', 'Note', 'public_flag');
            $blacklistEvidenceUuid = $authenticatedClient->submitEvidence($entityUuid, 'Blacklist evidence', 'Note', 'bl');
            $blacklistUuid = $authenticatedClient->blacklistEntity($entityUuid, $blacklistEvidenceUuid, IncidentType::SPAM, time() + 3600);

            // Entities
            if ($serverInfo->isPublicEntities())
            {
                $this->assertNotNull($anonymousClient->getEntityRecord($entityUuid));
            }
            else
            {
                $this->expectException(RequestException::class);
                $anonymousClient->getEntityRecord($entityUuid);
            }

            // Evidence
            try
            {
                $anonymousClient->getEvidenceRecord($evidenceUuid);
                $this->assertTrue($serverInfo->isPublicEvidence(), 'Anonymous evidence access should match public_evidence flag');
            }
            catch (RequestException)
            {
                $this->assertFalse($serverInfo->isPublicEvidence(), 'Anonymous evidence rejection should match public_evidence flag');
            }

            // Blacklist
            try
            {
                $anonymousClient->getBlacklistRecord($blacklistUuid);
                $this->assertTrue($serverInfo->isPublicBlacklist(), 'Anonymous blacklist access should match public_blacklist flag');
            }
            catch (RequestException)
            {
                $this->assertFalse($serverInfo->isPublicBlacklist(), 'Anonymous blacklist rejection should match public_blacklist flag');
            }

            // Cleanup
            $authenticatedClient->deleteBlacklistRecord($blacklistUuid);
            $authenticatedClient->deleteEvidence($evidenceUuid);
            $authenticatedClient->deleteEvidence($blacklistEvidenceUuid);
            $authenticatedClient->deleteEntity($entityUuid);
        }

        public function testAuthenticatedClientCanReadRegardlessOfPublicFlags(): void
        {
            $authenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));

            $entityUuid = $authenticatedClient->pushEntity('auth-flag-test.com', 'auth_flag_user');
            $evidenceUuid = $authenticatedClient->submitEvidence($entityUuid, 'Auth flag evidence', 'Note', 'auth_flag');

            $this->assertNotNull($authenticatedClient->getEntityRecord($entityUuid));
            $this->assertNotNull($authenticatedClient->getEvidenceRecord($evidenceUuid));

            $authenticatedClient->deleteEvidence($evidenceUuid);
            $authenticatedClient->deleteEntity($entityUuid);
        }

        public function testServerInformationPublicFlagsAreBoolean(): void
        {
            $serverInfo = $this->client->getServerInformation();

            $this->assertIsBool($serverInfo->isPublicAuditLogs());
            $this->assertIsBool($serverInfo->isPublicEvidence());
            $this->assertIsBool($serverInfo->isPublicBlacklist());
            $this->assertIsBool($serverInfo->isPublicEntities());
        }
    }
