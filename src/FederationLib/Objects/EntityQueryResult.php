<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\StandardObjectInterface;

    class EntityQueryResult implements StandardObjectInterface, ObjectSpecificationInterface
    {
        private EntityRecord $entityRecord;
        /**
         * @var EntityRecord[]
         */
        private array $relatedEntities;
        /**
         * @var BlacklistRecord[]
         */
        private array $activeBlacklists;
        private bool $suggestionOverridden;
        private ?SuggestedActionType $suggestedAction;
        private ?int $suggestedLiftTimestamp;
        /**
         * @var IncidentType[]|null
         */
        private ?array $concerns;

        /**
         * @param EntityRecord $entityRecord The queried entity.
         * @param EntityRecord[] $relatedEntities Entities in the queried entity's relationship group.
         * @param BlacklistRecord[] $activeBlacklists Active blacklist records for the queried entity and its relationship group.
         * @param IncidentType[]|null $concerns The effective concerns of the request, only active blacklist records of
         *                                      these incident types determine the suggested action, null considers all
         */
        public function __construct(EntityRecord $entityRecord, array $relatedEntities, array $activeBlacklists=[], ?array $concerns=null)
        {
            $this->concerns = $concerns !== null ? array_values($concerns) : null;
            $this->entityRecord = $entityRecord;
            $this->relatedEntities = array_values(array_filter($relatedEntities, fn($entity) => $entity instanceof EntityRecord));
            $this->activeBlacklists = array_values(array_filter($activeBlacklists, fn($blacklist) => $blacklist instanceof BlacklistRecord && !$blacklist->isLifted()));
            $this->suggestionOverridden = false;
            $this->suggestedAction = null;
            $this->suggestedLiftTimestamp = null;
        }

        /**
         * Overrides the suggested action and lift timestamp, which are otherwise derived from the active blacklists
         * (eg; by a QUERY_ENTITY event handler of a plugin)
         *
         * @param SuggestedActionType|null $action The suggested action, null to suggest no action
         * @param int|null $liftTimestamp The suggested lift timestamp
         * @return void
         */
        public function overrideSuggestedAction(?SuggestedActionType $action, ?int $liftTimestamp): void
        {
            $this->suggestionOverridden = true;
            $this->suggestedAction = $action;
            $this->suggestedLiftTimestamp = $liftTimestamp;
        }

        /**
         * Returns the queried entity.
         */
        public function getEntityRecord(): EntityRecord
        {
            return $this->entityRecord;
        }

        /**
         * Returns the other entities in the queried entity's relationship group.
         *
         * @return EntityRecord[]
         */
        public function getRelatedEntities(): array
        {
            return $this->relatedEntities;
        }

        /**
         * Returns active blacklist records for the queried entity and related entities.
         *
         * @return BlacklistRecord[]
         */
        public function getActiveBlacklists(): array
        {
            return $this->activeBlacklists;
        }

        /**
         * Returns the active blacklist records whose incident type is an effective concern of the request, which alone
         * determine the suggested action; every active blacklist record is still returned (OFD "Concerns")
         *
         * @return BlacklistRecord[] The relevant active blacklist records
         */
        private function getRelevantBlacklists(): array
        {
            if($this->concerns === null)
            {
                return $this->activeBlacklists;
            }

            return array_values(array_filter($this->activeBlacklists,
                fn(BlacklistRecord $blacklist) => in_array($blacklist->getType(), $this->concerns, true)
            ));
        }

        /**
         * Returns the action suggested by active blacklist records.
         *
         * A permanent target blacklist permanently blocks the target. Any other
         * active blacklist in the relationship group temporarily blocks the target,
         * unless the suggested action was overridden.
         */
        public function getSuggestedAction(): ?SuggestedActionType
        {
            if($this->suggestionOverridden)
            {
                return $this->suggestedAction;
            }

            $targetUuid = $this->entityRecord->getUuid();
            $relevantBlacklists = $this->getRelevantBlacklists();
            if (array_any($relevantBlacklists, fn($blacklist) => $blacklist->getEntityUuid() === $targetUuid && $blacklist->getExpires() === null))
            {
                return SuggestedActionType::PERMANENTLY_BLOCK_ENTITY;
            }

            return empty($relevantBlacklists) ? null : SuggestedActionType::TEMPORARILY_BLOCK_ENTITY;
        }

        /**
         * Returns the timestamp at which a temporary block can be lifted.
         *
         * A related permanent blacklist makes the temporary recommendation
         * effectively indefinite, represented by PHP_INT_MAX.
         */
        public function getSuggestedLiftTimestamp(): ?int
        {
            if($this->suggestionOverridden)
            {
                return $this->suggestedLiftTimestamp;
            }

            if($this->getSuggestedAction() !== SuggestedActionType::TEMPORARILY_BLOCK_ENTITY)
            {
                return null;
            }

            $latestExpiry = 0;
            foreach($this->getRelevantBlacklists() as $blacklist)
            {
                $expires = $blacklist->getExpires();
                if($expires === null)
                {
                    return PHP_INT_MAX;
                }

                $latestExpiry = max($latestExpiry, $expires);
            }

            return $latestExpiry > 0 ? $latestExpiry : null;
        }

        /**
         * @inheritDoc
         */
        public function toArray(bool $includeMetadata=true): array
        {
            return $this->toStandardArray($includeMetadata);
        }

        /**
         * @inheritDoc
         */
        public function toStandardArray(bool $includeMetadata=true, bool $hideTelephone=false): array
        {
            // A hidden telephone entity is returned only when it was itself queried by its address or SHA-256
            // identifier, never as a related entity, and its metadata is never returned (OFD "Telephone Entities")
            $relatedEntities = $hideTelephone
                ? array_values(array_filter($this->relatedEntities, fn(EntityRecord $entity) => !$entity->isTelephone()))
                : $this->relatedEntities;

            // The blacklist records of hidden related telephone entities are omitted too, their entity member would
            // reveal that the host holds records about them
            $hiddenUuids = $hideTelephone ? array_map(fn(EntityRecord $entity) => $entity->getUuid(), array_filter(
                $this->relatedEntities, fn(EntityRecord $entity) => $entity->isTelephone() && $entity->getUuid() !== $this->entityRecord->getUuid()
            )) : [];
            $activeBlacklists = array_values(array_filter($this->activeBlacklists,
                fn(BlacklistRecord $blacklist) => !in_array($blacklist->getEntityUuid(), $hiddenUuids, true)
            ));

            return [
                'entity_record' => $this->entityRecord->toArray($includeMetadata && !($hideTelephone && $this->entityRecord->isTelephone())),
                'related_entities' => array_map(
                    fn(EntityRecord $entity) => $entity->toArray($includeMetadata), $relatedEntities
                ),
                'active_blacklists' => array_map(
                    fn(BlacklistRecord $blacklist) => $blacklist->toArray(), $activeBlacklists
                ),
                'suggested_action' => $this->getSuggestedAction()?->value,
                'suggested_lift_timestamp' => $this->getSuggestedLiftTimestamp(),
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): EntityQueryResult
        {
            $result = new self(EntityRecord::fromArray($array['entity_record']),
                array_map(fn(array $entity) => EntityRecord::fromArray($entity), $array['related_entities'] ?? []),
                array_map(fn(array $blacklist) => BlacklistRecord::fromArray($blacklist), $array['active_blacklists'] ?? [])
            );

            // Keep the suggestion the server made when it differs from the derived one (eg; overridden by a plugin)
            if(array_key_exists('suggested_action', $array) || array_key_exists('suggested_lift_timestamp', $array))
            {
                $action = isset($array['suggested_action']) ? SuggestedActionType::tryFrom($array['suggested_action']) : null;
                $liftTimestamp = isset($array['suggested_lift_timestamp']) ? (int)$array['suggested_lift_timestamp'] : null;
                if($action !== $result->getSuggestedAction() || $liftTimestamp !== $result->getSuggestedLiftTimestamp())
                {
                    $result->overrideSuggestedAction($action, $liftTimestamp);
                }
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
                'entity_record' => ['$ref' => EntityRecord::getReference(), 'description' => 'The queried entity'],
                'related_entities' => [
                    'type' => 'array',
                    'items' => ['$ref' => EntityRecord::getReference()],
                    'description' => 'Other entities in the queried entity relationship group',
                ],
                'active_blacklists' => [
                    'type' => 'array',
                    'items' => ['$ref' => BlacklistRecord::getReference()],
                    'description' => 'Active blacklists for the queried entity and related entities',
                ],
                'suggested_action' => [
                    'type' => ['string', 'null'],
                    'enum' => [SuggestedActionType::PERMANENTLY_BLOCK_ENTITY->value, SuggestedActionType::TEMPORARILY_BLOCK_ENTITY->value, null],
                    'description' => 'The action the host suggests taking against the queried entity',
                ],
                'suggested_lift_timestamp' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Unix timestamp at which the host recommends lifting a temporary block',
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['entity_record', 'related_entities', 'active_blacklists'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/EntityQueryResult';
        }
    }