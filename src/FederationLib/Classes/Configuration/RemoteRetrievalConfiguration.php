<?php

    namespace FederationLib\Classes\Configuration;

    class RemoteRetrievalConfiguration
    {
        private bool $uploads;
        private bool $scans;
        private bool $scanFiles;
        private int $timeout;
        private bool $allowPrivate;

        /**
         * RemoteRetrievalConfiguration constructor.
         *
         * @param array $configuration The remote retrieval configuration values
         */
        public function __construct(array $configuration)
        {
            $this->uploads = ConfigurationValues::parseBool($configuration['uploads'] ?? null, true);
            $this->scans = ConfigurationValues::parseBool($configuration['scans'] ?? null, false);
            $this->scanFiles = ConfigurationValues::parseBool($configuration['scan_files'] ?? null, false);
            $this->timeout = max(1, ConfigurationValues::parseDuration($configuration['timeout'] ?? 30, 30) ?? 30);
            $this->allowPrivate = ConfigurationValues::parseBool($configuration['allow_private'] ?? null, false);
        }

        /**
         * Checks if attachments may be uploaded from a URL the server retrieves itself (allow_url_uploads).
         *
         * @return bool True if URL uploads are enabled
         */
        public function isUploadsEnabled(): bool
        {
            return $this->uploads;
        }

        /**
         * Checks if plugins may retrieve the URLs submitted for scanning, FederationLib itself never retrieves them.
         *
         * @return bool True if URLs submitted for scanning may be retrieved
         */
        public function isScansEnabled(): bool
        {
            return $this->scans;
        }

        /**
         * Checks if the server retrieves the files that scanned content names by URL (file_urls). The files
         * of scan requests must be enabled as well (scanning.max_files), and file URLs are only retrieved for requests
         * with client permissions.
         *
         * @return bool True if the file URLs of scanned content may be retrieved
         */
        public function isScanFilesEnabled(): bool
        {
            return $this->scanFiles;
        }

        /**
         * Returns the maximum time a retrieval may take, in seconds.
         *
         * @return int The timeout in seconds
         */
        public function getTimeout(): int
        {
            return $this->timeout;
        }

        /**
         * Checks if retrievals may connect to addresses that are not publicly routable (loopback, private, link-local
         * and reserved addresses), which is only safe on trusted networks.
         *
         * @return bool True if private addresses are allowed
         */
        public function isPrivateAllowed(): bool
        {
            return $this->allowPrivate;
        }
    }
