<?php

    namespace FederationLib;

    use FederationLib\Classes\Utilities;
    use PHPUnit\Framework\TestCase;

    class EntitiesTest extends TestCase
    {
        private FederationClient $client;

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }


        public function testPushEntity()
        {
            $this->client->pushEntity('john', 'example.com');
            $entityHash =
        }
    }