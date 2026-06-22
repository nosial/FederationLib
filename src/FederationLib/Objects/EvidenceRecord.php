<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Interfaces\SerializableInterface;

    class EvidenceRecord implements SerializableInterface
    {
        private string $uuid;
        private string $entityUuid;
        private string $operatorUuid;
        private bool $confidential;
        private ?string $textContent;
        private ?string $note;
        private ?string $tag;
        private ?string $report;
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
            $this->textContent = $data['text_content'] ?? null;
            $this->note = $data['note'] ?? null;
            $this->tag = $data['tag'] ?? null;
            $this->report = $data['report'] ?? null;
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
         * Get the text content value.
         *
         * @return string|null
         */
        public function getTextContent(): ?string
        {
            return $this->textContent;
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
                'text_content' => $this->textContent,
                'tag' => $this->tag,
                'report' => $this->report,
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
    }
