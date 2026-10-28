<?php

    namespace FederationLib\Helpers;

    use RuntimeException;

    /**
     * Local files for the attachment tests of a ServerTestCase: files to upload, directories to download into, and a
     * local HTTP server for the uploads from a URL, which the client retrieves itself. Everything created is registered
     * in the temporary files of the test and the HTTP server is stopped after it.
     */
    trait AttachmentFiles
    {
        /** @var resource|null */
        private $httpServerProcess = null;
        private ?int $httpServerPort = null;
        private ?string $httpServerRoot = null;

        protected function tearDown(): void
        {
            if (is_resource($this->httpServerProcess))
            {
                proc_terminate($this->httpServerProcess, 9);
                proc_close($this->httpServerProcess);
            }

            $this->httpServerProcess = null;
            $this->httpServerPort = null;
            $this->httpServerRoot = null;

            parent::tearDown();
        }

        /**
         * Creates a directory of its own for the test, since a download never overwrites an existing file
         */
        protected function createTemporaryDirectory(string $prefix = 'federation_download_'): string
        {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16));
            if (!mkdir($directory, 0700))
            {
                throw new RuntimeException('Failed to create temporary directory: ' . $directory);
            }

            $this->tempFiles[] = $directory;
            return $directory;
        }

        /**
         * Creates a file with the given name and content to upload, in a directory of its own
         */
        protected function createTestFile(string $fileName, string $content): string
        {
            $filePath = $this->createTemporaryDirectory('federation_upload_') . DIRECTORY_SEPARATOR . $fileName;
            if (file_put_contents($filePath, $content) === false)
            {
                throw new RuntimeException("Failed to create test file: $filePath");
            }

            return $filePath;
        }

        /**
         * Creates a valid 1x1 PNG image to upload
         */
        protected function createMinimalPng(string $fileName = 'image.png'): string
        {
            return $this->createTestFile($fileName, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        }

        /**
         * Serves a file from the local HTTP server, which is started on first use, and returns its URL. A file with the
         * .php extension is executed by the server.
         */
        protected function serveFile(string $fileName, string $content): string
        {
            if ($this->httpServerProcess === null)
            {
                $this->startHttpServer();
            }

            if (file_put_contents($this->httpServerRoot . DIRECTORY_SEPARATOR . $fileName, $content) === false)
            {
                throw new RuntimeException("Failed to create HTTP server file: $fileName");
            }

            return sprintf('http://127.0.0.1:%d/%s', $this->httpServerPort, $fileName);
        }

        private function startHttpServer(): void
        {
            $this->httpServerRoot = $this->createTemporaryDirectory('federation_http_');

            $socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false || !@\socket_bind($socket, '127.0.0.1', 0) || !@\socket_getsockname($socket, $address, $port))
            {
                throw new RuntimeException('Failed to find available port for HTTP server');
            }
            \socket_close($socket);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];

            $command = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($this->httpServerRoot));
            $this->httpServerProcess = proc_open($command, $descriptors, $pipes);
            if (!is_resource($this->httpServerProcess))
            {
                throw new RuntimeException('Failed to start HTTP server');
            }

            $this->httpServerPort = $port;
            $started = microtime(true);
            while (microtime(true) - $started < 5)
            {
                $handle = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($handle !== false)
                {
                    fclose($handle);
                    return;
                }
                usleep(100000);
            }

            throw new RuntimeException('HTTP server did not start in time');
        }
    }
