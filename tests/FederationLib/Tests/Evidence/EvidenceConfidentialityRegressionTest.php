<?php

namespace FederationLib\Tests\Evidence;

use FederationLib\Enums\IncidentType;
use FederationLib\FederationClient;
use FederationLib\Helpers\TestHelpers;
use FederationLib\Objects\ContentInput;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the installed server and its real managers through HTTP, including competing requests.
 * No extracted SQL, replacement schemas, Python runtime, or separately named Docker container.
 */
class EvidenceConfidentialityRegressionTest extends TestCase
{
    use TestHelpers;

    private FederationClient $client;
    private FederationClient $limitedClient;
    private array $createdOperators = [];
    private array $createdEntities = [];
    private array $createdEvidenceRecords = [];
    private array $createdBlacklistRecords = [];
    private array $createdReports = [];
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        self::assertTrue($this->client->getServerInformation()->isAllowIllegalContent(),
            'The integration environment must enable FEDERATION_ALLOW_ILLEGAL_CONTENT.');
        $this->limitedClient = $this->createLimitedOperator('confidentiality', client: true);
    }

    protected function tearDown(): void
    {
        $this->cleanupRecords();
    }

    private function report(string $entity, IncidentType $type): string
    {
        $submission = $this->client->submitReport($entity,
            new ContentInput('Synthetic confidentiality regression fixture'), $type);
        $uuid = $submission->getReport()->getUuid();
        $this->createdReports[] = $uuid;
        foreach($submission->getEvidence() as $evidence)
        {
            $this->createdEvidenceRecords[] = $evidence->getUuid();
        }
        return $uuid;
    }

    private function assertProtected(string $evidence, string $report): void
    {
        // A new client performs a separate read after the writes have completed.
        $reader = new FederationClient(getenv('SERVER_ENDPOINT'), $this->rootToken());
        $record = $reader->getEvidenceRecord($evidence);
        self::assertSame($report, $record->getReport());
        self::assertTrue($record->isConfidential());
        [$status] = $this->jsonRequest('GET', '/evidence/' . $evidence, $this->limitedClient->getAccessToken());
        self::assertSame(403, $status, 'Client-only operators must not see confidential evidence');
    }

    public function testLinkingPublicEvidenceMakesItConfidential(): void
    {
        $entity = $this->createSecurityEntity();
        $evidence = $this->createSecurityEvidence($entity);
        $report = $this->report($entity, IncidentType::ILLEGAL_CONTENT);
        self::assertFalse($this->client->getEvidenceRecord($evidence)->isConfidential());
        $this->client->addEvidenceToReport($evidence, $report);
        $this->assertProtected($evidence, $report);
    }

    public function testDeclassificationBeforeLinkingCannotLeaveIllegalContentPublic(): void
    {
        $entity = $this->createSecurityEntity();
        $evidence = $this->createSecurityEvidence($entity, true);
        $ordinary = $this->report($entity, IncidentType::OTHER);
        $illegal = $this->report($entity, IncidentType::ILLEGAL_CONTENT);
        $this->client->addEvidenceToReport($evidence, $ordinary);
        $this->client->updateEvidenceConfidentiality($evidence, false);
        self::assertFalse($this->client->getEvidenceRecord($evidence)->isConfidential());
        $this->client->addEvidenceToReport($evidence, $illegal);
        $this->assertProtected($evidence, $illegal);
    }

    public function testDeclassificationAfterLinkingIsRejectedAndPreservesConfidentiality(): void
    {
        $entity = $this->createSecurityEntity();
        $evidence = $this->createSecurityEvidence($entity);
        $report = $this->report($entity, IncidentType::ILLEGAL_CONTENT);
        $this->client->addEvidenceToReport($evidence, $report);
        $this->expectRequestFailure(fn() => $this->client->updateEvidenceConfidentiality($evidence, false), [409]);
        $this->assertProtected($evidence, $report);
        // Retaining confidentiality remains a valid operation.
        $this->client->updateEvidenceConfidentiality($evidence, true);
        $this->assertProtected($evidence, $report);
    }

    public function testConcurrentLinkAndDeclassificationPreserveConfidentiality(): void
    {
        $entity = $this->createSecurityEntity();
        $ordinary = $this->report($entity, IncidentType::OTHER);
        $illegal = $this->report($entity, IncidentType::ILLEGAL_CONTENT);
        // Alternate submission order. The HTTP scheduler decides actual lock acquisition order;
        // both allowed outcomes of declassification must leave the committed evidence private.
        for($iteration = 0; $iteration < 6; $iteration++)
        {
            $evidence = $this->createSecurityEvidence($entity, true);
            $this->client->addEvidenceToReport($evidence, $ordinary);
            $requests = [
                'link' => ['method' => 'PATCH', 'path' => '/evidence/' . $evidence . '/link-report',
                    'token' => $this->rootToken(), 'body' => json_encode(['report_uuid' => $illegal])],
                'declassify' => ['method' => 'PATCH', 'path' => '/evidence/' . $evidence . '/update-confidentiality',
                    'token' => $this->rootToken(), 'body' => json_encode(['confidential' => false])],
            ];
            if($iteration % 2 !== 0)
            {
                $requests = array_reverse($requests, true);
            }
            $results = $this->concurrentRawRequests($requests);
            self::assertSame(200, $results['link'][0], $results['link'][1]);
            self::assertContains($results['declassify'][0], [200, 409], $results['declassify'][1]);
            $this->assertProtected($evidence, $illegal);
        }
    }

    public function testMalformedConfidentialityUpdatesCannotExposeEvidence(): void
    {
        $entity = $this->createSecurityEntity();
        $evidence = $this->createSecurityEvidence($entity, true);
        foreach(['{}', '{"confidential":null}', '{"confidential":"invalid"}', '{"confidential":[]}',
            '{"confidential":42}', 'null', '[]', '{'] as $body)
        {
            [$status, $response] = $this->rawRequest('PATCH', '/evidence/' . $evidence . '/update-confidentiality',
                $this->rootToken(), $body);
            self::assertSame(400, $status, $response);
            self::assertTrue($this->client->getEvidenceRecord($evidence)->isConfidential(), $body);
        }
    }
}
