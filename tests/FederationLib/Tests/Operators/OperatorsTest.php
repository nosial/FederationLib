<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class OperatorsTest extends TestCase
    {
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
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
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
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
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
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
            $this->tempFiles = [];
        }

        public function testCreateOperatorNoPermissions(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->assertNotEmpty($operatorUuid);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertFalse($operatorRecord->hasManagementPermissions());
            $this->assertFalse($operatorRecord->hasOperatorPermissions());
            $this->assertFalse($operatorRecord->hasClientPermissions());
            $this->assertNotEmpty($operatorRecord->getAccessToken());
        }

        public function testCreateOperatorWithManagementPermission(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->hasManagementPermissions());
            $this->assertFalse($operatorRecord->hasOperatorPermissions());
            $this->assertFalse($operatorRecord->hasClientPermissions());
        }

        public function testCreateOperatorWithOperatorPermission(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setOperatorPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->hasManagementPermissions());
            $this->assertTrue($operatorRecord->hasOperatorPermissions());
            $this->assertFalse($operatorRecord->hasClientPermissions());
        }

        public function testCreateOperatorWithClientPermission(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setClientPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertFalse($operatorRecord->hasManagementPermissions());
            $this->assertFalse($operatorRecord->hasOperatorPermissions());
            $this->assertTrue($operatorRecord->hasClientPermissions());
        }

        public function testCreateOperatorWithAllPermissions(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->createdOperators[] = $operatorUuid;

            $this->client->setManagementPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setClientPermissions($operatorUuid, true);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertTrue($operatorRecord->hasManagementPermissions());
            $this->assertTrue($operatorRecord->hasOperatorPermissions());
            $this->assertTrue($operatorRecord->hasClientPermissions());
        }

        public function testDeleteOperator(): void
        {
            $name = uniqid('test operator');
            $operatorUuid = $this->client->createOperator($name);
            $this->assertNotEmpty($operatorUuid);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertNotNull($operatorRecord);
            $this->assertEquals($name, $operatorRecord->getName());

            $this->client->deleteOperator($operatorUuid);

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getOperator($operatorUuid);
        }

        public function testCreateInvalidOperatorName(): void
        {
            $name = str_repeat('a', 256);
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->createOperator($name);
        }

        public function testDeleteNonExistentOperator(): void
        {
            $nonExistentOperatorUuid = Uuid::v7()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->deleteOperator($nonExistentOperatorUuid);
        }

        public function testGetNonExistentOperator(): void
        {
            $nonExistentOperatorUuid = Uuid::v7()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->getOperator($nonExistentOperatorUuid);
        }

        public function testListOperators(): void
        {
            $createdOperators = [];
            for ($i = 0; $i < 10; $i++)
            {
                $name = uniqid('test operator');
                $operatorUuid = $this->client->createOperator($name);
                $this->createdOperators[] = $operatorUuid;
                $createdOperators[] = $operatorUuid;
            }

            $operators = $this->client->listOperators();
            $this->assertNotNull($operators);
            $this->assertGreaterThanOrEqual(10, count($operators));

            foreach ($operators as $operator)
            {
                $this->assertNotEmpty($operator->getUuid());
                $this->assertNotEmpty($operator->getName());
            }

            $found = false;
            $currentPage = 1;
            while (true)
            {
                $currentPageResults = $this->client->listOperators(page: $currentPage);
                if (count($currentPageResults) === 0)
                {
                    break;
                }

                foreach ($currentPageResults as $operator)
                {
                    if (in_array($operator->getUuid(), $createdOperators, true))
                    {
                        $found = true;
                        break 2;
                    }
                }

                $currentPage++;
            }

            $this->assertTrue($found, 'No created operators were found in the database');
        }
    }
