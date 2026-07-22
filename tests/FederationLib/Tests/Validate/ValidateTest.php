<?php

    namespace FederationLib\Tests\Validate;

    use FederationLib\Classes\Validate;
    use PHPUnit\Framework\TestCase;

    class ValidateTest extends TestCase
    {
        public function testUuidValid(): void
        {
            $this->assertTrue(Validate::uuid('550e8400-e29b-41d4-a716-446655440000'));
            $this->assertTrue(Validate::uuid('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
            $this->assertTrue(Validate::uuid('00000000-0000-0000-0000-000000000000'));
            $this->assertTrue(Validate::uuid('ffffffff-ffff-ffff-ffff-ffffffffffff'));
        }

        public function testUuidInvalid(): void
        {
            $this->assertFalse(Validate::uuid(''));
            $this->assertFalse(Validate::uuid('not-a-uuid'));
            $this->assertFalse(Validate::uuid('550e8400-e29b-41d4-a716-44665544000'));
            $this->assertFalse(Validate::uuid('550e8400-e29b-41d4-a716-4466554400000'));
            $this->assertFalse(Validate::uuid('550e8400e29b41d4a716446655440000'));
            $this->assertFalse(Validate::uuid('gggggggg-gggg-gggg-gggg-gggggggggggg'));
        }

        public function testEvidenceTagValid(): void
        {
            $this->assertTrue(Validate::evidenceTag('test'));
            $this->assertTrue(Validate::evidenceTag('my_tag-1'));
            $this->assertTrue(Validate::evidenceTag('a'));
            $this->assertTrue(Validate::evidenceTag(str_repeat('a', 32)));
        }

        public function testEvidenceTagTooLong(): void
        {
            $this->assertFalse(Validate::evidenceTag(str_repeat('a', 33)));
        }

        public function testEvidenceTagInvalidCharacters(): void
        {
            $this->assertFalse(Validate::evidenceTag(''));
            $this->assertFalse(Validate::evidenceTag('tag with spaces'));
            $this->assertFalse(Validate::evidenceTag('tag!'));
            $this->assertFalse(Validate::evidenceTag('tag@domain'));
            $this->assertFalse(Validate::evidenceTag('tag.value'));
        }

        public function testHostValidIpv4(): void
        {
            $this->assertTrue(Validate::host('127.0.0.1'));
            $this->assertTrue(Validate::host('192.168.1.1'));
            $this->assertTrue(Validate::host('8.8.8.8'));
            $this->assertTrue(Validate::host('0.0.0.0'));
        }

        public function testHostValidIpv6(): void
        {
            $this->assertTrue(Validate::host('::1'));
            $this->assertTrue(Validate::host('2001:db8::1'));
            $this->assertTrue(Validate::host('fe80::1'));
        }

        public function testHostValidDomain(): void
        {
            $this->assertTrue(Validate::host('example.com'));
            $this->assertTrue(Validate::host('sub.domain.co.uk'));
            $this->assertTrue(Validate::host('localhost'));
        }

        public function testHostInvalid(): void
        {
            $this->assertFalse(Validate::host(''));
            $this->assertFalse(Validate::host(' '));
            $this->assertFalse(Validate::host('invalid host'));
        }

        public function testDomainValid(): void
        {
            $this->assertTrue(Validate::domain('example.com'));
            $this->assertTrue(Validate::domain('sub.domain.co.uk'));
            $this->assertTrue(Validate::domain('a-b.com'));
            $this->assertTrue(Validate::domain('xn--n8h.com'));
        }

        public function testDomainInvalid(): void
        {
            $this->assertFalse(Validate::domain(''));
            $this->assertFalse(Validate::domain('notadomain'));
            $this->assertFalse(Validate::domain('-leading-hyphen.com'));
            $this->assertFalse(Validate::domain('trailing-hyphen-.com'));
            $this->assertFalse(Validate::domain('a..b.com'));
        }

        public function testDomainLengthLimit(): void
        {
            $longLabel = str_repeat('a', 64);
            $this->assertFalse(Validate::domain("$longLabel.com"));

            $longDomain = str_repeat('a', 254);
            $this->assertFalse(Validate::domain("$longDomain.com"));
        }

        public function testUrlValid(): void
        {
            $this->assertTrue(Validate::url('https://example.com'));
            $this->assertTrue(Validate::url('http://example.com/path?query=1'));
            $this->assertTrue(Validate::url('ftp://files.example.com'));
        }

        public function testUrlInvalid(): void
        {
            $this->assertFalse(Validate::url(''));
            $this->assertFalse(Validate::url('not-a-url'));
            $this->assertFalse(Validate::url('http://'));
        }

        public function testEmailValid(): void
        {
            $this->assertTrue(Validate::email('user@example.com'));
            $this->assertTrue(Validate::email('test.user@sub.domain.co.uk'));
            $this->assertTrue(Validate::email('user+tag@example.org'));
        }

        public function testEmailInvalid(): void
        {
            $this->assertFalse(Validate::email(''));
            $this->assertFalse(Validate::email('not-an-email'));
            $this->assertFalse(Validate::email('@example.com'));
            $this->assertFalse(Validate::email('user@'));
        }

        public function testIpv4Valid(): void
        {
            $this->assertTrue(Validate::ipv4('127.0.0.1'));
            $this->assertTrue(Validate::ipv4('192.168.1.1'));
            $this->assertTrue(Validate::ipv4('0.0.0.0'));
            $this->assertTrue(Validate::ipv4('255.255.255.255'));
        }

        public function testIpv4Invalid(): void
        {
            $this->assertFalse(Validate::ipv4(''));
            $this->assertFalse(Validate::ipv4('not-an-ip'));
            $this->assertFalse(Validate::ipv4('256.1.2.3'));
            $this->assertFalse(Validate::ipv4('::1'));
            $this->assertFalse(Validate::ipv4('1.2.3'));
        }

        public function testIpv6Valid(): void
        {
            $this->assertTrue(Validate::ipv6('::1'));
            $this->assertTrue(Validate::ipv6('2001:db8::1'));
            $this->assertTrue(Validate::ipv6('fe80::1'));
            $this->assertTrue(Validate::ipv6('::'));
        }

        public function testIpv6Invalid(): void
        {
            $this->assertFalse(Validate::ipv6(''));
            $this->assertFalse(Validate::ipv6('127.0.0.1'));
            $this->assertFalse(Validate::ipv6('not-an-ip'));
            $this->assertFalse(Validate::ipv6('gggg::1'));
        }

        public function testEntityMetadataValid(): void
        {
            $this->assertTrue(Validate::entityMetadata(['key' => 'value']));
            $this->assertTrue(Validate::entityMetadata(['count' => 42]));
            $this->assertTrue(Validate::entityMetadata(['flag' => true]));
            $this->assertTrue(Validate::entityMetadata(['nullable' => null]));
            $this->assertTrue(Validate::entityMetadata(['a' => 'b', 'c' => 1, 'd' => null]));
        }

        public function testEntityMetadataNullValue(): void
        {
            $this->assertTrue(Validate::entityMetadata(['key' => null]));
        }

        public function testEntityMetadataEmpty(): void
        {
            $this->assertTrue(Validate::entityMetadata([]));
        }

        public function testEntityMetadataKeyTooLong(): void
        {
            $key = str_repeat('a', 65);
            $this->assertFalse(Validate::entityMetadata([$key => 'value']));
        }

        public function testEntityMetadataKeyAtMaxLength(): void
        {
            $key = str_repeat('a', 64);
            $this->assertTrue(Validate::entityMetadata([$key => 'value']));
        }

        public function testEntityMetadataEmptyStringValue(): void
        {
            $this->assertFalse(Validate::entityMetadata(['key' => '']));
        }

        public function testEntityMetadataStringValueTooLong(): void
        {
            $value = str_repeat('a', 1001);
            $this->assertFalse(Validate::entityMetadata(['key' => $value]));
        }

        public function testEntityMetadataStringValueAtMaxLength(): void
        {
            $value = str_repeat('a', 1000);
            $this->assertTrue(Validate::entityMetadata(['key' => $value]));
        }

        public function testEntityMetadataDisallowedValueType(): void
        {
            $this->assertFalse(Validate::entityMetadata(['key' => 3.14]));
            $this->assertFalse(Validate::entityMetadata(['key' => [1, 2, 3]]));
            $this->assertFalse(Validate::entityMetadata(['key' => new \stdClass()]));
        }

        public function testEntityMetadataSizeLimit(): void
        {
            $largeValue = str_repeat('a', 1000);
            $metadata = [];
            for ($i = 0; $i < 20; $i++)
            {
                $metadata["key_$i"] = $largeValue;
            }
            $this->assertFalse(Validate::entityMetadata($metadata));
        }

        public function testEntityMetadataIntegerValue(): void
        {
            $this->assertTrue(Validate::entityMetadata(['count' => 0]));
            $this->assertTrue(Validate::entityMetadata(['count' => -1]));
            $this->assertTrue(Validate::entityMetadata(['count' => PHP_INT_MAX]));
        }

        public function testEntityMetadataBooleanValue(): void
        {
            $this->assertTrue(Validate::entityMetadata(['flag' => true]));
            $this->assertTrue(Validate::entityMetadata(['flag' => false]));
        }
    }
