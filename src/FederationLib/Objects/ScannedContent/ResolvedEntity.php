<?php

    namespace FederationLib\Objects\ScannedContent;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\StandardObjectInterface;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;

    class ResolvedEntity implements StandardObjectInterface, ObjectSpecificationInterface
    {
        private EntityRecord $entity;
        private ?ResolvedEntityPosition $entityPosition;
        /**
         * @var BlacklistRecord[]
         */
        private array $activeBlacklists;
        private ?ResolvedEntity $parentEntity;

        /**
         * ResolvedEntity Public Constructor
         *
         * @param EntityRecord $entity The resolved entity record of the target entity
         * @param BlacklistRecord[] $activeBlacklists An array of active blacklist records associated with the target entity
         * @param ResolvedEntityPosition|null $entityPosition Optional. The text-poistion of the entity within the text content
         * @param ResolvedEntity|null $parentEntity Optional. The resolved parent entity of the entity
         */
        public function __construct(EntityRecord $entity, array $activeBlacklists=[], ?ResolvedEntityPosition $entityPosition=null, ?ResolvedEntity $parentEntity=null)
        {
            $this->entity = $entity;
            $this->activeBlacklists = $activeBlacklists;
            $this->entityPosition = $entityPosition;
            $this->parentEntity = $parentEntity;
        }

        /**
         * Returns the resolved entity record
         *
         * @return EntityRecord The resolved entity record
         */
        public function getEntity(): EntityRecord
        {
            return $this->entity;
        }

        /**
         * Optional. Returns the position
         *
         * @return ResolvedEntityPosition|null
         */
        public function getEntityPosition(): ?ResolvedEntityPosition
        {
            return $this->entityPosition;
        }

        /**
         * Returns the list of active blacklist records associated with the resolved entity
         *
         * @return BlacklistRecord[] An array of active blacklist records
         */
        public function getActiveBlacklists(): array
        {
            return $this->activeBlacklists;
        }

        /**
         * Returns the resolved parent entity, null if no parent is associated with the entity
         *
         * @return ResolvedEntity|null Returns the resolved parent entity, null if there's no parent.
         */
        public function getParentEntity(): ?ResolvedEntity
        {
            return $this->parentEntity;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'entity' => $this->entity->toArray(),
                'entity_position' => $this->entityPosition?->toArray(),
                'active_blacklists' => array_map(fn($activeBlacklist) => $activeBlacklist->toArray(), $this->activeBlacklists),
                'parent_entity' => $this->parentEntity?->toArray()
            ];
        }

        /**
         * @inheritDoc
         */
        public function toStandardArray(): array
        {
            return [
                'entity' => $this->entity->toStandardArray(),
                'entity_position' => $this->entityPosition?->toArray(),
                'active_blacklists' => array_map(fn($activeBlacklist) => $activeBlacklist->toArray(), $this->activeBlacklists),
                'parent_entity' => $this->parentEntity?->toStandardArray()
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ResolvedEntity
        {
            return new self(
                EntityRecord::fromArray($array['entity']),
                isset($array['active_blacklists']) ? array_map(fn($activeBlacklist) => BlacklistRecord::fromArray($activeBlacklist), $array['active_blacklists']) : null,
                isset($array['entity_position']) ? ResolvedEntityPosition::fromArray($array['entity_position']) : null,
                isset($array['parent_entity']) ? ResolvedEntity::fromArray($array['parent_entity']) : null,
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
                'entity' => ['$ref' => EntityRecord::getReference()],
                'entity_position' => ['$ref' => ResolvedEntityPosition::getReference(), 'nullable' => true],
                'active_blacklists' => [
                    'type' => 'array',
                    'items' => ['$ref' => BlacklistRecord::getReference()],
                    'description' => 'Active blacklist records associated with the entity',
                ],
                'parent_entity' => ['$ref' => ResolvedEntity::getReference(), 'nullable' => true],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['entity', 'active_blacklists'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ResolvedEntity';
        }
    }