<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\ReputationManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;

    class ReputationCommand implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $tokens): int
        {
            $arguments = Arguments::parse($tokens);
            if($arguments->get(0) !== 'settle' || $arguments->count() > 1)
            {
                print(self::getHelp());
                return $arguments->wantsHelp() ? 0 : 1;
            }

            try
            {
                $result = ReputationManager::settle();
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to settle reputation: ' . $e->getMessage(), $e);
                print("Error: Failed to settle reputation, see the logs for details.\n");
                return 1;
            }

            printf("Settled %d day(s): %d gain(s), %d loss(es)\n", $result['days'], $result['gains'], $result['losses']);
            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationlib reputation settle\n" .
                "\nCommands:\n" .
                "  settle  Apply the reputation of every completed day of activity (UTC) that was not applied yet. An\n" .
                "          entity changes at most once per day: authors gain for normal content and lose for content\n" .
                "          that reached the block threshold, mentioned entities gain when several distinct authors\n" .
                "          mentioned them in normal content. Days are never applied twice, run it as often as you like.\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Settles the reputation of completed days of activity: settle.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Example:\n" .
                "  federationlib reputation settle\n";
        }
    }
