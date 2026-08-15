<?php

    namespace FederationLib\Objects;

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

        /**
         * @param EntityRecord $entityRecord The queried entity.
         * @param EntityRecord[] $relatedEntities Entities in the queried entity's relationship group.
         * @param BlacklistRecord[] $activeBlacklists Active blacklist records for the queried entity and its relationship group.
         */
        public function __construct(EntityRecord $entityRecord, array $relatedEntities, array $activeBlacklists=[])
        {
            $this->entityRecord = $entityRecord;
            $this->relatedEntities = array_values(array_filter($relatedEntities, fn($entity) => $entity instanceof EntityRecord));
            $this->activeBlacklists = array_values(array_filter($activeBlacklists, fn($blacklist) => $blacklist instanceof BlacklistRecord && !$blacklist->isLifted()));
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
         * Returns the action suggested by active blacklist records.
         *
         * A permanent target blacklist permanently blocks the target. Any other
         * active blacklist in the relationship group temporarily blocks the target.
         */
        public function getSuggestedAction(): ?SuggestedActionType
        {
            $targetUuid = $this->entityRecord->getUuid();
            if (array_any($this->activeBlacklists, fn($blacklist) => $blacklist->getEntityUuid() === $targetUuid && $blacklist->getExpires() === null))
            {
                return SuggestedActionType::PERMANENTLY_BLOCK_ENTITY;
            }

            return empty($this->activeBlacklists) ? null : SuggestedActionType::TEMPORARILY_BLOCK_ENTITY;
        }

        /**
         * Returns the timestamp at which a temporary block can be lifted.
         *
         * A related permanent blacklist makes the temporary recommendation
         * effectively indefinite, represented by PHP_INT_MAX.
         */
        public function getSuggestedLiftTimestamp(): ?int
        {
            if($this->getSuggestedAction() !== SuggestedActionType::TEMPORARILY_BLOCK_ENTITY)
            {
                return null;
            }

            $latestExpiry = 0;
            foreach($this->activeBlacklists as $blacklist)
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
        public function toStandardArray(bool $includeMetadata=true): array
        {
            return [
                'entity_record' => $this->entityRecord->toArray($includeMetadata),
                'related_entities' => array_map(
                    fn(EntityRecord $entity) => $entity->toArray($includeMetadata), $this->relatedEntities
                ),
                'active_blacklists' => array_map(
                    fn(BlacklistRecord $blacklist) => $blacklist->toArray(), $this->activeBlacklists
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
            return new self(EntityRecord::fromArray($array['entity_record']),
                array_map(fn(array $entity) => EntityRecord::fromArray($entity), $array['related_entities'] ?? []),
                array_map(fn(array $blacklist) => BlacklistRecord::fromArray($blacklist), $array['active_blacklists'] ?? [])
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
                    'type' => 'string',
                    'enum' => [SuggestedActionType::PERMANENTLY_BLOCK_ENTITY->value, SuggestedActionType::TEMPORARILY_BLOCK_ENTITY->value],
                    'nullable' => true,
                ],
                'suggested_lift_timestamp' => ['type' => 'integer', 'nullable' => true],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['entity_record', 'related_entities', 'active_blacklists', 'suggested_action', 'suggested_lift_timestamp'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/EntityQueryResult';
        }
    }