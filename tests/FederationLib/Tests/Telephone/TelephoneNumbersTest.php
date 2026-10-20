<?php

    namespace FederationLib\Tests\Telephone;

    use FederationLib\Classes\TelephoneNumbers;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\NamedEntityType;
    use PHPUnit\Framework\TestCase;

    class TelephoneNumbersTest extends TestCase
    {
        public function testCanonicalizeInternationalFormats(): void
        {
            foreach(['+1 650-253-0000', '+16502530000', '+1 (650) 253 0000', '+1.650.253.0000'] as $number)
            {
                $this->assertSame('16502530000', TelephoneNumbers::canonicalize($number), $number);
            }

            $this->assertSame('442070313000', TelephoneNumbers::canonicalize('+44 20 7031 3000'));
        }

        public function testCanonicalizeNationalFormatRequiresRegion(): void
        {
            $this->assertNull(TelephoneNumbers::canonicalize('(650) 253-0000'));
            $this->assertSame('16502530000', TelephoneNumbers::canonicalize('(650) 253-0000', 'US'));
            // The region is case-insensitive
            $this->assertSame('16502530000', TelephoneNumbers::canonicalize('650-253-0000', 'us'));
            $this->assertSame('442070313000', TelephoneNumbers::canonicalize('020 7031 3000', 'GB'));
        }

        public function testCanonicalizeIgnoresInvalidRegion(): void
        {
            // An invalid region is treated as unknown, international numbers are still accepted
            $this->assertNull(TelephoneNumbers::canonicalize('(650) 253-0000', 'USA'));
            $this->assertSame('16502530000', TelephoneNumbers::canonicalize('+1 650-253-0000', 'USA'));
        }

        public function testCanonicalizeRejectsInvalidNumbers(): void
        {
            foreach(['', 'not a number', '+1 000 000 0000', '+1 650', '12345', '+999 1234 5678'] as $number)
            {
                $this->assertNull(TelephoneNumbers::canonicalize($number), $number);
            }
        }

        public function testCanonicalLocalPartIsValidTelephoneEntity(): void
        {
            $localPart = TelephoneNumbers::canonicalize('+44 20 7031 3000');
            $this->assertTrue(Validate::telephoneNumber($localPart));
            $this->assertTrue(Validate::entityAddress(Utilities::TELEPHONE_ENTITY_HOST, $localPart));
        }

        public function testExtractInternationalNumber(): void
        {
            $text = 'Call +1 650-253-0000 now';
            $numbers = TelephoneNumbers::extract($text);

            $this->assertCount(1, $numbers);
            $this->assertSame('16502530000', $numbers[0]['number']);
            $this->assertSame(5, $numbers[0]['offset']);
            $this->assertSame('+1 650-253-0000', substr($text, $numbers[0]['offset'], $numbers[0]['length']));
        }

        public function testExtractReportsByteOffsets(): void
        {
            // libphonenumber reports character offsets, entity positions are byte offsets
            $text = 'Café ☎ +1 650-253-0000';
            $numbers = TelephoneNumbers::extract($text);

            $this->assertCount(1, $numbers);
            $this->assertSame(strpos($text, '+1'), $numbers[0]['offset']);
            $this->assertSame('+1 650-253-0000', substr($text, $numbers[0]['offset'], $numbers[0]['length']));
        }

        public function testExtractNationalNumberOnlyWithRegion(): void
        {
            $text = 'Reply to (650) 253-0000 to claim';

            $this->assertSame([], TelephoneNumbers::extract($text));

            $numbers = TelephoneNumbers::extract($text, 'US');
            $this->assertCount(1, $numbers);
            $this->assertSame('16502530000', $numbers[0]['number']);
            $this->assertSame('(650) 253-0000', substr($text, $numbers[0]['offset'], $numbers[0]['length']));
        }

        public function testExtractIgnoresDigitSequencesThatAreNotNumbers(): void
        {
            $this->assertSame([], TelephoneNumbers::extract('Your verification code is 482913', 'US'));
            $this->assertSame([], TelephoneNumbers::extract('Order 1234567 has shipped', 'US'));
            $this->assertSame([], TelephoneNumbers::extract('No numbers here'));
        }

        public function testExtractMultipleNumbers(): void
        {
            $numbers = TelephoneNumbers::extract('US: +1 650-253-0000, UK: +44 20 7031 3000');

            $this->assertSame(['16502530000', '442070313000'], array_column($numbers, 'number'));
        }

        public function testNamedEntityExtractionKeysTelephoneNumbersByE164(): void
        {
            $text = 'Text +1 650-253-0000 or visit example.com';
            $entities = NamedEntityType::extract($text);

            $this->assertArrayHasKey('+16502530000', $entities);
            $this->assertSame(NamedEntityType::TELEPHONE, $entities['+16502530000']->getType());
            $this->assertSame('+1 650-253-0000', substr($text, $entities['+16502530000']->getOffset(), $entities['+16502530000']->getLength()));
            $this->assertArrayHasKey('example.com', $entities);
        }

        public function testNamedEntityExtractionMergesWaysOfWritingANumber(): void
        {
            $entities = NamedEntityType::extract('Call +1 650-253-0000 or +16502530000');

            $telephone = array_filter($entities, fn($position) => $position->getType() === NamedEntityType::TELEPHONE);
            $this->assertSame(['+16502530000'], array_keys($telephone));
        }

        public function testNamedEntityExtractionUsesRegion(): void
        {
            $text = 'Call (650) 253-0000';

            $this->assertArrayNotHasKey('+16502530000', NamedEntityType::extract($text));
            $this->assertArrayHasKey('+16502530000', NamedEntityType::extract($text, 'US'));
        }

        public function testTelephoneNumberIsNotAnIpAddressOrDomain(): void
        {
            $entities = NamedEntityType::extract('Call +44 20 7031 3000');

            $this->assertSame(['+442070313000'], array_keys($entities));
        }

        public function testTelephoneValidity(): void
        {
            $this->assertTrue(NamedEntityType::TELEPHONE->isValid('+1 650-253-0000'));
            $this->assertFalse(NamedEntityType::TELEPHONE->isValid('+1 000 000 0000'));
        }
    }
