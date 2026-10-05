<?php

    namespace TestPlugin;

    use FederationLib\Objects\AuditLog;

    /**
     * Records the audit log entries received by the AUDIT_LOG event handlers to a file so that the test units can
     * observe them through a request
     */
    class AuditLogEvents
    {
        /**
         * The maximum number of recorded events, older events are discarded
         */
        private const int MAX_EVENTS = 500;

        /**
         * Returns the path of the events file
         *
         * @return string The file path
         */
        public static function getPath(): string
        {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'net.nosial.test_plugin.audit_log_events.json';
        }

        /**
         * Reads the recorded events, oldest first
         *
         * @return array[] The recorded events, each with the `handler` that received it and the `audit_log` entry
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
         * Records an audit log entry received by an event handler
         *
         * @param string $handler The name of the event handler that received the entry
         * @param AuditLog $auditLog The audit log entry
         * @return void
         */
        public static function record(string $handler, AuditLog $auditLog): void
        {
            $handle = fopen(self::getPath(), 'c+');
            if($handle === false)
            {
                return;
            }

            try
            {
                flock($handle, LOCK_EX);
                $events = json_decode((string)stream_get_contents($handle), true);
                if(!is_array($events))
                {
                    $events = [];
                }

                $events[] = ['handler' => $handler, 'audit_log' => $auditLog->toArray()];
                $events = array_slice($events, -self::MAX_EVENTS);

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($events));
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            finally
            {
                fclose($handle);
            }
        }

        /**
         * Resets the recorded events
         *
         * @return void
         */
        public static function reset(): void
        {
            if(is_file(self::getPath()))
            {
                @unlink(self::getPath());
            }
        }
    }
