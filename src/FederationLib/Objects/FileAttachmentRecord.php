<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Classes\Configuration;
    use FederationLib\Interfaces\SerializableInterface;

    class FileAttachmentRecord implements SerializableInterface
    {
        private string $uuid;
        private string $evidenceUuid;
        private string $fileName;
        private int $fileSize;
        private string $fileMime;
        private int $created;

        /**
         * FileAttachmentRecord constructor.
         *
         * @param array $data Associative array of file attachment data.
         *                    - 'uuid': string, Unique identifier for the file attachment.
         *                    - 'evidence': string, UUID of the associated evidence record.
         *                    - 'file_name': string, Name of the file.
         *                    - 'file_size': int, Size of the file in bytes.
         *                    - 'file_mime': string, The MIME of the file
         *                    - 'created': int, Timestamp of when the record was created.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->evidenceUuid = $data['evidence'] ?? '';
            $this->fileName = $data['file_name'] ?? '';
            $this->fileSize = isset($data['file_size']) ? (int)$data['file_size'] : 0;
            $this->fileMime = $data['file_mime'] ?? '';

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['created']) && is_string($data['created']))
            {
                $data['created'] = strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $data['created'] = $data['created']->getTimestamp();
            }
            else
            {
                $data['created'] = $data['created'] ?? time();
            }

            $this->created = (int)($data['created'] ?? time());
        }

        /**
         * Get the UUID of the file attachment.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the UUID of the associated evidence record.
         *
         * @return string
         */
        public function getEvidenceUuid(): string
        {
            return $this->evidenceUuid;
        }

        /**
         * Get the name of the file.
         *
         * @return string
         */
        public function getFileName(): string
        {
            return $this->fileName;
        }

        /**
         * Get the size of the file in bytes.
         *
         * @return int
         */
        public function getFileSize(): int
        {
            return $this->fileSize;
        }

        /**
         * Get the MIME type of the file.
         *
         * @return string
         */
        public function getFileMime(): string
        {
            return $this->fileMime;
        }

        /**
         * Get the timestamp of when the record was created.
         *
         * @return int
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * Returns the realpath of where the file is physically located on the disk
         *
         * @return string The realpath of the physical file location
         */
        public function getFilePath(): string
        {
            return realpath(rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->uuid);
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'evidence' => $this->evidenceUuid,
                'file_name' => $this->fileName,
                'file_size' => $this->fileSize,
                'file_mime' => $this->fileMime,
                'created' => $this->created,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): FileAttachmentRecord
        {
            if(isset($array['created']))
            {
                if(is_string($array['created']))
                {
                    $array['created'] = strtotime($array['created']);
                }
                elseif($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            return new self($array);
        }
    }