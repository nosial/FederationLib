<?php

    namespace FederationLib\Objects;

    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\ContentType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use InvalidArgumentException;

    class ContentInput implements ObjectSpecificationInterface
    {
        /**
         * The members a ContentInput object may contain (OFD "ContentInput object")
         */
        public const array MEMBERS = ['text_content', 'content_type', 'context', 'note', 'tag', 'confidential', 'metadata'];

        private ?string $textContent;
        private ?string $note;
        private ?string $tag;
        private bool $confidential;
        private ?array $metadata;
        private ContentType $contentType;
        private ?array $context;

        /**
         * ContentInput constructor
         *
         * @param string|null $textContent Optional text content of the evidence
         * @param string|null $note Optional note by the operator
         * @param string|null $tag Optional tag name for the evidence
         * @param bool $confidential Whether the evidence is confidential
         * @param array|null $metadata Optional arbitrary metadata
         * @param ContentType $contentType Optional. The content type of the text content (default TEXT)
         * @param array|null $context Optional. The context of the content, its keys depend on the content type
         */
        public function __construct(?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false, ?array $metadata=null, ContentType $contentType=ContentType::TEXT, ?array $context=null)
        {
            $this->textContent = $textContent;
            $this->note = $note;
            $this->tag = $tag;
            $this->confidential = $confidential;
            $this->metadata = $metadata;
            $this->contentType = $contentType;
            $this->context = $context;
        }

        /**
         * Parses a ContentInput object received in a request, validating its members, content type and context. The
         * text content is normalized for its content type (eg; a HOSTNAME is converted to lowercase).
         *
         * @param mixed $item The decoded ContentInput object
         * @return ContentInput The parsed ContentInput object
         * @throws InvalidArgumentException If the object is not a valid ContentInput object
         */
        public static function fromRequest(mixed $item): ContentInput
        {
            if(!is_array($item) || (array_is_list($item) && count($item) > 0))
            {
                throw new InvalidArgumentException('Each evidence entry must be an object');
            }

            foreach(array_keys($item) as $key)
            {
                if(!in_array($key, self::MEMBERS, true))
                {
                    throw new InvalidArgumentException(sprintf('Unknown evidence member "%s"', $key));
                }
            }

            foreach(['text_content', 'note', 'tag', 'content_type'] as $member)
            {
                if(isset($item[$member]) && !is_string($item[$member]))
                {
                    throw new InvalidArgumentException(sprintf('The evidence member "%s" must be a string', $member));
                }
            }

            // A confidential member that is not a boolean is rejected rather than read as false, which would expose
            // the evidence; the strings true and false, as carried by form bodies, and 1 and 0 are accepted
            $confidential = false;
            if(isset($item['confidential']))
            {
                $confidential = is_bool($item['confidential']) ? $item['confidential'] : match(is_string($item['confidential']) || is_int($item['confidential']) ? strtolower((string)$item['confidential']) : null)
                {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => throw new InvalidArgumentException('The evidence member "confidential" must be a boolean')
                };
            }

            // Text content is non-empty when present (OFD "ContentInput object"), an empty string is not omitted content
            if(isset($item['text_content']) && $item['text_content'] === '')
            {
                throw new InvalidArgumentException('The evidence member "text_content" must not be empty');
            }

            if(isset($item['metadata']) && !is_array($item['metadata']))
            {
                throw new InvalidArgumentException('The evidence member "metadata" must be an object');
            }

            if(isset($item['context']) && !is_array($item['context']))
            {
                throw new InvalidArgumentException('The evidence member "context" must be an object');
            }

            $contentType = ContentType::TEXT;
            if(isset($item['content_type']))
            {
                $contentType = ContentType::tryFromCaseInsensitive($item['content_type'])
                    ?? throw new InvalidArgumentException('Invalid content type');
            }

            $textContent = isset($item['text_content']) ? $contentType->normalizeTextContent($item['text_content']) : null;
            $context = isset($item['context']) && count($item['context']) > 0 ? $item['context'] : null;
            $contentType->validate($textContent, $context);

            return new self(
                $textContent,
                $item['note'] ?? null,
                $item['tag'] ?? null,
                $confidential,
                $item['metadata'] ?? null,
                $contentType,
                $context
            );
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
         * Returns the content type of the text content, which tells the host how to interpret it
         *
         * @return ContentType The content type
         */
        public function getContentType(): ContentType
        {
            return $this->contentType;
        }

        /**
         * Returns the context of the content as the client observed it, an assertion of the client that the host has
         * not verified (OFD "Client Assertions")
         *
         * @return array|null The context, null if none was provided
         */
        public function getContext(): ?array
        {
            return $this->context;
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

            if($this->contentType !== ContentType::TEXT)
            {
                $result['content_type'] = $this->contentType->value;
            }

            if($this->context !== null)
            {
                $result['context'] = $this->context;
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
                    'type' => ['string', 'null'],
                    'description' => 'Text content, its form depends on the content type',
                ],
                'content_type' => [
                    'type' => 'string',
                    'enum' => array_map(fn(ContentType $type) => $type->value, ContentType::cases()),
                    'default' => ContentType::TEXT->value,
                    'description' => 'The content type, which tells the host how to interpret the text content',
                ],
                'context' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                    'description' => 'Descriptive context of the content as the client observed it, its keys depend on the content type',
                ],
                'note' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional operator note',
                ],
                'tag' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional tag',
                ],
                'confidential' => [
                    'type' => 'boolean',
                    'description' => 'Whether the content is confidential',
                    'default' => false,
                ],
                'metadata' => Utilities::getMetadataSchema('Optional arbitrary metadata'),
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
