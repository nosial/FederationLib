<?php

    namespace FederationLib\Helpers;

    use FederationLib\Enums\ClassificationFlag;

    /**
     * Provides curated, deterministic text samples for classification tests.
     *
     * Samples are stored in plain text files (one sentence per line) so they are
     * easy to review and extend without changing code. The default helpers return
     * clean text without random noise. A separate noisy() helper is available for
     * the few tests that specifically need gibberish input.
     */
    class TextGenerator
    {
        private const int TRAINING_SAMPLES_PER_CLASS = 15;
        private const int TEST_SAMPLE_INDEX = 15;

        /**
         * @var array<string, string[]>|null
         */
        private static ?array $pools = null;

        /**
         * @var array<string, int>
         */
        private static array $counters = [];

        /**
         * Load the sample pools from the data files once.
         */
        private static function loadPools(): void
        {
            if (self::$pools !== null)
            {
                return;
            }

            self::$pools = [];
            foreach (ClassificationFlag::cases() as $flag)
            {
                $path = __DIR__ . '/data/' . strtolower($flag->value) . '.txt';
                if (!is_file($path))
                {
                    self::$pools[$flag->value] = [];
                    continue;
                }

                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                self::$pools[$flag->value] = $lines === false ? [] : $lines;
            }
        }

        /**
         * Return the full sample pool for the given flag.
         *
         * @return string[]
         */
        private static function pool(ClassificationFlag $flag): array
        {
            self::loadPools();
            return self::$pools[$flag->value] ?? self::$pools[ClassificationFlag::NORMAL->value] ?? [];
        }

        /**
         * Return a specific curated message for the given flag.
         */
        public static function text(ClassificationFlag $flag, int $index = 0): string
        {
            $pool = self::pool($flag);
            if ($pool === [])
            {
                return '';
            }

            $index = max(0, $index) % count($pool);
            return $pool[$index];
        }

        /**
         * Return the next message in rotation for the flag. This gives deterministic
         * variety across multiple calls while keeping tests reproducible.
         */
        public static function next(ClassificationFlag $flag): string
        {
            $pool = self::pool($flag);
            if ($pool === [])
            {
                return '';
            }

            if (!isset(self::$counters[$flag->value]))
            {
                self::$counters[$flag->value] = 0;
            }

            $index = self::$counters[$flag->value] % count($pool);
            self::$counters[$flag->value]++;
            return $pool[$index];
        }

        /**
         * Return a clean training sample by index.
         */
        public static function trainingText(ClassificationFlag $flag, int $index = 0): string
        {
            $pool = self::pool($flag);
            $limit = min(self::TRAINING_SAMPLES_PER_CLASS, count($pool));
            if ($limit === 0)
            {
                return '';
            }

            $index = max(0, $index) % $limit;
            return $pool[$index];
        }

        /**
         * Return all clean training samples for a flag.
         *
         * @return string[]
         */
        public static function trainingSamples(ClassificationFlag $flag): array
        {
            return array_slice(self::pool($flag), 0, self::TRAINING_SAMPLES_PER_CLASS);
        }

        /**
         * Return a clean test sample that is not part of the default training set.
         */
        public static function testText(ClassificationFlag $flag): string
        {
            return self::text($flag, self::TEST_SAMPLE_INDEX);
        }

        /**
         * Return a deterministic batch of clean samples for every flag.
         *
         * @return array<int, array{text: string, flag: ClassificationFlag}>
         */
        public static function batch(int $perClass = 5): array
        {
            $batch = [];

            foreach (ClassificationFlag::cases() as $flag)
            {
                $samples = self::trainingSamples($flag);
                $poolSize = count($samples);
                if ($poolSize === 0)
                {
                    continue;
                }

                for ($i = 0; $i < $perClass; $i++)
                {
                    $batch[] = [
                        'text' => $samples[$i % $poolSize],
                        'flag' => $flag,
                    ];
                }
            }

            return $batch;
        }

        /**
         * Return the full clean training set for all flags.
         *
         * @return array<int, array{text: string, flag: ClassificationFlag}>
         */
        public static function trainingSet(): array
        {
            $set = [];

            foreach (ClassificationFlag::cases() as $flag)
            {
                foreach (self::trainingSamples($flag) as $text)
                {
                    $set[] = [
                        'text' => $text,
                        'flag' => $flag,
                    ];
                }
            }

            return $set;
        }

        /**
         * Backwards-compatible alias for next(). The gibberish ratio parameter is
         * ignored because the default helpers no longer add noise.
         */
        public static function generate(ClassificationFlag $flag, float $gibberishRatio = 0.0): string
        {
            return self::next($flag);
        }

        /**
         * Backwards-compatible alias for batch(). The word-count parameters are
         * ignored because the helpers no longer synthesize random text.
         */
        public static function generateBatch(int $perClass = 10, int $minWords = 5, int $maxWords = 25): array
        {
            return self::batch($perClass);
        }

        /**
         * Return a sample corrupted with random gibberish. Only use this when a
         * test specifically needs noisy content.
         */
        public static function noisy(ClassificationFlag $flag, int $index = 0, float $gibberishRatio = 0.5): string
        {
            $text = self::text($flag, $index);
            $gibberishRatio = max(0.0, min(1.0, $gibberishRatio));

            if ($gibberishRatio <= 0.0 || $text === '')
            {
                return $text;
            }

            $threshold = (int)($gibberishRatio * 100);
            $tokens = explode(' ', $text);
            $parts = [];
            $chars = 'abcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';
            $maxIndex = strlen($chars) - 1;

            foreach ($tokens as $token)
            {
                $clean = preg_replace('/[^a-zA-Z0-9]/', '', $token);
                if (mt_rand(1, 100) > $threshold || $clean === '')
                {
                    $parts[] = $token;
                }
                else
                {
                    $gibberish = '';
                    $length = max(3, strlen($clean));
                    for ($i = 0; $i < $length; $i++)
                    {
                        $gibberish .= $chars[mt_rand(0, $maxIndex)];
                    }
                    $parts[] = $gibberish;
                }
            }

            return implode(' ', $parts);
        }
    }
