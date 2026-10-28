<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Entities;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Helpers\ServerTestCase;

    /**
     * Reputation settled from the daily activity of entities (OFD "Reputation"), through the TestPlugin route that
     * settles the recorded activity including the current day. A day of activity is settled once, so every test uses
     * entities of its own. The server is assumed to use its default configuration: a gain of 10 per active day, a loss of
     * 5 for a day with content that reached the block threshold (80), nothing for a day that warranted caution (60),
     * and mentions by 3 distinct authors.
     */
    class ReputationSettlementTest extends ServerTestCase
    {
        private const int GAIN = 10;
        private const int LOSS = -5;
        private const string SAFE_TEXT = 'Hello, are we still meeting for lunch tomorrow at noon? test_plugin_safe';

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
         * Scans content of an author as the root operator and returns the decoded response
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
         * Scans content that never warrants caution
         */
        private function scanSafe(string $text, ?string $author, ?string $token = null): void
        {
            $body = $this->scan($text . ' test_plugin_safe', $author, $token);
            $this->assertEquals(0.0, $body['risk_score'], 'The TestPlugin should fix the risk score of the content at the minimum');
        }

        /**
         * Scans content that always reaches the block threshold
         */
        private function scanBlocked(string $text, ?string $author): void
        {
            $body = $this->scan($text . ' test_plugin_block', $author);
            $this->assertEquals(100.0, $body['risk_score'], 'The TestPlugin should fix the risk score of the content at the maximum');
        }

        /**
         * Settles the recorded activity, including that of the current day
         *
         * @return array{days: int, gains: int, losses: int}
         */
        private function settle(?string $token = null): array
        {
            [$code, $body] = $this->jsonRequest('POST', '/test-plugin/settle-reputation', $token ?? $this->rootToken());
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
            [$code] = $this->rawRequest('POST', '/test-plugin/settle-reputation');
            $this->assertSame(401, $code, 'An anonymous request should not settle reputation');

            foreach([
                'client' => $this->createLimitedOperator('settle_client', client: true),
                'management' => $this->createLimitedOperator('settle_management', management: true),
                'operator' => $this->createLimitedOperator('settle_operator', operator: true),
            ] as $label => $operator)
            {
                [$code] = $this->rawRequest('POST', '/test-plugin/settle-reputation', $operator->getAccessToken());
                $this->assertSame(403, $code, "An operator with only $label permissions should not settle reputation");
            }

            $this->settle($this->createLimitedOperator('settle_both', management: true, operator: true)->getAccessToken());
        }

        public function testNoChangeBeforeSettlement(): void
        {
            $authorUuid = $this->createAuthor();
            $this->scanSafe(self::SAFE_TEXT, $authorUuid);

            // Reputation is not adjusted per message
            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testAuthorGainsOncePerActiveDay(): void
        {
            $authorUuid = $this->createAuthor();
            for($i = 0; $i < 5; $i++)
            {
                $this->scanSafe('Message #' . $i, $authorUuid);
            }

            $this->settle();

            // Five messages on one day earn the gain of one day
            $this->assertSame(self::GAIN, $this->getReputation($authorUuid));
        }

        public function testDayIsNeverSettledTwice(): void
        {
            $authorUuid = $this->createAuthor();
            $this->scanSafe('Message', $authorUuid);

            $this->settle();
            $this->assertSame(self::GAIN, $this->getReputation($authorUuid));

            $this->settle();
            $this->assertSame(self::GAIN, $this->getReputation($authorUuid));
        }

        public function testAnonymousScansDoNotAffectReputation(): void
        {
            $authorUuid = $this->createAuthor();

            // Content scanning is public by default
            [$code, $body] = $this->jsonRequest('POST', '/scan', null, ['evidence' => ['text_content' => self::SAFE_TEXT], 'author' => $authorUuid]);
            $this->assertSame(200, $code, json_encode($body));
            $this->assertEquals(0.0, $body['risk_score']);

            $this->settle();
            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testScansWithoutClientPermissionsDoNotAffectReputation(): void
        {
            $authorUuid = $this->createAuthor();
            $operator = $this->createLimitedOperator('no_client', management: true, operator: true);

            // The scan itself is public, it is only not recorded
            $this->scanSafe('Message', $authorUuid, $operator->getAccessToken());

            $this->settle();
            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testMentionedEntityGainsFromSeveralAuthors(): void
        {
            // A popular site mentioned in ordinary messages, eg; a video site
            $host = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->createEntity($host);

            $authors = [];
            for($i = 0; $i < 3; $i++)
            {
                $authorUuid = $this->createAuthor();
                $authors[] = $authorUuid;
                $this->scanSafe('Have you seen this video on ' . $host . ' yet?', $authorUuid);
            }

            $this->settle();

            $this->assertSame(self::GAIN, $this->getReputation($mentionedUuid));
            foreach($authors as $authorUuid)
            {
                $this->assertSame(self::GAIN, $this->getReputation($authorUuid));
            }
        }

        public function testMentionsInFlaggedContentDoNotCount(): void
        {
            $host = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->createEntity($host);

            for($i = 0; $i < 3; $i++)
            {
                $this->scanBlocked('Have you seen this video on ' . $host . ' yet?', $this->createAuthor());
            }

            $this->settle();

            // Being mentioned in flagged content holds back the gain, it never costs the mentioned entity reputation
            $this->assertSame(0, $this->getReputation($mentionedUuid));
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
                $this->scanSafe('Check out ' . $host . ' #' . $i, $first);
            }
            $this->scanSafe('Check out ' . $host, $second);

            $this->settle();

            $this->assertSame(0, $this->getReputation($mentionedUuid));
            $this->assertSame(self::GAIN, $this->getReputation($first));
            $this->assertSame(self::GAIN, $this->getReputation($second));
        }

        public function testMentionsWithoutAuthorDoNotCount(): void
        {
            $host = $this->uniqueHost('mentioned');
            $mentionedUuid = $this->createEntity($host);

            for($i = 0; $i < 3; $i++)
            {
                $this->scanSafe('Check out ' . $host . ' #' . $i, null);
            }

            $this->settle();

            $this->assertSame(0, $this->getReputation($mentionedUuid));
        }

        public function testAuthorDoesNotCountAsItsOwnMention(): void
        {
            $host = $this->uniqueHost('self');
            $authorUuid = $this->createEntity($host);

            // Recorded as a mention, the author's own scan would be replaced by a mention of a single author, which
            // earns nothing
            $this->scanSafe('Visit my site ' . $host, $host);
            $this->settle();

            $this->assertSame(self::GAIN, $this->getReputation($authorUuid));
        }

        public function testBlacklistedAuthorDoesNotGain(): void
        {
            $authorUuid = $this->createAuthor();
            $this->createdBlacklistRecords[] = $this->client->blacklistEntity($authorUuid, null, IncidentType::SPAM, time() + 3600);
            $afterBlacklist = $this->getReputation($authorUuid);

            $this->scanSafe('Message', $authorUuid);
            $this->settle();

            // Normal content of an author under an active blacklist holds the day back
            $this->assertSame($afterBlacklist, $this->getReputation($authorUuid));
        }

        public function testBlockedContentCostsReputation(): void
        {
            $authorUuid = $this->createAuthor();

            $this->scanBlocked('Message', $authorUuid);
            $this->scanBlocked('Message again', $authorUuid);
            $this->scanSafe('An ordinary message the same day', $authorUuid);
            $this->settle();

            // One loss for the day, not one per message, and the ordinary message does not offset it
            $this->assertSame(self::LOSS, $this->getReputation($authorUuid));
        }

        public function testChildCreditsItsParent(): void
        {
            $parentHost = $this->uniqueHost('parent');
            $parentUuid = $this->createEntity($parentHost);
            $childUuid = $this->createEntity('shop.' . $parentHost);

            // Subdomain entities are linked to their registrable domain by default
            $this->assertSame($parentUuid, $this->client->getEntityRecord($childUuid)->getRelationshipEntity());

            $this->scanSafe('Message', 'shop.' . $parentHost);
            $this->settle();

            $this->assertSame(self::GAIN, $this->getReputation($childUuid));
            $this->assertSame(self::GAIN, $this->getReputation($parentUuid));
        }

        public function testParentNeverLosesForItsChild(): void
        {
            $parentHost = $this->uniqueHost('parent');
            $parentUuid = $this->createEntity($parentHost);
            $childUuid = $this->createEntity('shop.' . $parentHost);
            $this->assertSame($parentUuid, $this->client->getEntityRecord($childUuid)->getRelationshipEntity());

            $this->scanBlocked('Message', 'shop.' . $parentHost);
            $this->settle();

            $this->assertSame(self::LOSS, $this->getReputation($childUuid));
            $this->assertSame(0, $this->getReputation($parentUuid));
        }

        public function testActivityRecordedAfterASettlementIsSettledByTheNext(): void
        {
            // With Redis enabled (the default of the docker image) scans are buffered and written before each settlement
            $this->settle();

            $authorUuid = $this->createAuthor();
            $this->scanSafe('Message', $authorUuid);
            $this->assertSame(0, $this->getReputation($authorUuid));

            $this->settle();
            $this->assertSame(self::GAIN, $this->getReputation($authorUuid));
        }

        public function testManyAuthorsInOneSettlement(): void
        {
            $authors = [];
            for($i = 0; $i < 10; $i++)
            {
                $authorUuid = $this->createAuthor();
                $authors[] = $authorUuid;
                $this->scanSafe('Message #' . $i, $authorUuid);
            }

            $this->settle();

            foreach($authors as $authorUuid)
            {
                $this->assertSame(self::GAIN, $this->getReputation($authorUuid), $authorUuid);
            }
        }

        public function testClearedReputationDiscardsUnsettledActivity(): void
        {
            $authorUuid = $this->createAuthor();
            $this->scanSafe('Message', $authorUuid);

            $this->client->clearEntityReputation($authorUuid);
            $this->settle();

            $this->assertSame(0, $this->getReputation($authorUuid));
        }

        public function testSettlementResultCountsAdjustments(): void
        {
            // Nothing recorded before this test is left over
            $this->settle();

            $this->scanSafe('Message', $this->createAuthor());
            $this->scanSafe('Message', $this->createAuthor());
            $this->scanBlocked('Message', $this->createAuthor());

            $this->assertSame(['days' => 1, 'gains' => 2, 'losses' => 1], $this->settle());

            // Nothing is left to settle
            $this->assertSame(['days' => 0, 'gains' => 0, 'losses' => 0], $this->settle());
        }
    }
