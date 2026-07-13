<?php

    namespace FederationLib\FederationServer;

    use FederationLib\Classes\Utilities;
    use PHPUnit\Framework\TestCase;

    class UtilitiesTest extends TestCase
    {
        public function testGenerateStringDefaultLength(): void
        {
            $result = Utilities::generateString();
            $this->assertEquals(32, strlen($result));
        }

        public function testGenerateStringCustomLength(): void
        {
            $this->assertEquals(0, strlen(Utilities::generateString(0)));
            $this->assertEquals(1, strlen(Utilities::generateString(1)));
            $this->assertEquals(8, strlen(Utilities::generateString(8)));
            $this->assertEquals(64, strlen(Utilities::generateString(64)));
            $this->assertEquals(128, strlen(Utilities::generateString(128)));
        }

        public function testGenerateStringOnlyAlphanumeric(): void
        {
            $result = Utilities::generateString(1000);
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $result);
        }

        public function testGenerateStringRandomness(): void
        {
            $results = [];
            for ($i = 0; $i < 100; $i++)
            {
                $results[] = Utilities::generateString();
            }
            $this->assertCount(100, array_unique($results));
        }

        public function testIsSha256Valid(): void
        {
            $this->assertTrue(Utilities::isSha256('abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890'));
            $this->assertTrue(Utilities::isSha256('0000000000000000000000000000000000000000000000000000000000000000'));
            $this->assertTrue(Utilities::isSha256('ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'));
        }

        public function testIsSha256CaseInsensitive(): void
        {
            $hash = 'ABCDEF1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
            $this->assertTrue(Utilities::isSha256($hash));
            $this->assertTrue(Utilities::isSha256(strtolower($hash)));
            $this->assertTrue(Utilities::isSha256(strtoupper($hash)));
        }

        public function testIsSha256InvalidLength(): void
        {
            $this->assertFalse(Utilities::isSha256(''));
            $this->assertFalse(Utilities::isSha256('abc'));
            $this->assertFalse(Utilities::isSha256(str_repeat('a', 63)));
            $this->assertFalse(Utilities::isSha256(str_repeat('a', 65)));
        }

        public function testIsSha256InvalidCharacters(): void
        {
            $this->assertFalse(Utilities::isSha256('gggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggg'));
            $this->assertFalse(Utilities::isSha256('abcdef1234567890abcdef1234567890abcdef1234567890abcdef123456789!'));
        }

        public function testIsSha256Empty(): void
        {
            $this->assertFalse(Utilities::isSha256(''));
        }

        public function testIsUuidValidV4(): void
        {
            $this->assertTrue(Utilities::isUuid('550e8400-e29b-41d4-a716-446655440000'));
            $this->assertTrue(Utilities::isUuid('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
            $this->assertTrue(Utilities::isUuid('12345678-1234-4abc-8abc-123456789abc'));
        }

        public function testIsUuidInvalidFormat(): void
        {
            $this->assertFalse(Utilities::isUuid(''));
            $this->assertFalse(Utilities::isUuid('not-a-uuid'));
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-41d4-a716-44665544000'));   // too short
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-41d4-a716-4466554400000'));  // too long
            $this->assertFalse(Utilities::isUuid('550e8400e29b41d4a716446655440000'));       // no dashes
        }

        public function testIsUuidInvalidVersion(): void
        {
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-11d4-a716-446655440000')); // v1, not v4
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-21d4-a716-446655440000')); // v2, not v4
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-31d4-a716-446655440000')); // v3, not v4
        }

        public function testIsUuidInvalidVariant(): void
        {
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-41d4-c716-446655440000')); // variant digit 'c' not in [89ab]
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-41d4-d716-446655440000')); // variant digit 'd' not in [89ab]
            $this->assertFalse(Utilities::isUuid('550e8400-e29b-41d4-e716-446655440000')); // variant digit 'e' not in [89ab]
        }

        public function testIsEntityAddressValid(): void
        {
            $this->assertTrue(Utilities::isEntityAddress('user@example.com'));
            $this->assertTrue(Utilities::isEntityAddress('test.user@sub.domain.co.uk'));
            $this->assertTrue(Utilities::isEntityAddress('user+tag@example.org'));
            $this->assertTrue(Utilities::isEntityAddress('a@b.cd'));
        }

        public function testIsEntityAddressInvalid(): void
        {
            $this->assertFalse(Utilities::isEntityAddress(''));
            $this->assertFalse(Utilities::isEntityAddress('not-an-email'));
            $this->assertFalse(Utilities::isEntityAddress('@example.com'));
            $this->assertFalse(Utilities::isEntityAddress('user@'));
            $this->assertFalse(Utilities::isEntityAddress('user@.com'));
        }

        public function testHashEntityWithHostOnly(): void
        {
            $expected = hash('sha256', 'example.com');
            $this->assertEquals($expected, Utilities::hashEntity('example.com'));
        }

        public function testHashEntityWithHostAndId(): void
        {
            $expected = hash('sha256', 'user@example.com');
            $this->assertEquals($expected, Utilities::hashEntity('example.com', 'user'));
        }

        public function testHashEntityDeterministic(): void
        {
            $result1 = Utilities::hashEntity('test.com', 'alice');
            $result2 = Utilities::hashEntity('test.com', 'alice');
            $result3 = Utilities::hashEntity('test.com', 'alice');
            $this->assertEquals($result1, $result2);
            $this->assertEquals($result1, $result3);
        }

        public function testHashEntityDifferentInputs(): void
        {
            $this->assertNotEquals(
                Utilities::hashEntity('example.com'),
                Utilities::hashEntity('example.org')
            );
            $this->assertNotEquals(
                Utilities::hashEntity('example.com', 'alice'),
                Utilities::hashEntity('example.com', 'bob')
            );
            $this->assertNotEquals(
                Utilities::hashEntity('example.com'),
                Utilities::hashEntity('example.com', 'alice')
            );
        }

        public function testParseEntityAddressValid(): void
        {
            $result = Utilities::parseEntityAddress('user@example.com');
            $this->assertNotNull($result);
            $this->assertEquals(['host' => 'example.com', 'id' => 'user'], $result);
        }

        public function testParseEntityAddressComplex(): void
        {
            $result = Utilities::parseEntityAddress('test.user+tag@sub.domain.co.uk');
            $this->assertNotNull($result);
            $this->assertEquals('sub.domain.co.uk', $result['host']);
            $this->assertEquals('test.user+tag', $result['id']);
        }

        public function testParseEntityAddressReturnsNullForInvalid(): void
        {
            $this->assertNull(Utilities::parseEntityAddress(''));
            $this->assertNull(Utilities::parseEntityAddress('not-valid'));
            $this->assertNull(Utilities::parseEntityAddress('@example.com'));
        }
    }
