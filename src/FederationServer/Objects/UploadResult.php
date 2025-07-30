<?php

    namespace FederationServer\Objects;

    use FederationServer\Interfaces\SerializableInterface;

    class UploadResult implements SerializableInterface
    {
        private string $uuid;
        private string $url;

        /**
         * The UploadResult constructor.
         *
         * @param string $uuid The UUID of the file attachment record
         * @param string $url The URL where the file can be downloaded
         */
        public function __construct(string $uuid, string $url)
        {
            $this->uuid = $uuid;
            $this->url = $url;
        }

        /**
         * Get the UUID of the file attachment record.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the URL where the file can be downloaded.
         *
         * @return string
         */
        public function getUrl(): string
        {
            return $this->url;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'url' => $this->url,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): UploadResult
        {
            if(!isset($array['uuid']) || !isset($array['url']))
            {
                throw new \InvalidArgumentException('Invalid array format for UploadResult');
            }

            return new self($array['uuid'], $array['url']);
        }
    }