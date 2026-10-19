<?php

    namespace FederationLib\Objects\OperatorRecord;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    /**
     * The permission groups an operator holds (OFD "OperatorPermissions object"). Management permissions inherit client
     * permissions, so client is true whenever management is.
     */
    class OperatorPermissions implements SerializableInterface, ObjectSpecificationInterface
    {
        private bool $client;
        private bool $management;
        private bool $operator;

        /**
         * OperatorPermissions constructor
         *
         * @param bool $client Whether the operator holds client permissions, true whenever management is
         * @param bool $management Whether the operator holds management permissions
         * @param bool $operator Whether the operator holds operator permissions
         */
        public function __construct(bool $client, bool $management, bool $operator)
        {
            $this->client = $client || $management;
            $this->management = $management;
            $this->operator = $operator;
        }

        /**
         * Returns whether the operator holds client permissions, in effect: true whenever it holds management permissions
         *
         * @return bool True if the operator holds client permissions
         */
        public function hasClient(): bool
        {
            return $this->client;
        }

        /**
         * Returns whether the operator holds management permissions
         *
         * @return bool True if the operator holds management permissions
         */
        public function hasManagement(): bool
        {
            return $this->management;
        }

        /**
         * Returns whether the operator holds operator permissions
         *
         * @return bool True if the operator holds operator permissions
         */
        public function hasOperator(): bool
        {
            return $this->operator;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'client' => $this->client,
                'management' => $this->management,
                'operator' => $this->operator,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): OperatorPermissions
        {
            return new self((bool)($array['client'] ?? false), (bool)($array['management'] ?? false), (bool)($array['operator'] ?? false));
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
                'client' => ['type' => 'boolean', 'description' => 'Whether the operator holds client permissions, true whenever management is'],
                'management' => ['type' => 'boolean', 'description' => 'Whether the operator holds management permissions, which inherit client permissions'],
                'operator' => ['type' => 'boolean', 'description' => 'Whether the operator holds operator permissions'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['client', 'management', 'operator'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/OperatorPermissions';
        }
    }
