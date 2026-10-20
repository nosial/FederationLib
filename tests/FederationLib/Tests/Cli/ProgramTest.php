<?php

    namespace FederationLib\Tests\Cli;

    use FederationLib\Enums\CliCommands;
    use FederationLib\Program;
    use PHPUnit\Framework\TestCase;

    /**
     * Only the paths of the command-line interface that never reach the database are tested here
     */
    class ProgramTest extends TestCase
    {
        /**
         * Runs the program and returns its exit code and output
         *
         * @return array{0: int, 1: string}
         */
        private function execute(array $args): array
        {
            ob_start();
            try
            {
                $exitCode = Program::main($args);
            }
            finally
            {
                $output = (string)ob_get_clean();
            }

            return [$exitCode, $output];
        }

        public function testHelpListsEveryCommand(): void
        {
            foreach([[], ['help'], ['--help'], ['-h']] as $args)
            {
                [$exitCode, $output] = $this->execute($args);

                $this->assertSame(0, $exitCode, json_encode($args));
                $this->assertStringContainsString('Usage: federationlib <command>', $output);
                foreach(CliCommands::cases() as $command)
                {
                    $this->assertStringContainsString($command->value, $output);
                    $this->assertStringContainsString($command->getShortHelp(), $output);
                }
            }
        }

        public function testProgramPathIsSkipped(): void
        {
            foreach(['/usr/bin/federationlib', 'federationlib', 'main', 'build/net.nosial.federation.ncc'] as $path)
            {
                [$exitCode, $output] = $this->execute([$path, 'help']);
                $this->assertSame(0, $exitCode, $path);
                $this->assertStringContainsString('Usage: federationlib <command>', $output, $path);
                $this->assertStringNotContainsString('Unknown command', $output, $path);
            }
        }

        public function testUnknownCommand(): void
        {
            [$exitCode, $output] = $this->execute(['bogus']);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Unknown command: bogus', $output);
            $this->assertStringContainsString('Usage: federationlib <command>', $output);
        }

        public function testRemovedCommandsAreUnknown(): void
        {
            // The commands of earlier releases became subcommands, eg; `operators ls` rather than `list-operators`
            foreach(['list-operators', 'create-operator', 'delete-operator', 'list-audit-logs', 'generate-access-token'] as $command)
            {
                [$exitCode, $output] = $this->execute([$command]);
                $this->assertSame(1, $exitCode, $command);
                $this->assertStringContainsString('Unknown command: ' . $command, $output);
            }
        }

        public function testHelpOfCommand(): void
        {
            foreach(CliCommands::cases() as $command)
            {
                [$exitCode, $output] = $this->execute(['help', $command->value]);

                $this->assertSame(0, $exitCode, $command->value);
                $this->assertStringStartsWith($command->value . ' - ' . $command->getShortHelp(), $output);
                $this->assertStringContainsString($command->getHelp(), $output);
            }
        }

        public function testHelpOfUnknownCommand(): void
        {
            [$exitCode, $output] = $this->execute(['help', 'bogus']);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Unknown command: bogus', $output);
        }

        public function testHelpOptionOfCommandsWithoutSubcommands(): void
        {
            // init and maintenance would otherwise run, --help must only show their help
            foreach([CliCommands::INITIALIZE, CliCommands::MAINTENANCE] as $command)
            {
                foreach(['--help', '-h'] as $option)
                {
                    [$exitCode, $output] = $this->execute([$command->value, $option]);
                    $this->assertSame(0, $exitCode);
                    $this->assertStringStartsWith($command->value . ' - ', $output);
                }
            }
        }

        public function testOperatorsHelp(): void
        {
            [$exitCode, $output] = $this->execute(['operators', '--help']);
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('operators ls', $output);

            // Without a subcommand the help is shown as an error
            [$exitCode] = $this->execute(['operators']);
            $this->assertSame(1, $exitCode);
        }

        public function testOperatorsRejectsInvalidTarget(): void
        {
            [$exitCode, $output] = $this->execute(['operators', 'not-a-uuid']);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString("invalid operator UUID 'not-a-uuid'", $output);
        }

        public function testReputationHelp(): void
        {
            [$exitCode, $output] = $this->execute(['reputation', '--help']);
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('settle', $output);

            [$exitCode] = $this->execute(['reputation']);
            $this->assertSame(1, $exitCode);

            [$exitCode] = $this->execute(['reputation', 'settle', 'extra']);
            $this->assertSame(1, $exitCode);
        }
    }
