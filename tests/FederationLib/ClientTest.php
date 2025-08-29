<?php

    namespace FederationLib;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
        private const string FAKE_OPERATOR_UUID = '0198f41f-45c7-78eb-a2a7-86de4e99991a';
        private FederationClient $client;

        protected function setUp(): void
        {
            // Note, authentication is not required for these tests.
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'));
        }

        public function testGetServerInformation()
        {
            $serverInformation = $this->client->getServerInformation();

            $this->assertNotNull($serverInformation);
            $this->assertNotEmpty($serverInformation->getServerName());
            $this->assertNotEmpty($serverInformation->getApiVersion());
            $this->assertEquals('2025.01', $serverInformation->getApiVersion());
            $this->assertIsBool($serverInformation->isPublicAuditLogs());
            $this->assertIsBool($serverInformation->isPublicEvidence());
            $this->assertIsBool($serverInformation->isPublicBlacklist());
            $this->assertIsBool($serverInformation->isPublicEntities());
            $this->assertIsArray($serverInformation->getPublicAuditLogsVisibility());
            $this->assertNotEmpty($serverInformation->getPublicAuditLogsVisibility());
            $this->assertIsInt($serverInformation->getAuditLogRecords());
            $this->assertIsInt($serverInformation->getBlacklistRecords());
            $this->assertIsInt($serverInformation->getKnownEntities());
            $this->assertIsInt($serverInformation->getEvidenceRecords());
            $this->assertIsInt($serverInformation->getFileAttachmentRecords());
            $this->assertIsInt($serverInformation->getOperators());
        }

        public function testUnauthorizedCreateOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->createOperator('testOperator unauthorized');
        }

        public function testUnauthorizedDeleteOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->deleteOperator(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedDisableOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->disableOperator(self::FAKE_OPERATOR_UUID);
        }

        public function testUnauthorizedEnableOperator()
        {
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::UNAUTHORIZED->value);
            $this->client->enableOperator(self::FAKE_OPERATOR_UUID);
        }
    }
