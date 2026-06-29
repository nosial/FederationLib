<?php

    namespace FederationLib\Objects\ScannedContent;

    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class ResolvedEntityPosition implements SerializableInterface, ObjectSpecificationInterface
    {
        private int $offset;
        private int $length;
        private NamedEntityType $type;

        /**
         * ResolvedEntityPosition Constructor
         *
         * @param int $offset The offset of the named entity mention
         * @param int $length The length of the named entity mention
         * @param NamedEntityType $type The type of NamedEntity detection
         */
        public function __construct(int $offset, int $length, NamedEntityType $type)
        {
            $this->offset = $offset;
            $this->length = $length;
            $this->type = $type;
        }

        /**
         * Returns the offset in the text that the named entity was found in
         *
         * @return int The offset within the text
         */
        public function getOffset(): int
        {
            return $this->offset;
        }

        /**
         * Returns the length of the detected content that the named entity was found in
         *
         * @return int The length of the named eneity mention
         */
        public function getLength(): int
        {
            return $this->length;
        }

        /**
         * Returns the NamedEntityType
         *
         * @return NamedEntityType The NamedEntityType
         */
        public function getType(): NamedEntityType
        {
            return $this->type;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'offset' => $this->offset,
                'length' => $this->length,
                'type' => $this->type->value
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ResolvedEntityPosition
        {
            return new self($array['offset'], $array['length'], NamedEntityType::tryFrom($array['type']));
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
                'offset' => ['type' => 'integer', 'description' => 'Offset of the named entity mention within the text'],
                'length' => ['type' => 'integer', 'description' => 'Length of the named entity mention within the text'],
                'type' => ['type' => 'string', 'description' => 'Type of named entity detection'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['offset', 'length', 'type'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ResolvedEntityPosition';
        }
    }