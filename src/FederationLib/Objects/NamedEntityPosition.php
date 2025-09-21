<?php

    namespace FederationLib\Objects;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Interfaces\SerializableInterface;

    class NamedEntityPosition implements SerializableInterface
    {
        private NamedEntityType $type;
        private string $value;
        private int $offset;
        private int $length;

        /**
         * Constructor for the NamedEntityPosition object
         *
         * @param NamedEntityType $type The NamedEntity type
         * @param string $value The actual-cleaned value of the entity
         * @param int $offset The offset of the entity in the given text content
         * @param int $length The length of the entity value in the given text content
         */
        public function __construct(NamedEntityType $type, string $value, int $offset, int $length)
        {
            $this->type = $type;
            $this->value = $value;
            $this->offset = $offset;
            $this->length = $length;
        }

        /**
         * Returns the Named Entity type
         *
         * @return NamedEntityType The named entity type
         */
        public function getType(): NamedEntityType
        {
            return $this->type;
        }

        /**
         * Returns the named entity value
         *
         * @return string The value of the named entity
         */
        public function getValue(): string
        {
            return $this->value;
        }

        /**
         * Returns the offset of the named entity based off the given text content
         *
         * @return int The offset number based off the given text content
         */
        public function getOffset(): int
        {
            return $this->offset;
        }

        /**
         * Returns the length of the named entity value based off the given text content
         *
         * @return int The length of the entity value in the given text content
         */
        public function getLength(): int
        {
            return $this->length;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'type' => $this->type->value,
                'value' => $this->value,
                'offset' => $this->offset,
                'length' => $this->length
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): NamedEntityPosition
        {
            return new self(
                type: NamedEntityType::tryFrom($array['type']),
                value: $array['value'] ?? '',
                offset: $array['offset'] ?? 0,
                length: $array['length'] ?? 0,
            );
        }
    }