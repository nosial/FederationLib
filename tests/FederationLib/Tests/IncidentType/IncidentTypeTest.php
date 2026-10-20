<?php

    namespace FederationLib\Tests\IncidentType;

    use FederationLib\Classes\Configuration\ScanningConfiguration;
    use FederationLib\Enums\IncidentType;
    use PHPUnit\Framework\TestCase;

    class IncidentTypeTest extends TestCase
    {
        public function testAdvertisingAndTrackingExist(): void
        {
            $this->assertSame(IncidentType::ADVERTISING, IncidentType::from('ADVERTISING'));
            $this->assertSame(IncidentType::TRACKING, IncidentType::from('TRACKING'));
        }

        public function testEveryIncidentTypeIsAConcern(): void
        {
            // An OTHER blacklist record must also be able to suggest blocking, so every incident type is a concern
            $this->assertSame(IncidentType::cases(), ScanningConfiguration::getAllConcerns());
        }

        public function testTryFromCaseInsensitive(): void
        {
            $this->assertSame(IncidentType::SPAM, IncidentType::tryFromCaseInsensitive('spam'));
            $this->assertSame(IncidentType::SERVICE_ABUSE, IncidentType::tryFromCaseInsensitive('Service_Abuse'));
            $this->assertNull(IncidentType::tryFromCaseInsensitive('bogus'));
        }

        public function testFromResponseTreatsUnknownValuesAsOther(): void
        {
            // A later minor version may add incident types (OFD "Versioning")
            $this->assertSame(IncidentType::PHISHING, IncidentType::fromResponse('PHISHING'));
            $this->assertSame(IncidentType::TRACKING, IncidentType::fromResponse('tracking'));
            $this->assertSame(IncidentType::OTHER, IncidentType::fromResponse('SOMETHING_NEW'));
            $this->assertSame(IncidentType::OTHER, IncidentType::fromResponse(''));
        }

        public function testParseListFromString(): void
        {
            $this->assertSame([IncidentType::SPAM, IncidentType::ADVERTISING], IncidentType::parseList('spam, ADVERTISING'));
            $this->assertSame([IncidentType::SPAM], IncidentType::parseList('SPAM,spam, Spam'));
            $this->assertSame([], IncidentType::parseList(''));
            $this->assertSame([], IncidentType::parseList(' , '));
        }

        public function testParseListFromArray(): void
        {
            $this->assertSame([IncidentType::TRACKING, IncidentType::MALWARE], IncidentType::parseList(['tracking', ' MALWARE ']));
            $this->assertSame([], IncidentType::parseList([]));
        }

        public function testParseListRejectsInvalidLists(): void
        {
            $this->assertNull(IncidentType::parseList('SPAM,BOGUS'));
            $this->assertNull(IncidentType::parseList(['SPAM', 5]));
            $this->assertNull(IncidentType::parseList(['a' => 'SPAM']));
            $this->assertNull(IncidentType::parseList(5));
            $this->assertNull(IncidentType::parseList(null));
        }
    }
