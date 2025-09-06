<?php

    namespace FederationLib;

    use PHPUnit\Framework\TestCase;

    class BlacklistClientTest extends TestCase
    {
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdBlacklistRecords = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }

        protected function tearDown(): void
        {
        }
    }