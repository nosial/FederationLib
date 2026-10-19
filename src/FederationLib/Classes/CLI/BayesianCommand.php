<?php

    namespace FederationLib\Classes\CLI;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\Configuration as BayesianConfiguration;
    use BayesianPlugin\Classes\Learner;
    use BayesianPlugin\Exceptions\BayesianException;
    use Exception;
    use FederationLib\Classes\BayesianModelRecovery;
    use FederationLib\Classes\BayesianSync;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Interfaces\CommandLineInterface;

    class BayesianCommand implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $tokens): int
        {
            $arguments = Arguments::parse($tokens);
            if($arguments->get(0) !== 'sync' || $arguments->count() > 1)
            {
                print(self::getHelp());
                return $arguments->wantsHelp() ? 0 : 1;
            }

            if(!in_array(BayesianModelRecovery::PLUGIN_PACKAGE, Configuration::getPluginsConfiguration()->getPlugins(), true))
            {
                print("BayesianPlugin is not enabled, nothing to do.\n");
                return 0;
            }

            try
            {
                // Imports the plugins' packages
                PluginManager::loadPlugins();
            }
            catch(Exception $e)
            {
                print(sprintf("Error: Failed to load the plugins: %s\n", $e->getMessage()));
                return 1;
            }

            if(!class_exists(Learner::class))
            {
                print("Error: The installed BayesianPlugin can't sync classifications, update it\n");
                return 1;
            }

            if(!BayesianConfiguration::isLearningEnabled())
            {
                print("BayesianPlugin learning is disabled, nothing to do.\n");
                return 0;
            }

            try
            {
                $position = $arguments->has('full') ? null : BayesianSync::getPosition();
                print($position === null ? "Learning every classification\n" : sprintf("Learning the classifications made after %s\n", $position[0]));

                $client = BayesianPlugin::getClient();
                $queueFull = false;
                $result = BayesianSync::replay($position, function(string $text, string $label) use ($client, &$queueFull): bool
                {
                    $queueFull = !Learner::learn($client, $text, $label)->isAccepted();
                    return !$queueFull;
                });

                BayesianSync::setPosition($result['position']);
                print(sprintf("Learned %d classification(s)\n", $result['replayed']));
                if($queueFull)
                {
                    print("BayesianServer's learning queue is full, the remaining classifications are learned on the next sync\n");
                }

                return 0;
            }
            catch(BayesianException $e)
            {
                Logger::log()->error('Failed to sync the classifications with BayesianServer: ' . $e->getMessage(), $e);
                print(sprintf("Error: Failed to sync the classifications with BayesianServer: %s\n", $e->getMessage()));
                return 1;
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to sync the classifications with BayesianServer: ' . $e->getMessage(), $e);
                print(sprintf("Error: %s\n", $e->getMessage()));
                return 1;
            }
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationlib bayesian sync [--full]\n" .
                "\nDescription:\n" .
                "  Teaches BayesianServer the evidence classifications made since the last sync, so it learns\n" .
                "  classifications it missed (eg; while it was unavailable or its learning queue was full).\n" .
                "  Does nothing if BayesianPlugin is not enabled or its learning is disabled.\n" .
                "\nOptions:\n" .
                "  --full  Learn every classification again instead of the ones made since the last sync.\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Teaches BayesianServer the evidence classifications made since the last sync.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Example:\n" .
                "  federationlib bayesian sync\n" .
                "  federationlib bayesian sync --full\n";
        }
    }
