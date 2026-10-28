<?php

    namespace FederationLib\Helpers;

    use FederationLib\Enums\ClassificationFlag;

    class TextGenerator
    {
        private const int TRAINING_SAMPLES_PER_CLASS = 15;

        /**
         * @var array<string, string[]>|null
         */
        private static ?array $pools = null;

        /**
         * Returns the samples of the given flag, loading the data files once
         *
         * @return string[]
         */
        private static function pool(ClassificationFlag $flag): array
        {
            if (self::$pools === null)
            {
                self::$pools = [];
                foreach (ClassificationFlag::cases() as $case)
                {
                    $lines = @file(__DIR__ . '/data/' . strtolower($case->value) . '.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    self::$pools[$case->value] = $lines === false ? [] : $lines;
                }
            }

            return self::$pools[$flag->value];
        }

        /**
         * Returns a training sample of the flag by index
         */
        public static function trainingText(ClassificationFlag $flag, int $index = 0): string
        {
            $samples = self::trainingSamples($flag);
            return $samples === [] ? '' : $samples[max(0, $index) % count($samples)];
        }

        /**
         * Returns all training samples of the flag
         *
         * @return string[]
         */
        public static function trainingSamples(ClassificationFlag $flag): array
        {
            return array_slice(self::pool($flag), 0, self::TRAINING_SAMPLES_PER_CLASS);
        }
    }
