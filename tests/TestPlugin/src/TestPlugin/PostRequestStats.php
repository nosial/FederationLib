<?php

    namespace TestPlugin;

    /**
     * Records the executions of the POST_REQUEST handler to a file so that the test units can observe them through
     * another request, since a POST_REQUEST handler cannot alter a response that was already sent.
     */
    class PostRequestStats
    {
        /**
         * Returns the path of the statistics file
         *
         * @return string The file path
         */
        public static function getPath(): string
        {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'net.nosial.test_plugin.post_request.json';
        }

        /**
         * Reads the recorded statistics
         *
         * @return array The statistics
         */
        public static function read(): array
        {
            $default = ['count' => 0, 'last_response_code' => null, 'last_response_sent' => null, 'last_path' => null];
            if(!is_file(self::getPath()))
            {
                return $default;
            }

            $data = json_decode((string)file_get_contents(self::getPath()), true);
            return is_array($data) ? array_merge($default, $data) : $default;
        }

        /**
         * Records an execution of the POST_REQUEST handler
         *
         * @param int $responseCode The response code of the request
         * @param bool $responseSent True if FederationLib reported the response as sent
         * @param string|null $path The path of the request
         * @return void
         */
        public static function record(int $responseCode, bool $responseSent, ?string $path): void
        {
            $handle = fopen(self::getPath(), 'c+');
            if($handle === false)
            {
                return;
            }

            try
            {
                flock($handle, LOCK_EX);
                $data = json_decode((string)stream_get_contents($handle), true);
                $count = is_array($data) ? (int)($data['count'] ?? 0) : 0;

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode([
                    'count' => $count + 1,
                    'last_response_code' => $responseCode,
                    'last_response_sent' => $responseSent,
                    'last_path' => $path
                ]));
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            finally
            {
                fclose($handle);
            }
        }

        /**
         * Resets the recorded statistics
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
