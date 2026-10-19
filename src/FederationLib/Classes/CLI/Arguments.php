<?php

    namespace FederationLib\Classes\CLI;

    use InvalidArgumentException;

    class Arguments
    {
        /**
         * @var string[]
         */
        private array $positionals;

        /**
         * @var array<string, string|true>
         */
        private array $options;

        /**
         * Arguments constructor
         *
         * @param string[] $positionals The positional arguments in their order
         * @param array<string, string|true> $options The options, true for a flag
         */
        private function __construct(array $positionals, array $options)
        {
            $this->positionals = $positionals;
            $this->options = $options;
        }

        /**
         * Parses the arguments of a command
         *
         * @param string[] $tokens The arguments as passed on the command line, without the program and command names
         * @param string[] $valueOptions The names of the options that take a value, the following argument is their
         *                               value unless it is written `--name=value`
         * @return Arguments The parsed arguments
         */
        public static function parse(array $tokens, array $valueOptions=[]): Arguments
        {
            $positionals = [];
            $options = [];
            $tokens = array_values($tokens);

            for($i = 0; $i < count($tokens); $i++)
            {
                $token = (string)$tokens[$i];
                if($token === '--')
                {
                    array_push($positionals, ...array_map('strval', array_slice($tokens, $i + 1)));
                    break;
                }

                if(!str_starts_with($token, '-') || $token === '-')
                {
                    $positionals[] = $token;
                    continue;
                }

                $name = ltrim($token, '-');
                $value = true;
                if(str_contains($name, '='))
                {
                    [$name, $value] = explode('=', $name, 2);
                }
                elseif(in_array($name, $valueOptions, true) && isset($tokens[$i + 1]) && !str_starts_with((string)$tokens[$i + 1], '--'))
                {
                    $value = (string)$tokens[++$i];
                }

                $options[$name] = $value;
            }

            return new self($positionals, $options);
        }

        /**
         * Returns the positional argument at the given position
         *
         * @param int $index The zero-based position
         * @return string|null The positional argument, null if there is none at the position
         */
        public function get(int $index): ?string
        {
            return $this->positionals[$index] ?? null;
        }

        /**
         * Returns the positional arguments from the given position onwards
         *
         * @param int $offset The zero-based position of the first argument
         * @return string[] The positional arguments
         */
        public function getFrom(int $offset): array
        {
            return array_slice($this->positionals, $offset);
        }

        /**
         * Returns the number of positional arguments
         *
         * @return int The number of positional arguments
         */
        public function count(): int
        {
            return count($this->positionals);
        }

        /**
         * Returns whether the option was given, as a flag or with a value
         *
         * @param string $name The option name, without the leading dashes
         * @return bool True if the option was given
         */
        public function has(string $name): bool
        {
            return array_key_exists($name, $this->options);
        }

        /**
         * Returns the value of an option
         *
         * @param string $name The option name, without the leading dashes
         * @param string|null $default The value when the option was not given
         * @return string|null The value, the default when the option was not given or is a flag without a value
         */
        public function option(string $name, ?string $default=null): ?string
        {
            $value = $this->options[$name] ?? null;
            return is_string($value) ? $value : $default;
        }

        /**
         * Returns the value of an option as a positive integer
         *
         * @param string $name The option name, without the leading dashes
         * @param int $default The value when the option was not given
         * @return int The value
         * @throws InvalidArgumentException If the value is not a positive integer
         */
        public function positiveInt(string $name, int $default): int
        {
            $value = $this->option($name);
            if($value === null)
            {
                return $default;
            }

            if(!ctype_digit($value) || (int)$value < 1)
            {
                throw new InvalidArgumentException(sprintf('--%s must be a positive integer', $name));
            }

            return (int)$value;
        }

        /**
         * Returns the value of an option as a boolean: a flag without a value is true, otherwise the value is one of
         * true, false, yes, no, on, off, 1 or 0
         *
         * @param string $name The option name, without the leading dashes
         * @return bool|null The value, null if the option was not given
         * @throws InvalidArgumentException If the value is not a boolean
         */
        public function bool(string $name): ?bool
        {
            if(!$this->has($name))
            {
                return null;
            }

            $value = $this->options[$name];
            if($value === true)
            {
                return true;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($parsed === null)
            {
                throw new InvalidArgumentException(sprintf('--%s must be true or false', $name));
            }

            return $parsed;
        }

        /**
         * Returns the options that were given but are not in the given list, used to reject unknown options
         *
         * @param string[] $known The option names the command accepts
         * @return string[] The unknown option names
         */
        public function getUnknownOptions(array $known): array
        {
            return array_values(array_diff(array_keys($this->options), $known, ['help', 'h']));
        }

        /**
         * Returns whether help was requested with --help or -h
         *
         * @return bool True if help was requested
         */
        public function wantsHelp(): bool
        {
            return $this->has('help') || $this->has('h');
        }
    }
