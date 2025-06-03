<?php

    namespace FederationServer\Objects;

    use FederationServer\Interfaces\SerializableInterface;

    class PublicOperatorRecord implements SerializableInterface
    {
        private string $uuid;
        private string $name;
        private bool $isClient;
        private int $created;
        private int $updated;

        public function __construct(array|OperatorRecord $data)
        {
            if(is_array($data))
            {
                $this->uuid = (string)$data['uuid'] ?? '';
                $this->name = (string)$data['name'] ?? '';
                $this->isClient = (bool)$data['is_client'];
                $this->created = (int)$data['created'] ?? 0;
                $this->updated = (int)$data['updated'] ?? 0;
                return;
            }

            $this->uuid = $data->getUuid();
            $this->name = $data->getName();
            $this->isClient = $data->isClient();
            $this->created = $data->getCreated();
            $this->updated = $data->getUpdated();
        }

        public function getUuid(): string
        {
            return $this->uuid;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function isClient(): bool
        {
            return $this->isClient;
        }

        public function getCreated(): int
        {
            return $this->created;
        }

        public function getUpdated(): int
        {
            return $this->updated;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'name' => $this->name,
                'is_client' => $this->isClient,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SerializableInterface
        {
            return new self($array);
        }
    }