<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Classes\Validate;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Exceptions\EntityQueryRejectedException;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityQueryResult;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\OperatorRecord;
    use InvalidArgumentException;

    class EntityQuery
    {
        private string $identifier;
        private EntityRecord $entityRecord;
        private ?OperatorRecord $authenticatedOperator;
        /** @var array<string, EntityRecord> */
        private array $relatedEntities;
        /** @var array<string, BlacklistRecord> */
        private array $activeBlacklists;
        private array $addedMetadata;
        private bool $suggestionOverridden;
        private ?SuggestedActionType $suggestedAction;
        private ?int $suggestedLiftTimestamp;

        /**
         * EntityQuery constructor.
         *
         * @param string $identifier The identifier the entity was queried with (UUID, SHA-256 hash or entity address)
         * @param EntityRecord $entityRecord The queried entity
         * @param EntityRecord[] $relatedEntities The other entities in the queried entity's relationship group
         * @param BlacklistRecord[] $activeBlacklists The active blacklist records of the relationship group
         * @param OperatorRecord|null $authenticatedOperator The authenticated operator, null if the request is anonymous
         */
        public function __construct(string $identifier, EntityRecord $entityRecord, array $relatedEntities, array $activeBlacklists, ?OperatorRecord $authenticatedOperator=null)
        {
            $this->identifier = $identifier;
            $this->entityRecord = $entityRecord;
            $this->authenticatedOperator = $authenticatedOperator;
            $this->relatedEntities = [];
            $this->activeBlacklists = [];
            $this->addedMetadata = [];
            $this->suggestionOverridden = false;
            $this->suggestedAction = null;
            $this->suggestedLiftTimestamp = null;

            foreach($relatedEntities as $relatedEntity)
            {
                $this->relatedEntities[$relatedEntity->getUuid()] = $relatedEntity;
            }

            foreach($activeBlacklists as $blacklist)
            {
                $this->activeBlacklists[$blacklist->getUuid()] = $blacklist;
            }
        }

        /**
         * Returns the identifier the entity was queried with, as provided in the request
         *
         * @return string The identifier (UUID, SHA-256 hash or entity address)
         */
        public function getIdentifier(): string
        {
            return $this->identifier;
        }

        /**
         * Returns the queried entity as it is stored, without the metadata added by the event handlers
         *
         * @return EntityRecord The queried entity
         */
        public function getEntityRecord(): EntityRecord
        {
            return $this->entityRecord;
        }

        /**
         * Returns the authenticated operator that made the request
         *
         * @return OperatorRecord|null The operator, null if the request is anonymous
         */
        public function getAuthenticatedOperator(): ?OperatorRecord
        {
            return $this->authenticatedOperator;
        }

        /**
         * Returns the other entities in the queried entity's relationship group, including the changes made by the
         * event handlers so far
         *
         * @return EntityRecord[] The related entities
         */
        public function getRelatedEntities(): array
        {
            return array_values($this->relatedEntities);
        }

        /**
         * Adds an entity to the related entities of the response, an entity that is already related is replaced
         *
         * @param EntityRecord $entity The entity to add
         * @return void
         * @throws InvalidArgumentException If the entity is the queried entity
         */
        public function addRelatedEntity(EntityRecord $entity): void
        {
            if($entity->getUuid() === $this->entityRecord->getUuid())
            {
                throw new InvalidArgumentException('The queried entity cannot be added as a related entity');
            }

            $this->relatedEntities[$entity->getUuid()] = $entity;
        }

        /**
         * Removes an entity from the related entities of the response, together with its active blacklist records
         *
         * @param string $entityUuid The UUID of the related entity
         * @return void
         */
        public function removeRelatedEntity(string $entityUuid): void
        {
            unset($this->relatedEntities[$entityUuid]);
            $this->activeBlacklists = array_filter($this->activeBlacklists, fn(BlacklistRecord $blacklist) => $blacklist->getEntityUuid() !== $entityUuid);
        }

        /**
         * Returns the active blacklist records of the relationship group, including the changes made by the event
         * handlers so far
         *
         * @return BlacklistRecord[] The active blacklist records
         */
        public function getActiveBlacklists(): array
        {
            return array_values($this->activeBlacklists);
        }

        /**
         * Adds a blacklist record to the active blacklists of the response (eg; from an external blocklist), which
         * is taken into account by the suggested action unless it was overridden. A record with the same UUID is
         * replaced.
         *
         * @param BlacklistRecord $blacklist The blacklist record to add
         * @return void
         * @throws InvalidArgumentException If the record is lifted or does not belong to the relationship group
         */
        public function addBlacklist(BlacklistRecord $blacklist): void
        {
            if($blacklist->isLifted())
            {
                throw new InvalidArgumentException('A lifted blacklist record cannot be added to the active blacklists');
            }

            if($blacklist->getEntityUuid() !== $this->entityRecord->getUuid() && !isset($this->relatedEntities[$blacklist->getEntityUuid()]))
            {
                throw new InvalidArgumentException(sprintf('The blacklist record %s belongs to the entity %s, which is neither the queried entity nor a related entity', $blacklist->getUuid(), $blacklist->getEntityUuid()));
            }

            $this->activeBlacklists[$blacklist->getUuid()] = $blacklist;
        }

        /**
         * Removes a blacklist record from the active blacklists of the response
         *
         * @param string $blacklistUuid The UUID of the blacklist record
         * @return void
         */
        public function removeBlacklist(string $blacklistUuid): void
        {
            unset($this->activeBlacklists[$blacklistUuid]);
        }

        /**
         * Adds metadata to the queried entity in the response, merged over the stored metadata (existing keys are
         * overwritten). The metadata is only included where entity metadata is visible to the client.
         *
         * @param array $metadata The metadata to add
         * @return void
         * @throws InvalidArgumentException If the metadata is invalid
         */
        public function addEntityMetadata(array $metadata): void
        {
            if(!Validate::metadata($metadata))
            {
                throw new InvalidArgumentException('Invalid entity metadata provided');
            }

            $this->addedMetadata = array_merge($this->addedMetadata, $metadata);
        }

        /**
         * Returns the metadata added to the queried entity by the event handlers so far
         *
         * @return array The added metadata
         */
        public function getAddedEntityMetadata(): array
        {
            return $this->addedMetadata;
        }

        /**
         * Overrides the suggested action of the response, which is otherwise derived from the active blacklists
         *
         * @param SuggestedActionType|null $action The suggested action, null to suggest no action
         * @param int|null $liftTimestamp The Unix timestamp at which a temporary block can be lifted, only for
         *        TEMPORARILY_BLOCK_ENTITY
         * @return void
         * @throws InvalidArgumentException If a lift timestamp is given for any other action or is not positive
         */
        public function setSuggestedAction(?SuggestedActionType $action, ?int $liftTimestamp=null): void
        {
            if($liftTimestamp !== null && $action !== SuggestedActionType::TEMPORARILY_BLOCK_ENTITY)
            {
                throw new InvalidArgumentException('A lift timestamp can only be suggested for ' . SuggestedActionType::TEMPORARILY_BLOCK_ENTITY->value);
            }

            if($liftTimestamp !== null && $liftTimestamp <= 0)
            {
                throw new InvalidArgumentException('The lift timestamp must be a positive Unix timestamp');
            }

            $this->suggestionOverridden = true;
            $this->suggestedAction = $action;
            $this->suggestedLiftTimestamp = $liftTimestamp;
        }

        /**
         * Removes an override of the suggested action, so that it is derived from the active blacklists again
         *
         * @return void
         */
        public function resetSuggestedAction(): void
        {
            $this->suggestionOverridden = false;
            $this->suggestedAction = null;
            $this->suggestedLiftTimestamp = null;
        }

        /**
         * Returns True if an event handler overrode the suggested action
         *
         * @return bool True if the suggested action was overridden
         */
        public function isSuggestedActionOverridden(): bool
        {
            return $this->suggestionOverridden;
        }

        /**
         * Builds the result of the request from the queried entity and the changes made by the event handlers
         *
         * @return EntityQueryResult The result
         */
        public function getResult(): EntityQueryResult
        {
            $entityRecord = $this->entityRecord;
            if(count($this->addedMetadata) > 0)
            {
                $entityRecord = EntityRecord::fromArray(array_merge($entityRecord->toArray(), [
                    'metadata' => array_merge($entityRecord->getMetadata() ?? [], $this->addedMetadata)
                ]));
            }

            $result = new EntityQueryResult($entityRecord, $this->getRelatedEntities(), $this->getActiveBlacklists());
            if($this->suggestionOverridden)
            {
                $result->overrideSuggestedAction($this->suggestedAction, $this->suggestedLiftTimestamp);
            }

            return $result;
        }

        /**
         * Returns the changes made by the event handlers so far, used by the PluginManager to discard the changes of
         * an event handler that failed
         *
         * @internal
         * @return array The current state
         */
        public function getState(): array
        {
            return [
                'related_entities' => $this->relatedEntities,
                'active_blacklists' => $this->activeBlacklists,
                'added_metadata' => $this->addedMetadata,
                'suggestion_overridden' => $this->suggestionOverridden,
                'suggested_action' => $this->suggestedAction,
                'suggested_lift_timestamp' => $this->suggestedLiftTimestamp,
            ];
        }

        /**
         * Restores the changes made by the event handlers to a previous state
         *
         * @internal
         * @param array $state A state returned by getState()
         * @return void
         */
        public function restoreState(array $state): void
        {
            $this->relatedEntities = $state['related_entities'] ?? [];
            $this->activeBlacklists = $state['active_blacklists'] ?? [];
            $this->addedMetadata = $state['added_metadata'] ?? [];
            $this->suggestionOverridden = $state['suggestion_overridden'] ?? false;
            $this->suggestedAction = $state['suggested_action'] ?? null;
            $this->suggestedLiftTimestamp = $state['suggested_lift_timestamp'] ?? null;
        }

        /**
         * Rejects the query entity request, the client receives an error response with the given message and HTTP
         * status code and the remaining event handlers are not executed
         *
         * @param string $message The reason the request was rejected
         * @param HttpResponseCode $code The HTTP status code of the response, must be a 4xx client error (default 403)
         * @return never
         * @throws EntityQueryRejectedException Always
         * @throws InvalidArgumentException If the HTTP status code is not a 4xx client error
         */
        public function reject(string $message, HttpResponseCode $code=HttpResponseCode::FORBIDDEN): never
        {
            if($code->value < 400 || $code->value > 499)
            {
                throw new InvalidArgumentException(sprintf('A query entity request can only be rejected with a 4xx client error, %d given', $code->value));
            }

            throw new EntityQueryRejectedException($message, $code);
        }
    }
