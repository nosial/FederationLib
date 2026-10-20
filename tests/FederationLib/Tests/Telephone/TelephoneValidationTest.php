<?php

    namespace FederationLib\Tests\Telephone;

    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use PHPUnit\Framework\TestCase;

    class TelephoneValidationTest extends TestCase
    {
        public function testTelephoneEntityHost(): void
        {
            $this->assertSame('e164.arpa', Utilities::TELEPHONE_ENTITY_HOST);
            $this->assertTrue(Utilities::isTelephoneHost('e164.arpa'));
            $this->assertFalse(Utilities::isTelephoneHost('E164.ARPA'));
            $this->assertFalse(Utilities::isTelephoneHost('sub.e164.arpa'));
            $this->assertFalse(Utilities::isTelephoneHost('example.com'));
        }

        public function testCanonicalizeTelephoneNumber(): void
        {
            $this->assertSame('16502530000', Utilities::canonicalizeTelephoneNumber('+1 650-253-0000'));
            $this->assertSame('16502530000', Utilities::canonicalizeTelephoneNumber('(650) 253-0000', 'US'));
            $this->assertNull(Utilities::canonicalizeTelephoneNumber('(650) 253-0000'));
            $this->assertNull(Utilities::canonicalizeTelephoneNumber('hello'));
        }

        public function testTelephoneNumberLocalPart(): void
        {
            $this->assertTrue(Validate::telephoneNumber('16502530000'));
            $this->assertTrue(Validate::telephoneNumber('1'));
            $this->assertTrue(Validate::telephoneNumber(str_repeat('9', 15)));

            foreach(['', '+16502530000', '016502530000', str_repeat('9', 16), '1 650 253 0000', '1-650-253-0000', 'abc', '１６５０'] as $value)
            {
                $this->assertFalse(Validate::telephoneNumber($value), $value);
            }
        }

        public function testEntityAddress(): void
        {
            $this->assertTrue(Validate::entityAddress('e164.arpa', '16502530000'));
            // The telephone numbering namespace is not itself an entity
            $this->assertFalse(Validate::entityAddress('e164.arpa', null));
            $this->assertFalse(Validate::entityAddress('e164.arpa', '+16502530000'));
            $this->assertFalse(Validate::entityAddress('e164.arpa', 'john'));
            // No entity host is under the namespace, such as the ENUM domain of a number
            $this->assertFalse(Validate::entityAddress('6.5.4.3.2.1.5.5.5.1.e164.arpa', null));
            $this->assertFalse(Validate::entityAddress('www.e164.arpa', '16502530000'));
            // Other hosts are not constrained
            $this->assertTrue(Validate::entityAddress('example.com', null));
            $this->assertTrue(Validate::entityAddress('example.com', 'john'));
        }

        public function testTelephoneAddress(): void
        {
            $this->assertTrue(Validate::telephoneAddress('16502530000@e164.arpa'));
            $this->assertFalse(Validate::telephoneAddress('+16502530000@e164.arpa'));
            $this->assertFalse(Validate::telephoneAddress('16502530000@E164.ARPA'));
            $this->assertFalse(Validate::telephoneAddress('16502530000@e164.arpa.'));
            $this->assertFalse(Validate::telephoneAddress('e164.arpa'));
            $this->assertFalse(Validate::telephoneAddress('6.5.4.3.2.1.5.5.5.1.e164.arpa'));
            // Identifiers outside the namespace are not constrained
            $this->assertTrue(Validate::telephoneAddress('john@example.com'));
            $this->assertTrue(Validate::telephoneAddress('0190f5a2-7c4e-7b1a-9d3f-2a6c8e4b1f07'));
            $this->assertTrue(Validate::telephoneAddress(hash('sha256', '16502530000@e164.arpa')));
        }

        public function testTelephoneEntityAddressParses(): void
        {
            $this->assertSame(['host' => 'e164.arpa', 'id' => '16502530000'], Utilities::parseEntityAddress('16502530000@e164.arpa'));
        }

        public function testDnsName(): void
        {
            foreach(['example.com', 'ads.example.co.uk', '_dmarc.example.com', 'localhost', 'xn--bcher-kva.example', 'a.b-c.d'] as $name)
            {
                $this->assertTrue(Validate::dnsName($name), $name);
            }

            foreach(['', 'Example.com', '192.0.2.1', '::1', '2001:db8::1', 'a..b', '.example.com', '-a.example.com', 'a-.example.com',
                     'exa mple.com', 'bücher.example', str_repeat('a', 64) . '.com', str_repeat('a.', 127) . 'aa'] as $name)
            {
                $this->assertFalse(Validate::dnsName($name), $name);
            }
        }

        public function testDnsNameRejectsIpv4InAnyNotation(): void
        {
            $this->assertFalse(Validate::dnsName('010.000.000.001'));
            $this->assertFalse(Validate::dnsName('4294967295.0.0.0'));
        }

        public function testAbsoluteUrl(): void
        {
            foreach(['https://example.com', 'http://example.com/path?q=1#frag', 'mailto:someone@example.com', 'data:text/plain,hello'] as $url)
            {
                $this->assertTrue(Validate::absoluteUrl($url), $url);
            }

            foreach(['', 'example.com', '/path', '//example.com', 'https://exa mple.com', "https://example.com/\n", '1http://example.com'] as $url)
            {
                $this->assertFalse(Validate::absoluteUrl($url), $url);
            }
        }

        public function testHttpUrl(): void
        {
            $this->assertTrue(Validate::httpUrl('https://example.com'));
            $this->assertTrue(Validate::httpUrl('HTTP://example.com/path'));

            foreach(['ftp://example.com', 'mailto:someone@example.com', 'javascript:alert(1)', 'http:/path', 'https://', 'example.com'] as $url)
            {
                $this->assertFalse(Validate::httpUrl($url), $url);
            }
        }
    }
