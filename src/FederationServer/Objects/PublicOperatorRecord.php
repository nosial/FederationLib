<?php

    namespace FederationServer\Objects;

    use FederationServer\Interfaces\SerializableInterface;

    class PublicOperatorRecord implements SerializableInterface
    {
        private string $uuid;
        private string $name;
        private bool $isClient;
        private bool $manageOperators;
        private bool $manageBlacklist;
        private bool $disabled;
        private int $created;
        private int $updated;

        public function __construct(array|OperatorRecord $data)
        {
            if(is_array($data))
            {
                $this->uuid = (string)$data['uuid'] ?? '';
                $this->name = (string)$data['name'] ?? '';
                $this->isClient = (bool)$data['is_client'];
                $this->manageOperators = (bool)$data['manage_operators'] ?? false;
                $this->manageBlacklist = (bool)$data['manage_blacklist'] ?? false;
                $this->disabled = (bool)$data['disabled'] ?? false;
                $this->created = (int)$data['created'] ?? 0;
                $this->updated = (int)$data['updated'] ?? 0;
                return;
            }

            $this->uuid = $data->getUuid();
            $this->name = $data->getName();
            $this->isClient = $data->isClient();
            $this->manageOperators = $data->canManageOperators();
            $this->manageBlacklist = $data->canManageBlacklist();
            $this->disabled = $data->isDisabled();
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

        public function canManageOperators(): bool
        {
            return $this->manageOperators;
        }

        public function canManageBlacklist(): bool
        {
            return $this->manageBlacklist;
        }

        public function isDisabled(): bool
        {
            return $this->disabled;
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
                'manage_operators' => $this->manageOperators,
                'manage_blacklist' => $this->manageBlacklist,
                'disabled' => $this->disabled,
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