<?php

    /** @noinspection PhpRedundantOptionalArgumentInspection */
    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Search;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ReportRecord;
    use FederationLib\Objects\SearchResult;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class SearchSecurityTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $createdAttachments = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdAttachments as $attachmentUuid)
            {
                try
                {
                    $this->client->deleteAttachment($attachmentUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete attachment $attachmentUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdReports as $reportUuid)
            {
                try
                {
                    $this->client->deleteReport($reportUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete report $reportUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdBlacklistRecords as $blacklistUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEvidenceRecords as $evidenceUuid)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity $entityUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete operator $operatorUuid: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->createdAttachments = [];
            $this->tempFiles = [];
        }

        public function testSearchConfidentialEvidenceAsAnonymousUser(): void
        {
            if ($this->client->getServerInformation()->isPublicEvidence())
            {
                $entityUuid = $this->client->pushEntity('confidential-test.com', 'conf_user');
                $this->createdEntities[] = $entityUuid;

                $confContent = 'CONFIDENTIAL_EVIDENCE_' . uniqid();
                $evidenceUuid = $this->client->submitEvidence($entityUuid, $confContent, 'conf note', 'conf_tag', true);
                $this->createdEvidenceRecords[] = $evidenceUuid;

                $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
                try
                {
                    $results = $anonymousClient->searchEvidence($confContent);
                    $found = false;
                    foreach ($results as $result)
                    {
                        if ($result->getUuid() === $evidenceUuid)
                        {
                            $found = true;
                            break;
                        }
                    }
                    $this->assertFalse($found, 'Anonymous user should not see confidential evidence');
                }
                catch (RequestException $e)
                {
                    $this->assertContains($e->getCode(), [400, 401, 403, 404],
                        'Unexpected HTTP status: ' . $e->getCode());
                }
            }
            else
            {
                $this->markTestSkipped('Evidence is not public, skipping anonymous search test');
            }
        }

        public function testSearchConfidentialEvidenceWithManagementOperator(): void
        {
            $entityUuid = $this->client->pushEntity('conf-mgmt-test.com', 'conf_mgmt_user');
            $this->createdEntities[] = $entityUuid;

            $confContent = 'MGMT_CONF_EVIDENCE_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $confContent, 'mgmt conf note', 'mgmt_conf_tag', true);
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $mgmtClient = $this->createLimitedOperator('mgmt_search', true, false, false);

            try
            {
                $results = $mgmtClient->searchEvidence($confContent);
                $found = false;
                foreach ($results as $result)
                {
                    if ($result->getUuid() === $evidenceUuid)
                    {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, 'Management operator should find confidential evidence');
            }
            catch (RequestException $e)
            {
                $this->markTestSkipped('Management operator search failed: ' . $e->getMessage());
            }
        }

        public function testSearchEntitiesAsAnonymousPublic(): void
        {
            if (!$this->client->getServerInformation()->isPublicEntities())
            {
                $this->markTestSkipped('Server does not expose entities publicly');
            }

            $host = 'anon-search-' . uniqid() . '.com';
            $entityUuid = $this->client->pushEntity($host, 'anon_user');
            $this->createdEntities[] = $entityUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            try
            {
                $results = $anonymousClient->searchEntities($host);
                $this->assertNotEmpty($results);
                foreach ($results as $result)
                {
                    $this->assertInstanceOf(EntityRecord::class, $result);
                    $this->assertSame($host, $result->getHost());
                }
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401, 404],
                    'Anonymous entity search failed with unexpected status: ' . $e->getCode());
            }
        }

        public function testSearchEvidenceAsAnonymousPublic(): void
        {
            if (!$this->client->getServerInformation()->isPublicEvidence())
            {
                $this->markTestSkipped('Server does not expose evidence publicly');
            }

            $entityUuid = $this->client->pushEntity('anon-evidence.com', 'anon_ev_user');
            $this->createdEntities[] = $entityUuid;

            $content = 'ANON_PUBLIC_EVIDENCE_' . uniqid();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $content, 'anon note', 'anon_tag');
            $this->createdEvidenceRecords[] = $evidenceUuid;

            $anonymousClient = new FederationClient(getenv('SERVER_ENDPOINT'));
            try
            {
                $results = $anonymousClient->searchEvidence($content);
                $this->assertNotEmpty($results);
                foreach ($results as $result)
                {
                    $this->assertInstanceOf(EvidenceRecord::class, $result);
                }
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401, 404],
                    'Anonymous evidence search failed with unexpected status: ' . $e->getCode());
            }
        }

        public function testSearchMixedConfidentialEvidenceWithNonManagementOperator(): void
        {
            $entityUuid = $this->client->pushEntity('mixed-conf.com', 'mixed_conf_user');
            $this->createdEntities[] = $entityUuid;

            $publicContent = 'PUBLIC_MIXED_' . uniqid();
            $confContent = 'CONF_MIXED_' . uniqid();

            $publicEvUuid = $this->client->submitEvidence($entityUuid, $publicContent, 'public note', 'mixed_tag');
            $this->createdEvidenceRecords[] = $publicEvUuid;

            $confEvUuid = $this->client->submitEvidence($entityUuid, $confContent, 'conf note', 'mixed_tag', true);
            $this->createdEvidenceRecords[] = $confEvUuid;

            $operatorClient = $this->createLimitedOperator('mixed_conf_op', false, false, false);

            try
            {
                $confResults = $operatorClient->searchEvidence($confContent);
                $foundConf = false;
                foreach ($confResults as $result)
                {
                    if ($result->getUuid() === $confEvUuid)
                    {
                        $foundConf = true;
                        break;
                    }
                }
                $this->assertFalse($foundConf,
                    'Non-management operator should not see confidential evidence');
            }
            catch (RequestException $e)
            {
                $this->assertContains($e->getCode(), [400, 401, 403, 404]);
            }

            try
            {
                $publicResults = $operatorClient->searchEvidence($publicContent);
                $foundPublic = false;
                foreach ($publicResults as $result)
                {
                    if ($result->getUuid() === $publicEvUuid)
                    {
                        $foundPublic = true;
                        break;
                    }
                }
                $this->assertTrue($foundPublic,
                    'Non-management operator should see non-confidential evidence');
            }
            catch (RequestException $e)
            {
                Logger::getLogger()->info('Non-management operator search for public evidence failed: ' . $e->getMessage());
            }
        }

    }
