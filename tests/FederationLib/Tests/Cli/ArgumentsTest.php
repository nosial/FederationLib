<?php

    namespace FederationLib\Tests\Cli;

    use FederationLib\Classes\CLI\Arguments;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class ArgumentsTest extends TestCase
    {
        public function testPositionalArguments(): void
        {
            $arguments = Arguments::parse(['ls', 'extra', 'more']);

            $this->assertSame(3, $arguments->count());
            $this->assertSame('ls', $arguments->get(0));
            $this->assertSame('extra', $arguments->get(1));
            $this->assertNull($arguments->get(3));
            $this->assertSame(['extra', 'more'], $arguments->getFrom(1));
            $this->assertSame([], $arguments->getFrom(5));
        }

        public function testOptionsMayAppearAnywhere(): void
        {
            $arguments = Arguments::parse(['--full', 'ls', '--limit', '10', 'next'], ['limit']);

            $this->assertSame(['ls', 'next'], $arguments->getFrom(0));
            $this->assertTrue($arguments->has('full'));
            $this->assertSame('10', $arguments->option('limit'));
        }

        public function testFlagDoesNotTakeTheFollowingArgument(): void
        {
            // Only options declared as taking a value consume the following argument
            $arguments = Arguments::parse(['--full', 'ls']);

            $this->assertTrue($arguments->has('full'));
            $this->assertNull($arguments->option('full'));
            $this->assertSame('default', $arguments->option('full', 'default'));
            $this->assertSame(['ls'], $arguments->getFrom(0));
        }

        public function testEqualsSyntax(): void
        {
            $arguments = Arguments::parse(['--bot=false', '--name=a=b']);

            $this->assertSame('false', $arguments->option('bot'));
            $this->assertSame('a=b', $arguments->option('name'));
        }

        public function testValueOptionWithoutValue(): void
        {
            // A value option followed by another option, or by nothing, is a flag
            $arguments = Arguments::parse(['--limit', '--full'], ['limit']);
            $this->assertTrue($arguments->has('limit'));
            $this->assertNull($arguments->option('limit'));
            $this->assertTrue($arguments->has('full'));

            $arguments = Arguments::parse(['--limit'], ['limit']);
            $this->assertTrue($arguments->has('limit'));
            $this->assertNull($arguments->option('limit'));
        }

        public function testValueOptionTakesNegativeNumbers(): void
        {
            // A single dash does not end a value, so negative values are accepted
            $arguments = Arguments::parse(['--delta', '-5'], ['delta']);
            $this->assertSame('-5', $arguments->option('delta'));
        }

        public function testDoubleDashEndsOptions(): void
        {
            $arguments = Arguments::parse(['create', '--', '--not-an-option', 'name'], ['limit']);

            $this->assertSame(['create', '--not-an-option', 'name'], $arguments->getFrom(0));
            $this->assertFalse($arguments->has('not-an-option'));
        }

        public function testSingleDashIsPositional(): void
        {
            $this->assertSame(['-'], Arguments::parse(['-'])->getFrom(0));
        }

        public function testShortOptions(): void
        {
            $arguments = Arguments::parse(['-h']);
            $this->assertTrue($arguments->has('h'));
            $this->assertTrue($arguments->wantsHelp());
            $this->assertTrue(Arguments::parse(['ls', '--help'])->wantsHelp());
            $this->assertFalse(Arguments::parse(['ls'])->wantsHelp());
        }

        public function testPositiveInt(): void
        {
            $arguments = Arguments::parse(['--limit', '25'], ['limit']);
            $this->assertSame(25, $arguments->positiveInt('limit', 10));
            $this->assertSame(10, $arguments->positiveInt('page', 10));
        }

        public function testPositiveIntRejectsInvalidValues(): void
        {
            foreach(['0', '-1', 'abc', '1.5'] as $value)
            {
                try
                {
                    Arguments::parse(['--limit=' . $value])->positiveInt('limit', 10);
                    $this->fail('Expected an InvalidArgumentException for ' . $value);
                }
                catch(InvalidArgumentException $e)
                {
                    $this->assertStringContainsString('--limit', $e->getMessage());
                }
            }
        }

        public function testBool(): void
        {
            $this->assertNull(Arguments::parse([])->bool('bot'));
            $this->assertTrue(Arguments::parse(['--bot'])->bool('bot'));

            foreach(['true' => true, 'yes' => true, 'on' => true, '1' => true, 'false' => false, 'no' => false, 'off' => false, '0' => false] as $value => $expected)
            {
                $this->assertSame($expected, Arguments::parse(['--bot', (string)$value], ['bot'])->bool('bot'), (string)$value);
            }
        }

        public function testBoolRejectsInvalidValues(): void
        {
            $this->expectException(InvalidArgumentException::class);
            Arguments::parse(['--bot=maybe'])->bool('bot');
        }

        public function testUnknownOptions(): void
        {
            $arguments = Arguments::parse(['--bot=true', '--bogus', '--help', '-h', '--client=false']);
            $this->assertSame(['bogus'], $arguments->getUnknownOptions(['bot', 'client']));
            $this->assertSame([], Arguments::parse(['ls'])->getUnknownOptions([]));
        }

        public function testTokensAreReindexed(): void
        {
            $arguments = Arguments::parse([3 => 'ls', 7 => '--limit', 9 => '5'], ['limit']);
            $this->assertSame('ls', $arguments->get(0));
            $this->assertSame('5', $arguments->option('limit'));
        }
    }
