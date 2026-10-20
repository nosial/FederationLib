<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Entities;

    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    /**
     * Reputation settled from the daily activity of entities (OFD "Reputation"), through the TestPlugin route that
     * settles the recorded activity including the current day. A day of activity is settled once, so every test uses
     * entities of its own. The server is assumed to use its default configuration: a gain of 10 per active day, a loss of
     * 5 for a day with content that reached the block threshold (80), nothing for a day that warranted caution (60),
     * and mentions by 3 distinct authors.
     */
    class ReputationSettlementTest extends TestCase
    {
        use TestHelpers;

        private const int GAIN = 10;
        private const int LOSS = -5;
        private const float CAUTION_THRESHOLD = 60.0;
        private const float BLOCK_THRESHOLD = 80.0;
        private const string NORMAL_TEXT = 'Hello, are we still meeting for lunch tomorrow at noon?';

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
            $this->cleanupRecords();
        }

        private function createEntity(string $host, ?string $id = null): string
        {
            $entityUuid = $this->client->pushEntity($host, $id);
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        private function createAuthor(): string
        {
            return $this->createEntity($this->uniqueHost('author'), 'user_' . bin2hex(random_bytes(4)));
        }

        /**
         * Scans content of an author and returns the decoded response
         */
        private function scan(string $text, ?string $author, ?string $token = null): array
        {
            [$code, $body] = $this->jsonRequest('POST', '/scan', $token ?? $this->rootToken(), array_filter([
                'evidence' => ['text_content' => $text],
                'author' => $author,
            ], fn($value) => $value !== null));

            $this->assertSame(200, $code, json_encode($body));
            return $body;
        }

        /**
         * Returns the reputation change a day of the author's content with the given risk score settles into
         */
        private function expectedAuthorDelta(float $riskScore): int
        {
            if($riskScore >= self::BLOCK_THRESHOLD)
            {
                return self::LOSS;
            }

            return $riskScore >= self::CAUTION_THRESHOLD ? 0 : self::GAIN;
        }

        /**
         * Settles the recorded activity, including that of the current day
         *
         * @return array{days: int, gains: int, losses: int}
         */
        private function settle(): array
        {
            [$code, $body] = $this->jsonRequest('POST', '/test-plugin/settle-reputation', $this->rootToken());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertArrayHasKey('days', $body);
            $this->assertArrayHasKey('gains', $body);
            $this->assertArrayHasKey('losses', $body);
            return $body;
        }

        private function getReputation(string $entityUuid): int
        {
            return $this->client->getEntityRecord($entityUuid)->getReputation();
        }

        public function testSettleRouteRequiresManagementAndOperatorPermissions(): void
        {
            $client = $this->createLimitedOperator('settle_client', client: true);
            [$code] = $this->rawRequest('POST', '/test-plugin/settle-reputation', $client->getAccessToken());
            $this->assertSame(403, $code);

            [$code] = $this->rawRequest('POST', '/test-plugin/settle-reputation');
            $this->assertSame(401, $code);
        }

        public function testNoChangeBeforeSettlement(): void
        {
            $authorUuid = $this->createAuthor();
            $this->scan(self::NORMAL_TEXT, $authorUuid);

            // Reputation is not adjusted per message
            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testAuthorGainsOncePerActiveDay(): void
        {
            $authorUuid = $this->createAuthor();

            $riskScores = [];
            for($i = 0; $i < 5; $i++)
            {
                $riskScores[] = $this->scan(self::NORMAL_TEXT . ' #' . $i, $authorUuid)['risk_score'];
            }

            $this->settle();

            // Five messages on one day earn the gain of one day, the worst content of the day decides
            $this->assertSame($this->expectedAuthorDelta(max($riskScores)), $this->getReputation($authorUuid));
        }

        public function testDayIsNeverSettledTwice(): void
        {
            $authorUuid = $this->createAuthor();
            $riskScore = $this->scan(self::NORMAL_TEXT, $authorUuid)['risk_score'];

            $this->settle();
            $afterFirst = $this->getReputation($authorUuid);
            $this->assertSame($this->expectedAuthorDelta($riskScore), $afterFirst);

            $this->settle();
            $this->assertSame($afterFirst, $this->getReputation($authorUuid));
        }

        public function testAnonymousScansDoNotAffectReputation(): void
        {
            $authorUuid = $this->createAuthor();

            // Content scanning is public by default
            [$code, $body] = $this->jsonRequest('POST', '/scan', null, ['evidence' => ['text_content' => self::NORMAL_TEXT], 'author' => $authorUuid]);
            $this->assertSame(200, $code, json_encode($body));

            $this->settle();
            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testScansWithoutClientPermissionsDoNotAffectReputation(): void
        {
            $authorUuid = $this->createAuthor();
            $operator = $this->createLimitedOperator('no_client', operator: true);

            $this->jsonRequest('POST', '/scan', $operator->getAccessToken(), ['evidence' => ['text_content' => self::NORMAL_TEXT], 'author' => $authorUuid]);

            $this->settle();
            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testMentionedEntityGainsFromSeveralAuthors(): void
        {
            // A popular site mentioned in ordinary messages, eg; a video site
            $host = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->createEntity($host);

            $authors = [];
            $flagged = false;
            for($i = 0; $i < 3; $i++)
            {
                $authorUuid = $this->createAuthor();
                $authors[] = $authorUuid;
                $riskScore = $this->scan('Have you seen this video on ' . $host . ' yet?', $authorUuid)['risk_score'];
                $flagged = $flagged || $riskScore >= self::CAUTION_THRESHOLD;
            }

            $this->settle();

            $this->assertSame($flagged ? 0 : self::GAIN, $this->getReputation($mentionedUuid));
        }

        public function testMentionsByTooFewAuthorsDoNotCount(): void
        {
            $host = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->createEntity($host);

            // Two distinct authors, one of them mentioning it repeatedly
            $first = $this->createAuthor();
            $second = $this->createAuthor();
            for($i = 0; $i < 3; $i++)
            {
                $this->scan('Check out ' . $host . ' #' . $i, $first);
            }
            $this->scan('Check out ' . $host, $second);

            $this->settle();

            $this->assertSame(0, $this->getReputation($mentionedUuid));
        }

        public function testMentionsWithoutAuthorDoNotCount(): void
        {
            $host = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->createEntity($host);

            for($i = 0; $i < 3; $i++)
            {
                $this->scan('Check out ' . $host . ' #' . $i, null);
            }

            $this->settle();

            $this->assertSame(0, $this->getReputation($mentionedUuid));
        }

        public function testAuthorDoesNotGainAsItsOwnMention(): void
        {
            $host = $this->uniqueHost('self');
            $authorUuid = $this->createEntity($host);

            $riskScore = $this->scan('Visit my site ' . $host, $host)['risk_score'];
            $this->settle();

            // Only the gain of the author's day, never an additional one for mentioning itself
            $this->assertSame($this->expectedAuthorDelta($riskScore), $this->getReputation($authorUuid));
        }

        public function testBlacklistedAuthorDoesNotGain(): void
        {
            $authorUuid = $this->createAuthor();
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($authorUuid, null, IncidentType::SPAM, time() + 3600);
            $afterBlacklist = $this->getReputation($authorUuid);

            $riskScore = $this->scan(self::NORMAL_TEXT, $authorUuid)['risk_score'];
            $this->settle();

            // The day is held back, or costs reputation if the content reached the block threshold
            $expected = $riskScore >= self::BLOCK_THRESHOLD ? self::LOSS : 0;
            $this->assertSame($afterBlacklist + $expected, $this->getReputation($authorUuid));
        }

        public function testBlockedContentCostsReputation(): void
        {
            $authorUuid = $this->createAuthor();
            // A permanently blacklisted author's content reaches the block threshold (see AutoAssignTest)
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($authorUuid, null, IncidentType::MALWARE, null);
            $before = $this->getReputation($authorUuid);

            $riskScore = $this->scan(self::NORMAL_TEXT, $authorUuid)['risk_score'];
            if($riskScore < self::BLOCK_THRESHOLD)
            {
                $this->markTestSkipped(sprintf('The content did not reach the block threshold (risk score %.2f)', $riskScore));
            }

            $this->scan(self::NORMAL_TEXT . ' again', $authorUuid);
            $this->settle();

            // One loss for the day, not one per message
            $this->assertSame($before + self::LOSS, $this->getReputation($authorUuid));
        }

        public function testChildCreditsItsParent(): void
        {
            $parentHost = $this->uniqueHost('parent');
            $parentUuid = $this->createEntity($parentHost);
            $childUuid = $this->createEntity('shop.' . $parentHost);

            // Subdomain entities are linked to their registrable domain by default
            $this->assertSame($parentUuid, $this->client->getEntityRecord($childUuid)->getRelationshipEntity());

            $riskScore = $this->scan(self::NORMAL_TEXT, 'shop.' . $parentHost)['risk_score'];
            $this->settle();

            $expected = $this->expectedAuthorDelta($riskScore);
            $this->assertSame($expected, $this->getReputation($childUuid));
            // The parent gains with its child, it never loses for it
            $this->assertSame(max(0, $expected), $this->getReputation($parentUuid));
        }

        public function testActivityRecordedAfterASettlementIsSettledByTheNext(): void
        {
            // With Redis enabled (the default of the docker image) scans are buffered and written before each settlement
            $this->settle();

            $authorUuid = $this->createAuthor();
            $riskScore = $this->scan(self::NORMAL_TEXT, $authorUuid)['risk_score'];
            $this->assertSame(0, $this->getReputation($authorUuid));

            $this->settle();
            $this->assertSame($this->expectedAuthorDelta($riskScore), $this->getReputation($authorUuid));
        }

        public function testManyAuthorsInOneSettlement(): void
        {
            $authors = [];
            for($i = 0; $i < 10; $i++)
            {
                $authorUuid = $this->createAuthor();
                $authors[$authorUuid] = $this->scan(self::NORMAL_TEXT . ' #' . $i, $authorUuid)['risk_score'];
            }

            $this->settle();

            foreach($authors as $authorUuid => $riskScore)
            {
                $this->assertSame($this->expectedAuthorDelta($riskScore), $this->getReputation($authorUuid), $authorUuid);
            }
        }

        public function testClearedReputationDiscardsUnsettledActivity(): void
        {
            $authorUuid = $this->createAuthor();
            $this->scan(self::NORMAL_TEXT, $authorUuid);

            $this->client->clearEntityReputation($authorUuid);
            $this->settle();

            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testSettlementResultCountsAdjustments(): void
        {
            $authorUuid = $this->createAuthor();
            $riskScore = $this->scan(self::NORMAL_TEXT, $authorUuid)['risk_score'];

            $result = $this->settle();

            $this->assertGreaterThanOrEqual(1, $result['days']);
            if($this->expectedAuthorDelta($riskScore) > 0)
            {
                $this->assertGreaterThanOrEqual(1, $result['gains']);
            }

            // Nothing is left to settle
            $this->assertSame(['days' => 0, 'gains' => 0, 'losses' => 0], $this->settle());
        }
    }
