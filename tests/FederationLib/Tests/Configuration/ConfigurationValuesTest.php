<?php

    namespace FederationLib\Tests\Configuration;

    use FederationLib\Classes\Configuration\ConfigurationValues;
    use PHPUnit\Framework\TestCase;

    class ConfigurationValuesTest extends TestCase
    {
        public function testParseListReturnsDefaultWhenNotConfigured(): void
        {
            $this->assertSame(['A', 'B'], ConfigurationValues::parseList(null, ['A', 'B', 'C'], ['A', 'B']));
        }

        public function testParseListAcceptsCommaSeparatedString(): void
        {
            $this->assertSame(['A', 'C'], ConfigurationValues::parseList(' a , c ', ['A', 'B', 'C'], []));
        }

        public function testParseListAcceptsArray(): void
        {
            $this->assertSame(['B', 'C'], ConfigurationValues::parseList(['b', 'C'], ['A', 'B', 'C'], []));
        }

        public function testParseListDropsUnknownItemsAndDuplicates(): void
        {
            $this->assertSame(['A'], ConfigurationValues::parseList('a,unknown,A,a', ['A', 'B'], ['B']));
        }

        public function testParseListOfNothingIsEmptyRatherThanDefault(): void
        {
            // An empty list disables what the list enables (eg; search.types), it must not fall back to the default
            $this->assertSame([], ConfigurationValues::parseList('', ['A'], ['A']));
            $this->assertSame([], ConfigurationValues::parseList([], ['A'], ['A']));
        }

        public function testParseListIgnoresNonStringItems(): void
        {
            $this->assertSame(['A'], ConfigurationValues::parseList(['a', 1, null, ['b']], ['A', 'B'], []));
        }

        public function testParseMapAcceptsKeyValueString(): void
        {
            $this->assertSame(['ENTITY' => '5000', 'EVIDENCE' => '3000'], ConfigurationValues::parseMap('entity=5000, EVIDENCE = 3000'));
        }

        public function testParseMapSkipsPairsWithoutValue(): void
        {
            $this->assertSame(['A' => '1'], ConfigurationValues::parseMap('A=1,B,C'));
        }

        public function testParseMapKeepsEqualsSignsInValue(): void
        {
            $this->assertSame(['A' => 'x=y'], ConfigurationValues::parseMap('A=x=y'));
        }

        public function testParseMapAcceptsAssociativeArray(): void
        {
            $this->assertSame(['NORMAL' => 1, 'MALICIOUS' => -20], ConfigurationValues::parseMap(['normal' => 1, 'MALICIOUS' => -20]));
        }

        public function testParseMapDropsIntegerKeys(): void
        {
            $this->assertSame(['A' => 1], ConfigurationValues::parseMap(['A' => 1, 0 => 'x']));
        }

        public function testParseMapOfOtherValuesIsEmpty(): void
        {
            $this->assertSame([], ConfigurationValues::parseMap(null));
            $this->assertSame([], ConfigurationValues::parseMap(42));
            $this->assertSame([], ConfigurationValues::parseMap(true));
        }

        public function testParseDurationUnits(): void
        {
            $this->assertSame(30, ConfigurationValues::parseDuration('30s', null));
            $this->assertSame(600, ConfigurationValues::parseDuration('10m', null));
            $this->assertSame(43200, ConfigurationValues::parseDuration('12h', null));
            $this->assertSame(2592000, ConfigurationValues::parseDuration('30d', null));
            $this->assertSame(1209600, ConfigurationValues::parseDuration('2w', null));
            $this->assertSame(63072000, ConfigurationValues::parseDuration('2y', null));
        }

        public function testParseDurationPlainSeconds(): void
        {
            $this->assertSame(120, ConfigurationValues::parseDuration('120', null));
            $this->assertSame(120, ConfigurationValues::parseDuration(120, null));
            $this->assertSame(120, ConfigurationValues::parseDuration(120.9, null));
            $this->assertSame(3600, ConfigurationValues::parseDuration(' 1 H ', null));
        }

        public function testParseDurationOffValues(): void
        {
            foreach(['off', 'never', 'null', 'none', 'false', 'OFF'] as $value)
            {
                $this->assertNull(ConfigurationValues::parseDuration($value, 10), $value);
            }

            $this->assertNull(ConfigurationValues::parseDuration(null, 10));
            $this->assertNull(ConfigurationValues::parseDuration(false, 10));
        }

        public function testParseDurationInvalidReturnsDefault(): void
        {
            $this->assertSame(10, ConfigurationValues::parseDuration('ten days', 10));
            $this->assertSame(10, ConfigurationValues::parseDuration('5x', 10));
            $this->assertSame(10, ConfigurationValues::parseDuration('-5', 10));
            $this->assertSame(10, ConfigurationValues::parseDuration(['5'], 10));
            $this->assertNull(ConfigurationValues::parseDuration('bogus', null));
        }

        public function testParseBool(): void
        {
            $this->assertTrue(ConfigurationValues::parseBool(true, false));
            $this->assertFalse(ConfigurationValues::parseBool(false, true));
            $this->assertTrue(ConfigurationValues::parseBool('true', false));
            $this->assertTrue(ConfigurationValues::parseBool('yes', false));
            $this->assertTrue(ConfigurationValues::parseBool('1', false));
            $this->assertTrue(ConfigurationValues::parseBool(1, false));
            $this->assertFalse(ConfigurationValues::parseBool('false', true));
            $this->assertFalse(ConfigurationValues::parseBool('off', true));
            $this->assertFalse(ConfigurationValues::parseBool('0', true));
        }

        public function testParseBoolDefaults(): void
        {
            $this->assertTrue(ConfigurationValues::parseBool(null, true));
            $this->assertFalse(ConfigurationValues::parseBool(null, false));
            $this->assertTrue(ConfigurationValues::parseBool('maybe', true));
            $this->assertFalse(ConfigurationValues::parseBool('maybe', false));
        }
    }
