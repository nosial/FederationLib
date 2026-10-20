<?php

    namespace FederationLib\Tests\ContentType;

    use FederationLib\Enums\ContentType;
    use FederationLib\Objects\ContentInput;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ContentInputTest extends TestCase
    {
        private function assertRejected(mixed $item, string $message): void
        {
            try
            {
                ContentInput::fromRequest($item);
                $this->fail('Expected the ContentInput object to be rejected: ' . $message);
            }
            catch(InvalidArgumentException $e)
            {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        public function testDefaults(): void
        {
            $input = ContentInput::fromRequest(['text_content' => 'Hello']);

            $this->assertSame('Hello', $input->getTextContent());
            $this->assertSame(ContentType::TEXT, $input->getContentType());
            $this->assertNull($input->getContext());
            $this->assertNull($input->getNote());
            $this->assertNull($input->getTag());
            $this->assertFalse($input->isConfidential());
            $this->assertNull($input->getMetadata());
        }

        public function testEmptyObject(): void
        {
            $input = ContentInput::fromRequest([]);
            $this->assertNull($input->getTextContent());
            $this->assertSame(ContentType::TEXT, $input->getContentType());
        }

        public function testEveryMember(): void
        {
            $input = ContentInput::fromRequest([
                'text_content' => 'Win a prize',
                'content_type' => 'sms',
                'context' => ['region' => 'US', 'sender_id' => 'PRIZES'],
                'note' => 'A note',
                'tag' => 'sms_spam',
                'confidential' => true,
                'metadata' => ['source' => 'test'],
            ]);

            $this->assertSame('Win a prize', $input->getTextContent());
            $this->assertSame(ContentType::SMS, $input->getContentType());
            $this->assertSame(['region' => 'US', 'sender_id' => 'PRIZES'], $input->getContext());
            $this->assertSame('A note', $input->getNote());
            $this->assertSame('sms_spam', $input->getTag());
            $this->assertTrue($input->isConfidential());
            $this->assertSame(['source' => 'test'], $input->getMetadata());
        }

        public function testHostnameIsNormalized(): void
        {
            $input = ContentInput::fromRequest(['text_content' => 'Ads.Example.COM.', 'content_type' => 'HOSTNAME']);

            $this->assertSame('ads.example.com', $input->getTextContent());
            $this->assertSame(ContentType::HOSTNAME, $input->getContentType());
        }

        public function testEmptyContextIsAbsent(): void
        {
            $input = ContentInput::fromRequest(['context' => []]);

            $this->assertNull($input->getTextContent());
            $this->assertNull($input->getContext());
        }

        public function testRejectsEmptyTextContent(): void
        {
            // Text content is non-empty when present (OFD "ContentInput object")
            $this->assertRejected(['text_content' => ''], 'must not be empty');
        }

        public function testConfidentialValues(): void
        {
            $this->assertTrue(ContentInput::fromRequest(['confidential' => 'true'])->isConfidential());
            $this->assertTrue(ContentInput::fromRequest(['confidential' => 1])->isConfidential());
            $this->assertFalse(ContentInput::fromRequest(['confidential' => 'false'])->isConfidential());
            $this->assertFalse(ContentInput::fromRequest(['confidential' => false])->isConfidential());
        }

        public function testRejectsInvalidConfidentialValues(): void
        {
            // An invalid value is rejected rather than read as false, which would expose the evidence
            $this->assertRejected(['confidential' => 'maybe'], 'must be a boolean');
            $this->assertRejected(['confidential' => ['true']], 'must be a boolean');
        }

        public function testRejectsNonObjects(): void
        {
            $this->assertRejected('text', 'must be an object');
            $this->assertRejected(null, 'must be an object');
            $this->assertRejected(['a', 'b'], 'must be an object');
        }

        public function testRejectsUnknownMembers(): void
        {
            $this->assertRejected(['text_content' => 'Hi', 'automated' => true], 'Unknown evidence member "automated"');
            $this->assertRejected(['textContent' => 'Hi'], 'Unknown evidence member "textContent"');
        }

        public function testRejectsInvalidMemberTypes(): void
        {
            $this->assertRejected(['text_content' => 5], '"text_content" must be a string');
            $this->assertRejected(['note' => ['x']], '"note" must be a string');
            $this->assertRejected(['tag' => true], '"tag" must be a string');
            $this->assertRejected(['content_type' => 1], '"content_type" must be a string');
            $this->assertRejected(['confidential' => ['x']], '"confidential" must be a boolean');
            $this->assertRejected(['metadata' => 'x'], '"metadata" must be an object');
            $this->assertRejected(['context' => 'x'], '"context" must be an object');
        }

        public function testRejectsUnknownContentType(): void
        {
            $this->assertRejected(['text_content' => 'Hi', 'content_type' => 'VIDEO'], 'Invalid content type');
        }

        public function testRejectsContentInvalidForItsType(): void
        {
            $this->assertRejected(['text_content' => 'not a host name', 'content_type' => 'HOSTNAME'], 'DNS name');
            $this->assertRejected(['text_content' => 'example.com', 'content_type' => 'URL'], 'absolute URL');
            $this->assertRejected(['text_content' => 'Page', 'content_type' => 'WEB_PAGE'], 'requires the "url" key');
            $this->assertRejected(['text_content' => 'Hi', 'context' => ['subject' => 'Hi']], 'not defined for the TEXT content type');
        }

        public function testToArrayRoundTrip(): void
        {
            $item = [
                'text_content' => 'Page text',
                'content_type' => 'WEB_PAGE',
                'context' => ['url' => 'https://example.com/'],
                'note' => 'A note',
                'tag' => 'web',
                'confidential' => true,
                'metadata' => ['a' => 1],
            ];

            $this->assertSame($item, ContentInput::fromRequest($item)->toArray());
        }

        public function testToArrayOmitsDefaults(): void
        {
            $this->assertSame(['text_content' => 'Hello'], (new ContentInput('Hello'))->toArray());
        }
    }
