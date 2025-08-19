<?php

    namespace FederationLib;

    use PHPUnit\Framework\TestCase;

    class FederationClientOperatorsTest extends TestCase
    {
        public function testCreateOperator()
        {
            $federationClient = new FederationClient("http://127.0.0.1:8500/", "abcdefghijklmnopqrstuvwxyz123456");
            $uuid = $federationClient->createOperator('Test Johnny');
            $this->assertNotEmpty($uuid);
        }
    }
