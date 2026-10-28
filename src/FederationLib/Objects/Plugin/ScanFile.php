<?php

    namespace FederationLib\Objects\Plugin;

    class ScanFile
    {
        private int $index;
        private string $fileName;
        private string $path;
        private int $size;
        private string $mimeType;
        private ?string $sha256;

        /**
         * ScanFile constructor
         *
         * @param int $index The zero-based position of the file within the files of its ContentInput object
         * @param string $fileName The file name supplied by the client, reduced to a safe form
         * @param string $path The path of the temporary file holding the uploaded file
         * @param int $size The size of the file in bytes
         * @param string $mimeType The media type of the file, as determined by the host from its content
         */
        public function __construct(int $index, string $fileName, string $path, int $size, string $mimeType)
        {
            $this->index = $index;
            $this->fileName = $fileName;
            $this->path = $path;
            $this->size = $size;
            $this->mimeType = $mimeType;
            $this->sha256 = null;
        }

        /**
         * Returns the zero-based position of the file within the files of its ContentInput object, the file index a
         * detection relating to the file is added with (see ContentScan::addDetection())
         *
         * @return int The file index
         */
        public function getIndex(): int
        {
            return $this->index;
        }

        /**
         * Returns the file name supplied by the client, reduced to a safe form. It is an assertion of the client and
         * says nothing reliable about the content of the file.
         *
         * @return string The file name
         */
        public function getFileName(): string
        {
            return $this->fileName;
        }

        /**
         * Returns the path of the temporary file holding the uploaded file. The file is hostile content submitted by
         * the requester: it must only be read, never executed, moved, modified or served, and should only be decoded
         * in an isolated process with bounded resources (OFD "Uploaded Files"). It is deleted when the request ends,
         * a handler that needs to keep it must copy it, and only as permitted by OFD "Scanned files".
         *
         * @return string The path of the file
         */
        public function getPath(): string
        {
            return $this->path;
        }

        /**
         * Returns the size of the file in bytes
         *
         * @return int The size of the file
         */
        public function getSize(): int
        {
            return $this->size;
        }

        /**
         * Returns the media type of the file as determined by the host from its content, never the media type the
         * client declared
         *
         * @return string The media type, eg; image/png
         */
        public function getMimeType(): string
        {
            return $this->mimeType;
        }

        /**
         * Returns the SHA-256 hash of the content of the file, calculated on first use
         *
         * @return string The lowercase hexadecimal SHA-256 hash
         */
        public function getSha256(): string
        {
            if($this->sha256 === null)
            {
                $this->sha256 = (string)hash_file('sha256', $this->path);
            }

            return $this->sha256;
        }
    }
