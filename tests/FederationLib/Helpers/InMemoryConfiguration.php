<?php

    namespace FederationLib\Helpers;

    /**
     * A ConfigLib configuration held in memory only, for the test units of code that reads and writes a ConfigLib
     * configuration. The constructor of ConfigLib is never called, so no configuration file is ever resolved, read or
     * written (ConfigLib falls back to the configuration file of the user when its path does not exist), and the
     * destructor never saves.
     */
    class InMemoryConfiguration extends \ConfigLib\Configuration
    {
        private array $data;

        /**
         * @param array $data The initial configuration
         */
        public function __construct(array $data=[])
        {
            $this->data = $data;
        }

        public function get(string $key, mixed $default=null): mixed
        {
            $value = $this->data;
            foreach(explode('.', $key) as $part)
            {
                if(!is_array($value) || !array_key_exists($part, $value))
                {
                    return $default;
                }

                $value = $value[$part];
            }

            return $value;
        }

        public function set(string $key, mixed $value, bool $create=false): bool
        {
            $target = &$this->data;
            foreach(explode('.', $key) as $part)
            {
                if(!isset($target[$part]) || !is_array($target[$part]))
                {
                    $target[$part] = [];
                }

                $target = &$target[$part];
            }

            $target = $value;
            return true;
        }

        public function exists(string $key): bool
        {
            return $this->get($key, $this) !== $this;
        }

        public function setDefault(string $key, mixed $value, ?string $environmentVariable=null, bool $override=true): bool
        {
            if($environmentVariable !== null && getenv($environmentVariable) !== false && $override)
            {
                return $this->set($key, getenv($environmentVariable), true);
            }

            return !$this->exists($key) && $this->set($key, $value, true);
        }

        public function clear(): void
        {
            $this->data = [];
        }

        public function save(): void
        {
        }

        public function load(bool $force=false): void
        {
        }

        public function getName(): string
        {
            return 'in_memory';
        }

        public function getPath(): string
        {
            return '';
        }

        public function getConfiguration(): array
        {
            return $this->data;
        }

        public function __destruct()
        {
        }
    }
