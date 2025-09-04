<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Interfaces\SerializableInterface;

    class EvidenceRecord implements SerializableInterface
    {
        private string $uuid;
        private string $entity;
        private string $operator;
        private bool $confidential;
        private ?string $textContent;
        private ?string $note;
        private ?string $tag;
        private int $created;

        /**
         * EvidenceRecord constructor.
         *
         * @param array $data Associative array of evidence data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->entity = $data['entity'] ?? '';
            $this->operator = $data['operator'] ?? '';
            $this->confidential = (bool)$data['confidential'] ?? false;
            $this->textContent = $data['text_content'] ?? null;
            $this->note = $data['note'] ?? null;
            $this->tag = $data['tag'] ?? null;

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['created']) && is_string($data['created']))
            {
                $data['created'] = strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $data['created'] = $data['created']->getTimestamp();
            }
            else
            {
                $data['created'] = $data['created'] ?? time();
            }

            $this->created = (int)($data['created'] ?? time());
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
            return $this->entity;
        }

        /**
         * Get the operator value.
         *
         * @return string
         */
        public function getOperator(): string
        {
            return $this->operator;
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
         * Get the created timestamp.
         *
         * @return int
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'entity' => $this->entity,
                'operator' => $this->operator,
                'confidential' => $this->confidential,
                'text_content' => $this->textContent,
                'note' => $this->note,
                'tag' => $this->tag,
                'created' => $this->created,
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
                    $array['created'] = strtotime($array['created']);
                }
                elseif($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            return new self($array);
        }
    }
