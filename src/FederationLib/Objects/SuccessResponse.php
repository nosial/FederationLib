<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class SuccessResponse implements SerializableInterface, ObjectSpecificationInterface
    {
        /**
         * Constructor for SuccessResponse
         */
        public function __construct(){}

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return ['success' => true];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array=[]): SuccessResponse
        {
            return new self();
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
                'success' => ['type' => 'boolean', 'description' => 'Indicates the request was successful', 'enum' => [true]]
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['success'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/SuccessResponse';
        }
    }