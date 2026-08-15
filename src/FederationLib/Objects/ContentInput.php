<?php

    namespace FederationLib\Objects;
    use FederationLib\Interfaces\ObjectSpecificationInterface;

    class ContentInput implements ObjectSpecificationInterface
    {
        private ?string $textContent;
        private ?string $note;
        private ?string $tag;
        private bool $confidential;
        private ?array $metadata;

        /**
         * ContentInput constructor
         *
         * @param string|null $textContent Optional text content of the evidence
         * @param string|null $note Optional note by the operator
         * @param string|null $tag Optional tag name for the evidence
         * @param bool $confidential Whether the evidence is confidential
         * @param array|null $metadata Optional arbitrary metadata
         */
        public function __construct(?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false, ?array $metadata=null)
        {
            $this->textContent = $textContent;
            $this->note = $note;
            $this->tag = $tag;
            $this->confidential = $confidential;
            $this->metadata = $metadata;
        }

        /**
         * Returns the text content of the evidence
         *
         * @return string|null
         */
        public function getTextContent(): ?string
        {
            return $this->textContent;
        }

        /**
         * Returns the operator note for the evidence
         *
         * @return string|null
         */
        public function getNote(): ?string
        {
            return $this->note;
        }

        /**
         * Returns the tag for the evidence
         *
         * @return string|null
         */
        public function getTag(): ?string
        {
            return $this->tag;
        }

        /**
         * Returns whether the evidence is confidential
         *
         * @return bool
         */
        public function isConfidential(): bool
        {
            return $this->confidential;
        }

        /**
         * Returns the metadata for the evidence
         *
         * @return array|null
         */
        public function getMetadata(): ?array
        {
            return $this->metadata;
        }

        /**
         * Converts the input to an array suitable for the report submission request
         *
         * @return array
         */
        public function toArray(): array
        {
            $result = [];

            if($this->textContent !== null)
            {
                $result['text_content'] = $this->textContent;
            }

            if($this->note !== null)
            {
                $result['note'] = $this->note;
            }

            if($this->tag !== null)
            {
                $result['tag'] = $this->tag;
            }

            if($this->confidential)
            {
                $result['confidential'] = true;
            }

            if($this->metadata !== null)
            {
                $result['metadata'] = $this->metadata;
            }

            return $result;
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
                'text_content' => [
                    'type' => 'string',
                    'description' => 'Text content',
                    'nullable' => true,
                ],
                'note' => [
                    'type' => 'string',
                    'description' => 'Optional operator note',
                    'nullable' => true,
                ],
                'tag' => [
                    'type' => 'string',
                    'description' => 'Optional tag',
                    'nullable' => true,
                ],
                'confidential' => [
                    'type' => 'boolean',
                    'description' => 'Whether the content is confidential',
                    'default' => false,
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Optional arbitrary metadata',
                    'nullable' => true,
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return [];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ContentInput';
        }
    }
