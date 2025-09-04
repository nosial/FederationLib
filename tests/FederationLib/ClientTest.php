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
    }
