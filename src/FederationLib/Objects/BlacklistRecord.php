<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Interfaces\SerializableInterface;

    class BlacklistRecord implements SerializableInterface
    {
        private string $uuid;
        private string $operatorUuid;
        private string $entityUuid;
        private ?string $evidenceUuid;
        private BlacklistType $type;
        private bool $lifted;
        private ?string $liftedBy;
        private ?int $expires;
        private int $created;

        /**
         * BlacklistRecord constructor.
         *
         * @param array $data Associative array of blacklist data.
         *                    - 'uuid': string, Unique identifier for the blacklist record.
         *                    - 'operator': string, UUID of the operator who created the record.
         *                    - 'entity': string, Entity being blacklisted (e.g., IP address, domain).
         *                    - 'entity': string, Entity being blacklisted (e.g., IP address, domain).
         *                    - 'type': BlacklistType, Type of blacklist (e.g., IP, domain).
         *                    - 'expires': int|null, Timestamp when the blacklist expires, null if permanent.
         *                    - 'created': int, Timestamp when the record was created.
         * @noinspection PhpUnnecessaryBoolCastInspection
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->operatorUuid = $data['operator'] ?? '';
            $this->entityUuid = $data['entity'] ?? '';
            $this->evidenceUuid = $data['evidence'] ?? null;
            if(isset($data['type']) && $data['type'] instanceof BlacklistType)
            {
                $this->type = $data['type'];
            }
            else
            {
                $this->type = isset($data['type']) ? BlacklistType::from($data['type']) : BlacklistType::OTHER;
            }
            /** @noinspection PhpTernaryExpressionCanBeReplacedWithConditionInspection */
            $this->lifted = isset($data['lifted']) ? (bool)$data['lifted'] : false;
            $this->liftedBy = $data['lifted_by'] ?? null;

            // Handle expires field - can be null for permanent blacklists
            if (isset($data['expires']) && $data['expires'] !== null)
            {
                if (is_string($data['expires']))
                {
                    $this->expires = strtotime($data['expires']);
                }
                elseif ($data['expires'] instanceof DateTime)
                {
                    $this->expires = $data['expires']->getTimestamp();
                }
                else
                {
                    $this->expires = (int)$data['expires'];
                }
            }
            else
            {
                $this->expires = null; // Permanent blacklist
            }

            // Parse SQL datetime string to timestamp if necessary
            if (isset($data['created']) && is_string($data['created']))
            {
                $this->created = strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $this->created = $data['created']->getTimestamp();
            }
            else
            {
                $this->created = $data['created'] ?? time();
            }
        }

        /**
         * Get the UUID of the blacklist record.
         *
         * @return string The UUID of the blacklist record.
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the operator UUID who created the blacklist record.
         *
         * @return string The UUID of the operator.
         */
        public function getOperatorUuid(): string
        {
            return $this->operatorUuid;
        }

        /**
         * Get the entity being blacklisted.
         *
         * @return string The entity being blacklisted (e.g., IP address, domain).
         */
        public function getEntityUuid(): string
        {
            return $this->entityUuid;
        }

        /**
         * Get the evidence UUID associated with the blacklist record, if any.
         *
         * @return string|null The UUID of the evidence, or null if not applicable.
         */
        /**
         * @return string|null
         */
        public function getEvidenceUuid(): ?string
        {
            return $this->evidenceUuid;
        }

        /**
         * Get the type of the blacklist record.
         *
         * @return BlacklistType The type of the blacklist record.
         */
        public function getType(): BlacklistType
        {
            return $this->type;
        }

        /**
         * Check if the blacklist record has been lifted.
         *
         * @return bool True if the record is lifted, false otherwise.
         */
        public function isLifted(): bool
        {
            return $this->lifted || ($this->expires !== null && $this->expires < time());
        }

        /**
         * If an operator manually lifted the blacklist, this property would represent the UUID of the operator
         * that made that action.
         *
         * @return string|null The Operator UUID that lifted the blacklist, null otherwise; even if it gets lifted automatically.
         */
        public function getLiftedBy(): ?string
        {
            return $this->liftedBy;
        }

        /**
         * Get the expiration timestamp of the blacklist record.
         *
         * @return int|null The expiration timestamp, or null if the record is permanent.
         */
        public function getExpires(): ?int
        {
            return $this->expires;
        }

        /**
         * Get the timestamp when the blacklist record was created.
         *
         * @return int The creation timestamp.
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
            $data = [
                'uuid' => $this->uuid,
                'operator' => $this->operatorUuid,
                'entity' => $this->entityUuid,
                'evidence' => $this->evidenceUuid,
                'type' => $this->type->value,
                'lifted' => $this->lifted,
                'lifted_by' => $this->liftedBy,
                'created' => $this->created,
            ];

            if($this->expires !== null)
            {
                $data['expires'] = $this->expires;
            }

            return $data;
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): BlacklistRecord
        {
            if(isset($array['expires']))
            {
                if(is_string($array['expires']))
                {
                    $array['expires'] = strtotime($array['expires']);
                }
                elseif($array['expires'] instanceof DateTime)
                {
                    $array['expires'] = $array['expires']->getTimestamp();
                }
            }

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

            if(isset($array['type']) && is_string($array['type']))
            {
                $array['type'] = BlacklistType::from($array['type']);
            }

            return new self($array);
        }
    }