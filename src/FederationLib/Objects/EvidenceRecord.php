<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\ContentType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class EvidenceRecord implements SerializableInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private string $entityUuid;
        private string $operatorUuid;
        private bool $confidential;
        private bool $automated;
        private ?string $textContent;
        private ContentType $contentType;
        private ?array $context;
        private ?string $note;
        private ?string $tag;
        private ?string $report;
        private ?array $metadata;
        private ?ClassificationFlag $classificationFlag;
        private int $created;
        private int $updated;

        /**
         * EvidenceRecord constructor.
         *
         * @param array $data Associative array of evidence data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->entityUuid = $data['entity'] ?? '';
            $this->operatorUuid = $data['operator'] ?? '';
            $this->confidential = (bool)$data['confidential'] ?? false;
            $this->automated = (bool)($data['automated'] ?? false);
            $this->textContent = $data['text_content'] ?? null;
            $contentType = $data['content_type'] ?? null;
            if(is_string($contentType))
            {
                $contentType = ContentType::tryFromCaseInsensitive($contentType);
            }
            $this->contentType = $contentType instanceof ContentType ? $contentType : ContentType::TEXT;
            $this->context = null;
            if(isset($data['context']))
            {
                $context = is_string($data['context']) ? @json_decode($data['context'], true) : $data['context'];
                if(is_array($context) && count($context) > 0)
                {
                    $this->context = $context;
                }
            }
            $this->note = $data['note'] ?? null;
            $this->tag = $data['tag'] ?? null;
            $this->report = $data['report'] ?? null;
            $this->metadata = null;
            $this->classificationFlag = null;
            if(isset($data['metadata']))
            {
                if(is_array($data['metadata']))
                {
                    $this->metadata = $data['metadata'];
                }
                elseif(is_string($data['metadata']))
                {
                    $parsed = @json_decode($data['metadata'], true);
                    if(is_array($parsed))
                    {
                        $this->metadata = $parsed;
                    }
                }
            }
            if(isset($data['classification_flag']))
            {
                if(is_string($data['classification_flag']))
                {
                    $this->classificationFlag = ClassificationFlag::tryFrom($data['classification_flag']);
                }
                elseif($data['classification_flag'] instanceof ClassificationFlag)
                {
                    $this->classificationFlag = $data['classification_flag'];
                }
            }

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['created']) && is_string($data['created']))
            {
                // Numeric strings come from the Redis cache (hGetAll returns all hash values as strings)
                $data['created'] = is_numeric($data['created']) ? (int)$data['created'] : strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $data['created'] = $data['created']->getTimestamp();
            }
            else
            {
                $data['created'] = $data['created'] ?? time();
            }

            if(isset($data['updated']) && is_string($data['updated']))
            {
                $data['updated'] = is_numeric($data['updated']) ? (int)$data['updated'] : strtotime($data['updated']);
            }
            elseif(isset($data['updated']) && $data['updated'] instanceof DateTime)
            {
                $data['updated'] = $data['updated']->getTimestamp();
            }
            else
            {
                $data['updated'] = $data['updated'] ?? null;
            }

            $this->created = (int)($data['created'] ?? time());
            $this->updated = (int)$data['updated'] ?? null;
        }

        /**
         * Get the UUID value.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the entity value.
         *
         * @return string
         */
        public function getEntityUuid(): string
        {
            return $this->entityUuid;
        }

        /**
         * Get the operator value.
         *
         * @return string
         */
        public function getOperatorUuid(): string
        {
            return $this->operatorUuid;
        }

        /**
         * Check if the evidence is confidential.
         *
         * @return bool
         */
        public function isConfidential(): bool
        {
            return $this->confidential;
        }

        /**
         * Check if the evidence is automated: created by the host itself or submitted by a bot operator
         *
         * @return bool
         */
        public function isAutomated(): bool
        {
            return $this->automated;
        }

        /**
         * Get the text content value.
         *
         * @return string|null
         */
        public function getTextContent(): ?string
        {
            return $this->textContent;
        }

        /**
         * Returns the content type of the text content, TEXT for evidence submitted without one
         *
         * @return ContentType The content type
         */
        public function getContentType(): ContentType
        {
            return $this->contentType;
        }

        /**
         * Returns the context submitted with the evidence, as the client observed it
         *
         * @return array|null The context, null if none was submitted
         */
        public function getContext(): ?array
        {
            return $this->context;
        }

        /**
         * Get the note value.
         *
         * @return string|null
         */
        public function getNote(): ?string
        {
            return $this->note;
        }

        /**
         * Get the tag name
         *
         * @return string|null
         */
        public function getTag(): ?string
        {
            return $this->tag;
        }

        /**
         * Optional. Returns the Unique Universal Identifier of the report record that this evidence record is
         * associated with
         *
         * @return string|null The report UUID, null = No report assigned with this evidence record.
         */
        public function getReport(): ?string
        {
            return $this->report;
        }

        /**
         * Returns the metadata associated with the evidence record.
         *
         * @return array|null The metadata array, or null if not set
         */
        public function getMetadata(): ?array
        {
            return $this->metadata;
        }

        /**
         * Optional. Returns the classification flag of the record
         *
         * @return ClassificationFlag|null The classification flag of the record
         */
        public function getClassificationFlag(): ?ClassificationFlag
        {
            return $this->classificationFlag;
        }

        /**
         * Get the created timestamp.
         *
         * @return int Returns the creation timestamp of the record
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * Get the updated timestamp
         *
         * @return int|null Returns the updated timestamp of the record, null=Never updated
         */
        public function getUpdated(): ?int
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
                'entity' => $this->entityUuid,
                'operator' => $this->operatorUuid,
                'confidential' => $this->confidential,
                'automated' => $this->automated,
                'text_content' => $this->textContent,
                'content_type' => $this->contentType->value,
                'context' => $this->context,
                'tag' => $this->tag,
                'report' => $this->report,
                'metadata' => $this->metadata,
                'classification_flag' => $this->classificationFlag?->value ?? null,
                'note' => $this->note,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): EvidenceRecord
        {
            if(isset($array['created']))
            {
                if(is_string($array['created']))
                {
                    $array['created'] = is_numeric($array['created']) ? (int)$array['created'] : strtotime($array['created']);
                }
                elseif($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            if(isset($array['updated']))
            {
                if(is_string($array['updated']))
                {
                    $array['updated'] = is_numeric($array['updated']) ? (int)$array['updated'] : strtotime($array['updated']);
                }
                elseif($array['updated'] instanceof DateTime)
                {
                    $array['updated'] = $array['updated']->getTimestamp();
                }
            }

            return new self($array);
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
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Unique identifier for the evidence'],
                'entity' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the entity the evidence relates to'],
                'operator' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the operator who submitted the evidence'],
                'confidential' => ['type' => 'boolean', 'description' => 'Whether the evidence is marked as confidential'],
                'automated' => ['type' => 'boolean', 'description' => 'Whether the evidence is automated: created by the server itself or submitted by a bot operator. Automated evidence is created confidential'],
                'text_content' => ['type' => ['string', 'null'], 'description' => 'Text content of the evidence'],
                'content_type' => [
                    'type' => 'string',
                    'enum' => array_map(fn(ContentType $type) => $type->value, ContentType::cases()),
                    'description' => 'The content type of the evidence, TEXT for evidence submitted without one',
                ],
                'context' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                    'description' => 'The context submitted with the evidence, its keys depend on the content type',
                ],
                'note' => ['type' => ['string', 'null'], 'description' => 'Optional note attached to the evidence'],
                'tag' => ['type' => ['string', 'null'], 'description' => 'Tag categorizing the evidence'],
                'report' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'UUID of the report this evidence is linked to'],
                'metadata' => Utilities::getMetadataSchema('Additional metadata attached to the evidence'),
                'classification_flag' => [
                    'type' => ['string', 'null'],
                    'enum' => [...array_map(fn(ClassificationFlag $flag) => $flag->value, ClassificationFlag::cases()), null],
                    'description' => 'Classification flag assigned to the evidence',
                ],
                'created' => ['type' => 'integer', 'description' => 'Unix timestamp when the evidence was created'],
                'updated' => ['type' => ['integer', 'null'], 'description' => 'Unix timestamp when the evidence was last updated'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'entity', 'operator', 'confidential', 'automated', 'created'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/EvidenceRecord';
        }
    }
