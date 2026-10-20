<?php

    namespace TestPlugin;

    use FederationLib\Objects\Plugin\Maintenance;
    use FederationLib\Objects\Plugin\Startup;

    /**
     * Records the STARTUP and MAINTENANCE events received by the TestPlugin to a file so that the test units can
     * observe them through a request. The STARTUP event is recorded by `federationlib init` when the container starts,
     * a different process than the web server, so the file is left writable by both.
     */
    class LifecycleEvents
    {
        /**
         * Returns the path of the events file
         *
         * @return string The file path
         */
        public static function getPath(): string
        {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'net.nosial.test_plugin.lifecycle_events.json';
        }

        /**
         * Reads the recorded events, oldest first
         *
         * @return array[] The recorded events
         */
        public static function read(): array
        {
            if(!is_file(self::getPath()))
            {
                return [];
            }

            $data = json_decode((string)file_get_contents(self::getPath()), true);
            return is_array($data) ? $data : [];
        }

        /**
         * Records the start of the server
         *
         * @param Startup $startup The start of the server
         * @return void
         */
        public static function recordStartup(Startup $startup): void
        {
            self::append([
                'event' => 'STARTUP',
                'started_at' => $startup->getStartedAt(),
                'database_created' => $startup->isDatabaseCreated(),
                'migrated_from' => $startup->getMigratedFrom(),
                'schema_version' => $startup->getSchemaVersion(),
            ]);
        }

        /**
         * Records a maintenance run
         *
         * @param Maintenance $maintenance The maintenance run
         * @return void
         */
        public static function recordMaintenance(Maintenance $maintenance): void
        {
            self::append([
                'event' => 'MAINTENANCE',
                'started_at' => $maintenance->getStartedAt(),
                'cleaning_enabled' => $maintenance->isCleaningEnabled(),
                'archive_path' => $maintenance->getArchivePath(),
                'removed' => $maintenance->getRemoved(),
            ]);
        }

        /**
         * Removes the recorded MAINTENANCE events, the STARTUP events are kept since the server only starts once
         *
         * @return void
         */
        public static function resetMaintenance(): void
        {
            self::write(array_values(array_filter(self::read(), fn(array $event) => $event['event'] !== 'MAINTENANCE')));
        }

        /**
         * Appends an event to the events file
         *
         * @param array $event The event
         * @return void
         */
        private static function append(array $event): void
        {
            $events = self::read();
            $events[] = $event;
            self::write($events);
        }

        /**
         * Writes the events file, readable and writable by every process of the container
         *
         * @param array[] $events The events
         * @return void
         */
        private static function write(array $events): void
        {
            file_put_contents(self::getPath(), json_encode($events), LOCK_EX);
            @chmod(self::getPath(), 0666);
        }
    }
