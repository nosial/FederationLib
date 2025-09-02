<?php

    namespace FederationLib;

    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Entity;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EvidenceRecord;
    use PHPUnit\Framework\TestCase;

    class EntitiesTest extends TestCase
    {
        private FederationClient $client;
        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_API_KEY'));
        }


    }
