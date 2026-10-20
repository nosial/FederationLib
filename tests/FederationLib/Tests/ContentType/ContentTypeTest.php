<?php

    namespace FederationLib\Tests\ContentType;

    use FederationLib\Enums\ContentType;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ContentTypeTest extends TestCase
    {
        private function assertInvalid(ContentType $type, ?string $textContent, ?array $context, string $message): void
        {
            try
            {
                $type->validate($textContent, $context);
                $this->fail(sprintf('Expected %s content to be rejected: %s', $type->value, $message));
            }
            catch(InvalidArgumentException $e)
            {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        public function testValues(): void
        {
            $this->assertSame(['TEXT', 'EMAIL', 'SMS', 'WEB_PAGE', 'HOSTNAME', 'URL'], array_map(fn(ContentType $type) => $type->value, ContentType::cases()));
        }

        public function testTryFromCaseInsensitive(): void
        {
            $this->assertSame(ContentType::SMS, ContentType::tryFromCaseInsensitive('sms'));
            $this->assertSame(ContentType::WEB_PAGE, ContentType::tryFromCaseInsensitive('Web_Page'));
            $this->assertNull(ContentType::tryFromCaseInsensitive('web page'));
            $this->assertNull(ContentType::tryFromCaseInsensitive(''));
        }

        public function testContextKeys(): void
        {
            $this->assertSame([], ContentType::TEXT->getContextKeys());
            $this->assertSame([], ContentType::URL->getContextKeys());
            $this->assertSame(['subject', 'sender', 'reply_to'], ContentType::EMAIL->getContextKeys());
            $this->assertSame(['region', 'sender_id'], ContentType::SMS->getContextKeys());
            $this->assertSame(['url', 'final_url', 'title', 'description', 'language'], ContentType::WEB_PAGE->getContextKeys());
            $this->assertSame(['query_type'], ContentType::HOSTNAME->getContextKeys());

            $this->assertSame(['url'], ContentType::WEB_PAGE->getRequiredContextKeys());
            foreach([ContentType::TEXT, ContentType::EMAIL, ContentType::SMS, ContentType::HOSTNAME, ContentType::URL] as $type)
            {
                $this->assertSame([], $type->getRequiredContextKeys(), $type->value);
            }
        }

        public function testNormalizeHostname(): void
        {
            $this->assertSame('example.com', ContentType::HOSTNAME->normalizeTextContent('Example.COM.'));
            // Only a single trailing dot is removed
            $this->assertSame('example.com.', ContentType::HOSTNAME->normalizeTextContent('example.com..'));
        }

        public function testNormalizeLeavesOtherTypesUnchanged(): void
        {
            foreach([ContentType::TEXT, ContentType::EMAIL, ContentType::SMS, ContentType::WEB_PAGE, ContentType::URL] as $type)
            {
                $this->assertSame('Example.COM.', $type->normalizeTextContent('Example.COM.'), $type->value);
            }
        }

        public function testValidHostname(): void
        {
            ContentType::HOSTNAME->validate('ads.example.com', ['query_type' => 'A']);
            ContentType::HOSTNAME->validate('_dmarc.example.com', null);
            $this->addToAssertionCount(1);
        }

        public function testInvalidHostname(): void
        {
            foreach(['not a hostname', 'Example.com', '192.0.2.1', '::1', 'https://example.com', '-bad.example.com'] as $value)
            {
                $this->assertInvalid(ContentType::HOSTNAME, $value, null, 'DNS name');
            }
        }

        public function testUrl(): void
        {
            ContentType::URL->validate('https://example.com/path?q=1', null);
            ContentType::URL->validate('mailto:someone@example.com', null);
            $this->addToAssertionCount(1);

            foreach(['example.com/path', 'https://example.com/a b', '/relative'] as $value)
            {
                $this->assertInvalid(ContentType::URL, $value, null, 'absolute URL');
            }
        }

        public function testUrlAcceptsNoContext(): void
        {
            $this->assertInvalid(ContentType::URL, 'https://example.com', ['title' => 'Title'], 'not defined for the URL content type');
        }

        public function testEmptyTextContentIsNotValidatedForItsType(): void
        {
            // ContentInput objects may carry only a context or attachments
            ContentType::HOSTNAME->validate(null, null);
            ContentType::URL->validate('', null);
            $this->addToAssertionCount(1);
        }

        public function testTextAcceptsNoContext(): void
        {
            ContentType::TEXT->validate('Hello', null);
            ContentType::TEXT->validate('Hello', []);
            $this->addToAssertionCount(1);

            $this->assertInvalid(ContentType::TEXT, 'Hello', ['subject' => 'Hi'], 'not defined for the TEXT content type');
        }

        public function testEmailContext(): void
        {
            ContentType::EMAIL->validate('Body', ['subject' => 'Hi', 'sender' => 'a@example.com', 'reply_to' => 'b@example.com']);
            $this->addToAssertionCount(1);

            $this->assertInvalid(ContentType::EMAIL, 'Body', ['region' => 'US'], '"region" is not defined');
        }

        public function testSmsRegion(): void
        {
            ContentType::SMS->validate('Body', ['region' => 'US', 'sender_id' => 'BANK']);
            ContentType::SMS->validate('Body', ['region' => 'gb']);
            $this->addToAssertionCount(1);

            foreach(['USA', 'U', '12', 'U-'] as $region)
            {
                $this->assertInvalid(ContentType::SMS, 'Body', ['region' => $region], 'ISO 3166-1 alpha-2');
            }
        }

        public function testWebPageRequiresUrl(): void
        {
            ContentType::WEB_PAGE->validate('Page text', ['url' => 'https://example.com/', 'final_url' => 'http://example.org/landing', 'title' => 'Title']);
            ContentType::WEB_PAGE->validate(null, ['url' => 'https://example.com/']);
            $this->addToAssertionCount(1);

            $this->assertInvalid(ContentType::WEB_PAGE, 'Page text', null, 'requires the "url" key');
            $this->assertInvalid(ContentType::WEB_PAGE, 'Page text', ['title' => 'Title'], 'requires the "url" key');
        }

        public function testWebPageUrlsMustBeHttp(): void
        {
            $this->assertInvalid(ContentType::WEB_PAGE, null, ['url' => 'ftp://example.com/'], '"url" must be an absolute HTTP or HTTPS URL');
            $this->assertInvalid(ContentType::WEB_PAGE, null, ['url' => 'example.com'], '"url" must be an absolute HTTP or HTTPS URL');
            $this->assertInvalid(ContentType::WEB_PAGE, null, ['url' => 'https://example.com/', 'final_url' => 'javascript:alert(1)'], '"final_url" must be an absolute HTTP or HTTPS URL');
        }

        public function testContextMustBeAnObject(): void
        {
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['subject'], 'must be an object');
        }

        public function testContextValuesMustBeNonEmptyStrings(): void
        {
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['subject' => ''], 'non-empty string');
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['subject' => 5], 'non-empty string');
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['subject' => ['nested']], 'non-empty string');
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['subject' => null], 'non-empty string');
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['subject' => str_repeat('a', 1001)], 'at most 1000 bytes');

            ContentType::EMAIL->validate('Body', ['subject' => str_repeat('a', 1000)]);
            $this->addToAssertionCount(1);
        }

        public function testContextKeyLength(): void
        {
            $this->assertInvalid(ContentType::EMAIL, 'Body', [str_repeat('k', 65) => 'value'], '1 to 64 bytes');
            $this->assertInvalid(ContentType::EMAIL, 'Body', ['' => 'value'], '1 to 64 bytes');
        }

        public function testContextSizeLimit(): void
        {
            $context = [];
            for($i = 0; $i < 9; $i++)
            {
                $context['key_' . $i] = str_repeat('a', 1000);
            }

            // The size constraint applies to every context object, before its keys are checked
            $this->assertInvalid(ContentType::EMAIL, 'Body', $context, '8000 bytes');
        }
    }
