<?php

    namespace FederationLib\Objects\ScannedContent;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class Detection implements SerializableInterface, ObjectSpecificationInterface
    {
        private IncidentType $concern;
        private int $contentIndex;
        private ?string $entity;
        private string $source;
        private ?float $confidence;
        private bool $verified;
        private ?int $fileIndex;

        /**
         * Detection constructor
         *
         * @param IncidentType $concern The concern the detection relates to
         * @param int $contentIndex The zero-based position of the ContentInput object the detection relates to
         * @param string $source A host-defined name of the mechanism that made the detection (eg; a filter list)
         * @param string|null $entity Optional. The UUID of the entity the detection relates to
         * @param float|null $confidence Optional. A host-defined confidence of the detection
         * @param bool $verified False when the detection relies on context or other assertions of the client that the
         *                       host has not verified, true when it relies only on the submitted content, the records
         *                       of the host and information the host determined itself
         * @param int|null $fileIndex Optional. The zero-based position of the file, within the files of the ContentInput
         *                            object, the detection relates to, null when it does not relate to a file
         */
        public function __construct(IncidentType $concern, int $contentIndex, string $source, ?string $entity=null, ?float $confidence=null, bool $verified=true, ?int $fileIndex=null)
        {
            $this->fileIndex = $fileIndex;
            $this->concern = $concern;
            $this->contentIndex = $contentIndex;
            $this->source = $source;
            $this->entity = $entity;
            $this->confidence = $confidence;
            $this->verified = $verified;
        }

        /**
         * Returns the concern the detection relates to
         *
         * @return IncidentType The concern
         */
        public function getConcern(): IncidentType
        {
            return $this->concern;
        }

        /**
         * Returns the zero-based position of the ContentInput object the detection relates to
         *
         * @return int The content index
         */
        public function getContentIndex(): int
        {
            return $this->contentIndex;
        }

        /**
         * Returns the zero-based position of the file, within the files of the ContentInput object, the detection
         * relates to
         *
         * @return int|null The file index, null if the detection does not relate to a file
         */
        public function getFileIndex(): ?int
        {
            return $this->fileIndex;
        }

        /**
         * Returns the UUID of the entity the detection relates to
         *
         * @return string|null The entity UUID, null if the detection does not relate to an entity
         */
        public function getEntity(): ?string
        {
            return $this->entity;
        }

        /**
         * Returns the host-defined name of the mechanism that made the detection
         *
         * @return string The source
         */
        public function getSource(): string
        {
            return $this->source;
        }

        /**
         * Returns the host-defined confidence of the detection
         *
         * @return float|null The confidence, null if the mechanism provides none
         */
        public function getConfidence(): ?float
        {
            return $this->confidence;
        }

        /**
         * Returns whether the detection relies only on information the host verified, a detection that relies on
         * unverified client assertions should be weighed as no stronger than the context the client supplied
         *
         * @return bool True if verified
         */
        public function isVerified(): bool
        {
            return $this->verified;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'concern' => $this->concern->value,
                'content_index' => $this->contentIndex,
                'file_index' => $this->fileIndex,
                'entity' => $this->entity,
                'source' => $this->source,
                'confidence' => $this->confidence,
                'verified' => $this->verified,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): Detection
        {
            return new self(
                IncidentType::fromResponse((string)($array['concern'] ?? '')),
                (int)($array['content_index'] ?? 0),
                (string)($array['source'] ?? ''),
                $array['entity'] ?? null,
                isset($array['confidence']) ? (float)$array['confidence'] : null,
                (bool)($array['verified'] ?? false),
                isset($array['file_index']) ? (int)$array['file_index'] : null
            );
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
                'concern' => [
                    'type' => 'string',
                    'enum' => array_map(fn(IncidentType $type) => $type->value, IncidentType::cases()),
                    'description' => 'The concern the detection relates to, one of the effective concerns of the request',
                ],
                'content_index' => ['type' => 'integer', 'description' => 'The zero-based position, in the evidence member of the request, of the content the detection relates to'],
                'file_index' => ['type' => ['integer', 'null'], 'description' => 'The zero-based position of the file, within the files of that content, the detection relates to; null when it does not relate to a file'],
                'entity' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'The UUID of the entity the detection relates to, when it relates to an entity resolved from the content'],
                'source' => ['type' => 'string', 'description' => 'A host-defined name of the mechanism that made the detection, clients must not rely on particular values'],
                'confidence' => ['type' => ['number', 'null'], 'description' => 'A host-defined confidence of the detection, when the mechanism provides one'],
                'verified' => ['type' => 'boolean', 'description' => 'False when the detection relies on context or other assertions of the client that the host has not verified'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['concern', 'content_index', 'file_index', 'entity', 'source', 'confidence', 'verified'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/Detection';
        }
    }
