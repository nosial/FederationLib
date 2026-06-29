<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class UploadResult implements SerializableInterface, ObjectSpecificationInterface
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

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Unique identifier for the uploaded file'],
                'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'URL to download the uploaded file'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'url'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/UploadResult';
        }
    }