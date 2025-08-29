<?php

    namespace FederationLib;

    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
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
    }
