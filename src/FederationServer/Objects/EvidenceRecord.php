<?php

    namespace FederationServer\Objects;

    use FederationServer\Interfaces\SerializableInterface;

    class EvidenceRecord implements SerializableInterface
    {
        private string $uuid;
        private ?string $blacklist;
        private string $entity;
        private string $operator;
        private ?string $textContent;
        private ?string $note;
        private int $created;

        /**
         * EvidenceRecord constructor.
         *
         * @param array $data Associative array of evidence data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->blacklist = $data['blacklist'] ?? null;
            $this->entity = $data['entity'] ?? '';
            $this->operator = $data['operator'] ?? '';
            $this->textContent = $data['text_content'] ?? null;
            $this->note = $data['note'] ?? null;
            $this->created = isset($data['created']) ? (int)$data['created'] : time();
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
         * Get the blacklist value.
         *
         * @return string|null
         */
        public function getBlacklist(): ?string
        {
            return $this->blacklist;
        }

        /**
         * Get the entity value.
         *
         * @return string
         */
        public function getEntity(): string
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
                'blacklist' => $this->blacklist,
                'entity' => $this->entity,
                'operator' => $this->operator,
                'text_content' => $this->textContent,
                'note' => $this->note,
                'created' => $this->created,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SerializableInterface
        {
            if(isset($array['created']))
            {
                if(is_string($array['created']))
                {
                    $array['created'] = strtotime($array['created']);
                }
                elseif($array['created'] instanceof \DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            return new self($array);
        }
    }

