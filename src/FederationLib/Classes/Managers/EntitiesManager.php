<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\DnsDetails;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Enums\Categories\EntityCategory;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\EntityOrderType;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ScannedContent\ResolvedEntityPosition;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;

    class EntitiesManager
    {
        public const string CACHE_PREFIX = 'entity:';

        /**
         * SQL condition that excludes telephone entities, which are hidden from requests that do not already know the
         * number (OFD "Telephone Entities")
         */
        public const string NOT_TELEPHONE_CONDITION = "host <> '" . Utilities::TELEPHONE_ENTITY_HOST . "'";

        /**
         * Registers a new entity with the given ID and domain.
         *
         * If an entity with the same host/ID already exists (e.g. due to a concurrent request),
         * the duplicate-key failure is handled internally: the provided metadata is merged into
         * the existing record and the existing entity UUID is returned, making the operation
         * idempotent.
         *
         * @param string $host The host of the entity
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific domain
         * @param array|null $metadata Optional. Metadata to associate with the entity
         * @return string The UUID of the registered or existing entity
         * @throws InvalidArgumentException If the ID exceeds 255 characters or if the domain is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function registerEntity(string $host, ?string $id=null, ?array $metadata=null): string
        {
            if($id !== null && strlen($id) > 255)
            {
                throw new InvalidArgumentException("Entity ID cannot exceed 255 characters.");
            }

            if(!Validate::host($host))
            {
                throw new InvalidArgumentException('A valid Entity host/domain must be provided');
            }

            if(strlen($host) > 255)
            {
                throw new InvalidArgumentException("Host cannot exceed 255 characters.");
            }

            if(!Validate::entityAddress($host, $id))
            {
                throw new InvalidArgumentException('A telephone entity must be the international number as 1 to 15 digits without a leading + or separators, and no entity host may be under e164.arpa');
            }

            if($metadata !== null && !Validate::metadata($metadata))
            {
                throw new InvalidArgumentException('Invalid entity metadata provided');
            }

            $host = Utilities::canonicalizeHost($host);
            $uuid = Uuid::v7()->toRfc4122();
            $hash = Utilities::hashEntity($host, $id);

            try
            {
                $now = date('Y-m-d H:i:s');
                if($metadata !== null)
                {
                    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt = DatabaseConnection::getConnection()->prepare(
                        "INSERT INTO entities (uuid, hash, id, host, metadata, updated) VALUES (:uuid, :hash, :id, :host, :metadata, :updated)"
                    );
                    $stmt->bindParam(':metadata', $metadataJson);
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare(
                        "INSERT INTO entities (uuid, hash, id, host, updated) VALUES (:uuid, :hash, :id, :host, :updated)"
                    );
                }

                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':hash', $hash);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':host', $host);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                if($e->getCode() == 23000)
                {
                    $existingEntity = self::getEntity($host, $id);
                    if($existingEntity === null)
                    {
                        throw new DatabaseOperationException("Failed to register entity: " . $e->getMessage(), $e->getCode(), $e);
                    }

                    if($metadata !== null)
                    {
                        self::updateEntityMetadata($existingEntity->getUuid(), $metadata);
                    }

                    return $existingEntity->getUuid();
                }

                throw new DatabaseOperationException("Failed to register entity: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_CREATED, $uuid);

            if($id === null && Configuration::getServerConfiguration()->isLinkSubdomainEntitiesEnabled())
            {
                self::assignParentDomain($uuid, $host);
                self::adoptSubdomainEntities($uuid, $host);
            }

            return $uuid;
        }

        /**
         * Links a newly registered subdomain host entity to the entity of its registrable domain, registering that
         * domain entity first when it does not exist yet, so that sub1.example.com and sub2.example.com both become
         * a CHILD of example.com. Hosts that are already a registrable domain or an IP address are left alone.
         * Failures are logged rather than thrown, since the subdomain entity itself has already been registered.
         *
         * @param string $entityUuid The UUID of the subdomain entity
         * @param string $host The canonical host of the subdomain entity
         * @return void
         */
        private static function assignParentDomain(string $entityUuid, string $host): void
        {
            $parentHost = Utilities::getRegistrableDomain($host);
            if($parentHost === null || $parentHost === $host)
            {
                return;
            }

            try
            {
                $parentUuid = self::getEntity($parentHost)?->getUuid() ?? self::registerEntity($parentHost);
                self::assignEntityRelationship($entityUuid, $parentUuid, EntityRelationshipType::CHILD);
            }
            catch(DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->warning(sprintf('Failed to assign parent domain %s to entity %s: %s', $parentHost, $host, $e->getMessage()), $e);
            }
        }

        /**
         * Links subdomain host entities that were registered without a relationship to their registrable domain
         * (e.g. registered before subdomain linking was enabled) to the entity of that domain as a CHILD,
         * registering the domain entity when it does not exist yet. This is what would have happened had the
         * subdomain entities been registered with subdomain linking enabled.
         *
         * Once every subdomain entity has been linked, running this again has no effect. Does nothing when
         * subdomain linking is disabled.
         *
         * @return int The number of subdomain entities that were linked
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function linkUnlinkedSubdomainEntities(): int
        {
            if(!Configuration::getServerConfiguration()->isLinkSubdomainEntitiesEnabled())
            {
                return 0;
            }

            try
            {
                // A subdomain of a registrable domain has at least three labels
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT host FROM entities WHERE id IS NULL AND relationship_entity IS NULL AND host LIKE '%.%.%'"
                );
                $stmt->execute();
                $hosts = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve unlinked subdomain entities: " . $e->getMessage(), $e->getCode(), $e);
            }

            $parentHosts = [];
            foreach($hosts as $host)
            {
                $parentHost = Utilities::getRegistrableDomain($host);
                if($parentHost !== null && $parentHost !== $host)
                {
                    $parentHosts[$parentHost] = true;
                }
            }

            $linked = 0;
            foreach(array_keys($parentHosts) as $parentHost)
            {
                $parentUuid = self::getEntity($parentHost)?->getUuid();
                if($parentUuid === null)
                {
                    // Registering the domain entity links its existing subdomain entities, a domain that is not a
                    // valid entity host (eg; under e164.arpa, stored before it was rejected) is skipped
                    if(!Validate::entityAddress($parentHost, null))
                    {
                        continue;
                    }

                    $parentUuid = self::registerEntity($parentHost);
                    $linked += count(self::getEntitiesByRelationshipEntity($parentUuid));
                    continue;
                }

                $linked += self::adoptSubdomainEntities($parentUuid, $parentHost);
            }

            return $linked;
        }

        /**
         * Updates the metadata of an existing entity, merging new values with existing ones.
         * Only updates the database when changes are detected. New keys are added, existing
         * keys are overwritten, but no existing keys are removed.
         *
         * @param string $entityUuid The UUID of the entity to update
         * @param array $metadata The new metadata to merge into the existing metadata
         * @return bool True if changes were applied, False if no changes were needed
         * @throws InvalidArgumentException If the metadata is invalid or entity is not found
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function updateEntityMetadata(string $entityUuid, array $metadata): bool
        {
            if(!Validate::metadata($metadata))
            {
                throw new InvalidArgumentException('Invalid entity metadata provided');
            }

            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            $existingMetadata = $entity->getMetadata() ?? [];
            $mergedMetadata = array_merge($existingMetadata, $metadata);
            $existingJson = json_encode($existingMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mergedJson = json_encode($mergedMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if($mergedJson === $existingJson)
            {
                return false;
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET metadata=:metadata, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':metadata', $mergedJson);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity metadata: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_UPDATED, $entityUuid);
            return true;
        }

        /**
         * Replaces the metadata of an existing entity entirely, removing any keys not present
         * in the provided metadata. This is a full replace, not a merge. Intended for use
         * by the PATCH endpoint (updateEntity).
         *
         * @param string $entityUuid The UUID of the entity to update
         * @param array $metadata The new metadata to set on the entity (replaces entirely)
         * @return bool True if changes were applied, False if no changes were needed
         * @throws InvalidArgumentException If the metadata is invalid or entity is not found
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function replaceEntityMetadata(string $entityUuid, array $metadata): bool
        {
            if(!Validate::metadata($metadata))
            {
                throw new InvalidArgumentException('Invalid entity metadata provided');
            }

            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            $clear = empty($metadata);
            $existingMetadata = $entity->getMetadata();

            if($clear)
            {
                if($existingMetadata === null)
                {
                    return false;
                }
            }
            else
            {
                $newJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $existingJson = json_encode($existingMetadata ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if($newJson === $existingJson)
                {
                    return false;
                }
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                if($clear)
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("UPDATE entities SET metadata=NULL, updated=:updated WHERE uuid=:uuid");
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("UPDATE entities SET metadata=:metadata, updated=:updated WHERE uuid=:uuid");
                    $stmt->bindParam(':metadata', $newJson);
                }
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to replace entity metadata: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_UPDATED, $entityUuid);
            return true;
        }

        /**
         * Updates the entity's relationship
         *
         * @param string $entityUuid The entity to update
         * @param string $targetEntityUuid The target entity of the relationship (the parent)
         * @param EntityRelationshipType $type The relationship type
         * @return bool True if the relationship was updated successfully, False otherwise
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function assignEntityRelationship(string $entityUuid, string $targetEntityUuid, EntityRelationshipType $type): bool
        {
            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            $targetEntity = self::getEntityByUuid($targetEntityUuid);
            if($targetEntity === null)
            {
                throw new InvalidArgumentException('Target Entity not found');
            }

            $typeString = $type->value;

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET relationship_entity=:relationship_entity, relationship_type=:relationship_type, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':relationship_entity', $targetEntityUuid);
                $stmt->bindParam(':relationship_type', $typeString);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity relationship: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_UPDATED, $entityUuid);
            return true;
        }

        /**
         * Clears the entity's relationship information
         *
         * @param string $entityUuid The entity UUID to clear relationship data from
         * @return bool True if the operation was successful, False otherwise.
         * @throws DatabaseOperationException Thrown if there was a database operation error.
         */
        public static function clearEntityRelationship(string $entityUuid): bool
        {
            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET relationship_entity=null, relationship_type=null, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity relationship: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_UPDATED, $entityUuid);
            return true;
        }

        /**
         * Retrieves all entities that directly reference the given entity as their relationship target.
         *
         * @param string $entityUuid The relationship target UUID.
         * @return EntityRecord[] Entities that reference the target UUID.
         * @throws InvalidArgumentException If the UUID is invalid.
         * @throws DatabaseOperationException If the query fails.
         */
        public static function getEntitiesByRelationshipEntity(string $entityUuid): array
        {
            if(!Validate::uuid($entityUuid))
            {
                throw new InvalidArgumentException('A valid Entity UUID must be provided');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare('SELECT * FROM entities WHERE relationship_entity = :relationship_entity');
                $stmt->bindParam(':relationship_entity', $entityUuid);
                $stmt->execute();

                return array_map(fn(array $entity) => new EntityRecord($entity), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to retrieve entities by relationship target: ' . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves an entity by its ID and domain.
         *
         * @param string $host The host of the entity.
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific domain
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntity(string $host, ?string $id=null): ?EntityRecord
        {
            if(!is_null($id))
            {
                if(strlen($id) < 1)
                {
                    throw new InvalidArgumentException("Entity ID cannot be empty if provided");
                }

                if(strlen($id) > 255)
                {
                    throw new InvalidArgumentException("Entity ID cannot exceed 255 characters.");
                }
            }

            if(!Validate::host($host))
            {
                throw new InvalidArgumentException('A valid Entity host/domain must be provided');
            }
            elseif(strlen($host) > 255)
            {
                throw new InvalidArgumentException("Host cannot exceed 255 characters.");
            }

            // Try cache first
            $hash = Utilities::hashEntity($host, $id);

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return new EntityRecord($data);
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE hash=:hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$data)
                {
                    return null;
                }

                $data = new EntityRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by domain: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0,
                );

                // Create a pointer from hash to UUID for quick lookups
                // Only set the hash pointer if the entity record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::CACHE_PREFIX, $hash),
                    expire: Configuration::getRedisConfiguration()->getEntitiesCacheTtl(),
                    value: $data->getUuid()
                );
            }

            return $data;
        }

        /**
         * Retrieves an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByUuid(string $uuid): ?EntityRecord
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            if(!Validate::uuid($uuid))
            {
                throw new InvalidArgumentException('A valid Entity UUID must be provided');
            }

            if(self::isCachingEnabled())
            {
                $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                if($data !== null)
                {
                    return new EntityRecord($data);
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if(!$data)
                {
                    return null;
                }

                $data = new EntityRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0,
                );

                // Create a pointer from hash to UUID for quick lookups
                // Only set the hash pointer if the entity record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::CACHE_PREFIX, Utilities::hashEntity($data->getHost(), $data->getId())),
                    expire: Configuration::getRedisConfiguration()->getEntitiesCacheTtl(),
                    value: $data->getUuid()
                );
            }

            return $data;
        }

        /**
         * Retrieves several entities by their UUIDs at once: the cached ones are served from the cache, the others
         * are retrieved with a single query and cached like getEntityByUuid() caches them
         *
         * @param string[] $uuids The entity UUIDs, invalid UUIDs are ignored
         * @return array<string, EntityRecord> The entities that exist, keyed by their UUID
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntitiesByUuids(array $uuids): array
        {
            $uuids = array_values(array_unique(array_filter($uuids, fn($uuid) => is_string($uuid) && Validate::uuid($uuid))));
            $entities = [];

            if(self::isCachingEnabled())
            {
                foreach($uuids as $uuid)
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($data !== null)
                    {
                        $entities[$uuid] = new EntityRecord($data);
                    }
                }
            }

            $missing = array_values(array_diff($uuids, array_keys($entities)));
            if(count($missing) === 0)
            {
                return $entities;
            }

            [$placeholders, $parameters] = Utilities::getInPlaceholders($missing, 'uuid');
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE uuid IN ($placeholders)");
                foreach($parameters as $placeholder => $value)
                {
                    $stmt->bindValue($placeholder, $value);
                }
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entities by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }

            $loaded = array_map(fn(array $row) => new EntityRecord($row), $rows);
            foreach($loaded as $entity)
            {
                $entities[$entity->getUuid()] = $entity;
            }

            if(self::isCachingEnabled() && count($loaded) > 0 && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                $ttl = Configuration::getRedisConfiguration()->getEntitiesCacheTtl();
                foreach($loaded as $entity)
                {
                    RedisConnection::setRecord(record: $entity, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $entity->getUuid()), ttl: $ttl);
                    if($ttl > 0)
                    {
                        RedisConnection::getConnection()->setex(sprintf("%s%s", self::CACHE_PREFIX, Utilities::hashEntity($entity->getHost(), $entity->getId())), $ttl, $entity->getUuid());
                    }
                }
            }

            return $entities;
        }

        /**
         * Retrieves an entity by any OFD entity identifier form: its UUID, its SHA-256 identifier, a named entity
         * address (local-part@entity-host) or a host entity address (the entity host alone). An entity address must
         * be in canonical form, and its entity host may be a DNS name, an IPv4 address or an IPv6 address.
         *
         * @param string $identifier The entity identifier
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the identifier is not a valid entity identifier.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByIdentifier(string $identifier): ?EntityRecord
        {
            if(Utilities::isUuid($identifier))
            {
                return self::getEntityByUuid($identifier);
            }

            // Only 64 lowercase hexadecimal characters are a SHA-256 identifier, any other value is an entity address
            if(preg_match('/^[a-f0-9]{64}$/', $identifier) === 1)
            {
                return self::getEntityByHash($identifier);
            }

            // The local part cannot contain '@', so the last one separates it from the entity host
            $separator = strrpos($identifier, '@');
            if($separator !== false)
            {
                $id = substr($identifier, 0, $separator);
                $host = substr($identifier, $separator + 1);
                if(preg_match('/^[a-zA-Z0-9._%+-]+$/', $id) !== 1 || !Validate::host($host) || !Validate::entityAddress($host, $id))
                {
                    throw new InvalidArgumentException('Invalid entity address');
                }

                return self::getEntityByHash(Utilities::hashEntity($host, $id));
            }

            if(!Validate::host($identifier) || !Validate::entityAddress($identifier, null))
            {
                throw new InvalidArgumentException('Invalid entity identifier');
            }

            return self::getEntityByHash(Utilities::hashEntity($identifier));
        }

        /**
         * Retrieves an entity by its SHA-256 hash.
         *
         * @param string $hash The SHA-256 hash of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByHash(string $hash): ?EntityRecord
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return new EntityRecord($data);
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE hash = :hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();
                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$data)
                {
                    return null;
                }

                $data = new EntityRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by hash: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEntitiesCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0,
                );

                // Create a pointer from hash to UUID for quick lookups
                // Only set the hash pointer if the entity record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::CACHE_PREFIX, $hash),
                    expire: Configuration::getRedisConfiguration()->getEntitiesCacheTtl(),
                    value: $data->getUuid()
                );
            }

            return $data;
        }

        /**
         * Checks if an entity exists by its ID and domain.
         *
         * @param string $host The host/domain of the entity
         * @param string|null $id Optional. The ID of the entity if it belongs to the specific host
         * @return bool True if the entity exists, false otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExists(string $host, ?string $id=null): bool
        {
            if(!is_null($id) && strlen($id) < 1)
            {
                throw new InvalidArgumentException("Entity ID must be provided.");
            }

            if(!Validate::host($host))
            {
                throw new InvalidArgumentException('A valid Entity host/domain must be provided');
            }

            $hash = Utilities::hashEntity($host, $id);

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    // Verify that the actual entity record still exists in cache
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return true;
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE hash=:hash LIMIT 1");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();

                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check entity existence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an entity exists by its UUID.
         *
         * @param string $uuid The UUID of the entity.
         * @return bool True if the entity exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExistsByUuid(string $uuid): bool
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            if(!Validate::uuid($uuid))
            {
                throw new InvalidArgumentException("Entity UUID must be valid.");
            }

            if(self::isCachingEnabled())
            {
                if(RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $uuid)))
                {
                    return true;
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check entity existence by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an entity exists by its hash.
         *
         * @param string $hash The hash of the entity.
         * @return bool True if the entity exists, false otherwise.
         * @throws InvalidArgumentException If the hash is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function entityExistsByHash(string $hash): bool
        {
            if(strlen($hash) < 1 || !preg_match('/^[a-f0-9]{64}$/', $hash))
            {
                throw new InvalidArgumentException("Entity hash must be a valid SHA-256 hash.");
            }

            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    // Verify that the actual entity record still exists in cache
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return true;
                    }
                    else
                    {
                        // Hash pointer exists but entity record is missing from cache
                        // Remove the stale hash pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities WHERE hash = :hash");
                $stmt->bindParam(':hash', $hash);
                $stmt->execute();
                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check entity existence by hash: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEntity(string $uuid): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            // The database deletes the attachments of the entity's evidence, but not their stored files
            $attachmentUuids = FileAttachmentManager::getUuidsByEvidenceColumn('entity', $uuid);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                // Remove from cache
                if(self::isCachingEnabled())
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($data !== null)
                    {
                        $hash = Utilities::hashEntity($data['host'], $data['id'] ?? null);
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                    }

                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    RedisConnection::deleteRecordsByField(self::CACHE_PREFIX, 'relationship_entity', $uuid);
                    RedisConnection::deleteRecordsByField(BlacklistManager::CACHE_PREFIX, 'entity', $uuid);
                    RedisConnection::deleteRecordsByField(AuditLogManager::CACHE_PREFIX, 'entity', $uuid);
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }

                // The database also deletes the entity's evidence and the reports made by the entity, which may be
                // cached even when entities are not
                if(Configuration::getRedisConfiguration()->isEnabled())
                {
                    RedisConnection::deleteRecordsByField(EvidenceManager::CACHE_PREFIX, 'entity', $uuid);
                    RedisConnection::deleteRecordsByField(ReportManager::CACHE_PREFIX, 'reporting_entity', $uuid);
                    RedisConnection::clearSearchCache(EvidenceManager::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(ReportManager::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                FileAttachmentManager::forgetDeletedRecords($attachmentUuids);
                PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_DELETED, $uuid);
            }
        }

        /**
         * Migrates entities registered before entity hosts were canonicalized, so that hosts with a leading "www."
         * label (e.g. www.example.com) are replaced by their canonical host (example.com).
         *
         * When no entity exists for the canonical host, the entity is renamed in place, keeping its UUID and
         * everything linked to it. When one already exists, the reports, evidence, blacklist records, audit log
         * entries and relationships of the non-canonical entity are moved to the existing entity, its reputation is
         * added to the existing entity's reputation, and the non-canonical entity is removed. Every change is
         * recorded in the audit log under the system operator.
         *
         * Once every non-canonical host has been migrated, running this again has no effect.
         *
         * @return int The number of entities migrated
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function migrateNonCanonicalHosts(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE host LIKE 'www.%'");
                $stmt->execute();
                $entities = $stmt->fetchAll();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve non-canonical entities: " . $e->getMessage(), $e->getCode(), $e);
            }

            $systemOperatorUuid = null;
            $migrated = 0;

            foreach($entities as $entity)
            {
                $canonicalHost = Utilities::canonicalizeHost($entity['host']);
                if($canonicalHost === $entity['host'])
                {
                    continue; // Hosts such as www.com where "www" is the registered label are already canonical
                }

                $systemOperatorUuid ??= OperatorManager::getSystemOperator()->getUuid();
                $oldAddress = $entity['id'] !== null ? sprintf('%s@%s', $entity['id'], $entity['host']) : $entity['host'];
                $newAddress = $entity['id'] !== null ? sprintf('%s@%s', $entity['id'], $canonicalHost) : $canonicalHost;
                $canonicalHash = Utilities::hashEntity($canonicalHost, $entity['id']);
                $connection = DatabaseConnection::getConnection();

                try
                {
                    $connection->beginTransaction();

                    $stmt = $connection->prepare("SELECT uuid FROM entities WHERE uuid != :uuid AND (hash = :hash OR (host = :host AND id <=> :id)) LIMIT 1");
                    $stmt->bindParam(':uuid', $entity['uuid']);
                    $stmt->bindParam(':hash', $canonicalHash);
                    $stmt->bindParam(':host', $canonicalHost);
                    $stmt->bindParam(':id', $entity['id']);
                    $stmt->execute();
                    $canonicalUuid = $stmt->fetchColumn();

                    if($canonicalUuid === false)
                    {
                        $stmt = $connection->prepare("UPDATE entities SET host = :host, hash = :hash, updated = NOW() WHERE uuid = :uuid");
                        $stmt->bindParam(':host', $canonicalHost);
                        $stmt->bindParam(':hash', $canonicalHash);
                        $stmt->bindParam(':uuid', $entity['uuid']);
                        $stmt->execute();

                        AuditLogManager::createEntry(AuditLogType::ENTITY_UPDATED, sprintf(
                            'Entity %s was replaced by %s because entity hosts no longer include a leading "www." label',
                            $oldAddress, $newAddress
                        ), $systemOperatorUuid, $entity['uuid']);
                    }
                    else
                    {
                        foreach(['UPDATE reports SET reporting_entity = :new WHERE reporting_entity = :old',
                                 'UPDATE evidence SET entity = :new WHERE entity = :old',
                                 'UPDATE blacklist SET entity = :new WHERE entity = :old',
                                 'UPDATE audit_log SET entity = :new WHERE entity = :old',
                                 // Prevent the canonical entity from ending up in a relationship with itself
                                 'UPDATE entities SET relationship_entity = NULL, relationship_type = NULL WHERE uuid = :new AND relationship_entity = :old',
                                 'UPDATE entities SET relationship_entity = :new WHERE relationship_entity = :old'] as $query)
                        {
                            $stmt = $connection->prepare($query);
                            $stmt->bindParam(':new', $canonicalUuid);
                            $stmt->bindParam(':old', $entity['uuid']);
                            $stmt->execute();
                        }

                        $stmt = $connection->prepare("UPDATE entities SET reputation = reputation + :reputation, updated = NOW() WHERE uuid = :uuid");
                        $stmt->bindParam(':reputation', $entity['reputation'], PDO::PARAM_INT);
                        $stmt->bindParam(':uuid', $canonicalUuid);
                        $stmt->execute();

                        $stmt = $connection->prepare("DELETE FROM entities WHERE uuid = :uuid");
                        $stmt->bindParam(':uuid', $entity['uuid']);
                        $stmt->execute();

                        AuditLogManager::createEntry(AuditLogType::ENTITY_DELETED, sprintf(
                            'Entity %s (%s) was merged into %s because entity hosts no longer include a leading "www." label',
                            $oldAddress, $entity['uuid'], $newAddress
                        ), $systemOperatorUuid, $canonicalUuid);
                    }

                    $connection->commit();
                }
                catch (PDOException|DatabaseOperationException $e)
                {
                    if($connection->inTransaction())
                    {
                        $connection->rollBack();
                    }

                    throw new DatabaseOperationException(sprintf("Failed to migrate entity %s: %s", $oldAddress, $e->getMessage()), $e->getCode(), $e);
                }

                // A renamed subdomain host (www.sub.example.com -> sub.example.com) is now linked like any new one
                if($canonicalUuid === false && $entity['id'] === null && $entity['relationship_entity'] === null &&
                    Configuration::getServerConfiguration()->isLinkSubdomainEntitiesEnabled())
                {
                    self::assignParentDomain($entity['uuid'], $canonicalHost);
                }

                $migrated++;
            }

            // Cached records may still carry the old hosts, hashes and entity references
            if($migrated > 0 && Configuration::getRedisConfiguration()->isEnabled())
            {
                foreach([self::CACHE_PREFIX, ReportManager::CACHE_PREFIX, EvidenceManager::CACHE_PREFIX,
                         BlacklistManager::CACHE_PREFIX, AuditLogManager::CACHE_PREFIX] as $prefix)
                {
                    RedisConnection::clearRecords($prefix);
                }
            }

            return $migrated;
        }

        /**
         * Retrieves a list of entities with pagination.
         *
         * @param int $limit The maximum number of entities to retrieve per page.
         * @param int $page The page number to retrieve.
         * @return EntityRecord[] An array of EntityRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntities(int $limit=100, int $page=1, ?EntityCategory $category=null, ?string $by=null, ?OrderType $order=null, bool $excludeTelephone=false): array
        {
            if($limit < 1)
            {
                $limit = 100;
            }
            if($page < 1)
            {
                $page = 1;
            }

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new EntityRecord($data), $cached);
                }
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $conditions = array_filter([$category?->toCondition() ?? '', $excludeTelephone ? self::NOT_TELEPHONE_CONDITION : '']);
                $sortClause = self::buildEntitySortClause($by, $order);
                $sql = "SELECT * FROM entities";
                if (!empty($conditions))
                {
                    $sql .= ' WHERE (' . implode(') AND (', $conditions) . ')';
                }
                $sql .= " $sortClause LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $entities = [];
                while($row = $stmt->fetch(PDO::FETCH_ASSOC))
                {
                    $entities[] = new EntityRecord($row);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entities: " . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($entities))
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(EntityRecord $r) => $r->toArray(), $entities));
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $entities, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0
                );
            }

            return $entities;
        }

        /**
         * Returns the total number of entities in the database.
         *
         * @return int The total number of entities.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM entities");
                $stmt->execute();
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count entities: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Updates the reputation score for an entity atomically. The delta is added to the existing
         * reputation and clamped to the configured bounds. Whitelisted entities are excluded and their
         * reputation is left untouched. The reputation cache is invalidated after the update so subsequent
         * reads fetch the fresh value.
         *
         * @param string $entityUuid The UUID of the entity to update
         * @param int $delta The amount to add to the current reputation (positive or negative)
         * @return void
         * @throws DatabaseOperationException If there is an error executing the SQL statement
         */
        public static function updateEntityReputation(string $entityUuid, int $delta): void
        {
            try
            {
                $minBound = Configuration::getScanningConfiguration()->getReputationMinBound();
                $maxBound = Configuration::getScanningConfiguration()->getReputationMaxBound();
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET reputation = GREATEST(:minBound, LEAST(:maxBound, reputation + :delta)), reputation_last_updated = :updated WHERE uuid = :uuid AND whitelisted = 0"
                );
                $stmt->bindParam(':minBound', $minBound, PDO::PARAM_INT);
                $stmt->bindParam(':maxBound', $maxBound, PDO::PARAM_INT);
                $stmt->bindParam(':delta', $delta, PDO::PARAM_INT);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity reputation: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_REPUTATION_UPDATED, $entityUuid);
            }
        }

        /**
         * Resolves an identifier extracted from content (a UUID, SHA-256 hash, entity address, URL, email, domain,
         * IPv4 or IPv6 address) to an existing entity record, hashing the canonical host (and optional id) the same
         * way pushEntity stores it.
         *
         * @param string $entityIdentifier The identifier to resolve
         * @param ResolvedEntityPosition|null $entityPosition Optional position metadata carrying the named entity type
         * @return EntityRecord|null The matching entity record, or null if none exists
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function resolveNamedEntity(string $entityIdentifier, ?ResolvedEntityPosition $entityPosition=null): ?EntityRecord
        {
            if(strlen($entityIdentifier) < 1)
            {
                return null;
            }

            if(Utilities::isUuid($entityIdentifier))
            {
                return self::getEntityByUuid($entityIdentifier);
            }

            if(Utilities::isSha256($entityIdentifier))
            {
                return self::getEntityByHash($entityIdentifier);
            }

            if(Utilities::isEntityAddress($entityIdentifier))
            {
                $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                return self::getEntityByHash(Utilities::hashEntity($parsedAddress['host'], $parsedAddress['id']));
            }

            $host = null;
            $id = null;

            if($entityPosition !== null)
            {
                switch($entityPosition->getType())
                {
                    case NamedEntityType::URL:
                        $host = parse_url($entityIdentifier, PHP_URL_HOST);
                        break;

                    case NamedEntityType::EMAIL:
                        $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                        if($parsedAddress !== null)
                        {
                            $host = $parsedAddress['host'];
                            $id = $parsedAddress['id'];
                        }
                        break;

                    case NamedEntityType::DOMAIN:
                    case NamedEntityType::IPv4:
                    case NamedEntityType::IPv6:
                        $host = $entityIdentifier;
                        break;

                    case NamedEntityType::TELEPHONE:
                        $id = Utilities::canonicalizeTelephoneNumber($entityIdentifier);
                        $host = $id !== null ? Utilities::TELEPHONE_ENTITY_HOST : null;
                        break;
                }
            }
            elseif(Validate::url($entityIdentifier))
            {
                $host = parse_url($entityIdentifier, PHP_URL_HOST);
            }
            elseif(Validate::domain($entityIdentifier) || Validate::ipv4($entityIdentifier) || Validate::ipv6($entityIdentifier))
            {
                $host = $entityIdentifier;
            }

            if($host === null || $host === '')
            {
                return null;
            }

            try
            {
                return self::getEntityByHash(Utilities::hashEntity($host, $id));
            }
            catch (InvalidArgumentException $e)
            {
                Logger::log()->warning('Failed to resolve entity by identifier ' . $entityIdentifier . ': ' . $e->getMessage(), $e);
                return null;
            }
        }

        /**
         * Resolves every existing entity mentioned in the given text content, using the same named entity
         * extraction as content scanning.
         *
         * @param string $textContent The text content to extract named entities from
         * @return array<string, EntityRecord> The resolved entity records keyed by entity UUID
         */
        public static function resolveNamedEntities(string $textContent): array
        {
            $resolved = [];
            foreach(NamedEntityType::extract($textContent) as $entityIdentifier => $entityPosition)
            {
                try
                {
                    $entityRecord = self::resolveNamedEntity($entityIdentifier, $entityPosition);
                    if($entityRecord !== null)
                    {
                        $resolved[$entityRecord->getUuid()] = $entityRecord;
                    }
                }
                catch (DatabaseOperationException $e)
                {
                    Logger::log()->warning('Failed to resolve ' . $entityIdentifier . ': ' . $e->getMessage(), $e);
                }
            }

            return $resolved;
        }


        /**
         * Clears the reputation score of an entity back to 0, along with its unsettled activity
         *
         * @param string $uuid The target entity UUID
         * @param bool $affectParent True to affect parent entities recursively, False otherwise
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function clearReputation(string $uuid, bool $affectParent=false): void
        {
            $entity = self::getEntityByUuid($uuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET reputation=0, reputation_last_updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clear entity reputation: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            // Activity recorded before the reputation was cleared no longer counts
            ReputationManager::clearActivity($uuid);

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_REPUTATION_UPDATED, $uuid);

            if($affectParent)
            {
                $parentUuid = $entity->getRelationshipEntity();
                if($parentUuid !== null)
                {
                    self::clearReputation($parentUuid, $affectParent);
                }
            }
        }

        /**
         * Retrieves the oldest entity records that are eligible for cleanup, as raw record data.
         *
         * An entity is old when it was created, last updated and its reputation last changed before the given time.
         * Whitelisted entities and entities that still have evidence, reports or blacklist records are never cleaned
         * up, those records are cleaned up by their own retention.
         *
         * @param int $before The Unix timestamp before which a record is old
         * @param int $limit The maximum number of records to return
         * @return array[] An array of raw entity record data, oldest first
         * @throws InvalidArgumentException If the limit is out of range
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getOldRecords(int $before, int $limit=1000): array
        {
            if($limit < 1 || $limit > 10000)
            {
                throw new InvalidArgumentException('Limit must be between 1 and 10000.');
            }

            try
            {
                // GREATEST() is when the entity last changed: its creation, last update or last reputation change
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE whitelisted = 0 AND GREATEST(created, COALESCE(updated, created), COALESCE(reputation_last_updated, created)) < :before AND NOT EXISTS (SELECT 1 FROM evidence WHERE evidence.entity = entities.uuid) AND NOT EXISTS (SELECT 1 FROM reports WHERE reports.reporting_entity = entities.uuid) AND NOT EXISTS (SELECT 1 FROM blacklist WHERE blacklist.entity = entities.uuid) ORDER BY created ASC, uuid ASC LIMIT :limit");
                $stmt->bindValue(':before', date('Y-m-d H:i:s', $before));
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old entity records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves the domain entities (host entities whose host is a DNS name, not an IP address or the telephone
         * numbering namespace) whose DNS details are missing or were resolved before the given time, those never
         * resolved first and then the longest resolved ago. A resolution time in the future counts as missing.
         *
         * @param int $before The Unix timestamp before which DNS details are refreshed
         * @param int $limit The maximum number of entities to retrieve
         * @return EntityRecord[] The entities
         * @throws InvalidArgumentException If the limit is not between 1 and 10000
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getDnsRefreshCandidates(int $before, int $limit=500): array
        {
            if($limit < 1 || $limit > 10000)
            {
                throw new InvalidArgumentException('Limit must be between 1 and 10000.');
            }

            try
            {
                // The metadata is JSON text, an entity whose metadata is not valid JSON has no DNS details
                $resolved = "CAST(JSON_VALUE(metadata, '$." . DnsDetails::KEY_RESOLVED . "') AS SIGNED)";
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT * FROM entities WHERE id IS NULL AND " . self::NOT_TELEPHONE_CONDITION . " AND host LIKE '%.%' AND host NOT LIKE '%:%' AND host NOT REGEXP '^[0-9.]+$'
                     AND ($resolved IS NULL OR $resolved < :before OR $resolved > :now)
                     ORDER BY $resolved IS NOT NULL, $resolved ASC, created ASC, uuid ASC LIMIT :limit"
                );
                $stmt->bindValue(':before', $before, PDO::PARAM_INT);
                $stmt->bindValue(':now', time(), PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return array_map(fn(array $row) => new EntityRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve the entities to refresh the DNS details of: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Replaces the DNS details in the metadata of an entity (see DnsDetails), keeping every other metadata key. The
         * details are maintained by the host, so the entity is not considered changed by an operator: its updated
         * timestamp is left as it is, which also keeps refreshing the details from postponing the removal of an
         * entity by maintenance. When the details and the other metadata exceed the size of entity metadata, only
         * the resolution time and status are kept.
         *
         * @param EntityRecord $entity The entity
         * @param array<string, string|int> $details The DNS details
         * @return bool True if the details were stored, false if the metadata has no room for them
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function setDnsDetails(EntityRecord $entity, array $details): bool
        {
            $metadata = array_filter($entity->getMetadata() ?? [], fn($key) => !DnsDetails::isKey((string)$key), ARRAY_FILTER_USE_KEY);
            $updated = array_merge($metadata, $details);
            if(!Validate::metadata($updated))
            {
                $updated = array_merge($metadata, array_intersect_key($details, array_flip([DnsDetails::KEY_RESOLVED, DnsDetails::KEY_STATUS])));
                if(!Validate::metadata($updated))
                {
                    return false;
                }
            }

            try
            {
                $json = json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE entities SET metadata=:metadata WHERE uuid=:uuid");
                $stmt->bindValue(':metadata', $json);
                $stmt->bindValue(':uuid', $entity->getUuid());
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update the DNS details of an entity: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entity->getUuid()));
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, Utilities::hashEntity($entity->getHost(), $entity->getId())));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_UPDATED, $entity->getUuid());
            return true;
        }

        /**
         * Deletes the given entity records that are still eligible for cleanup (see getOldRecords()), a record that
         * changed since it was retrieved is kept. No record changes are dispatched for the deleted records.
         * The database sets the relationship of entities and the entity of audit log records that refer to a deleted
         * entity to null.
         *
         * @param string[] $uuids The UUIDs of the entity records to delete
         * @param int $before The Unix timestamp that was given to getOldRecords()
         * @return int The number of deleted records
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function purgeRecords(array $uuids, int $before): int
        {
            if(empty($uuids))
            {
                return 0;
            }

            [$placeholders, $parameters] = Utilities::getInPlaceholders($uuids);
            $parameters[':before'] = date('Y-m-d H:i:s', $before);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE uuid IN ($placeholders) AND whitelisted = 0 AND GREATEST(created, COALESCE(updated, created), COALESCE(reputation_last_updated, created)) < :before AND NOT EXISTS (SELECT 1 FROM evidence WHERE evidence.entity = entities.uuid) AND NOT EXISTS (SELECT 1 FROM reports WHERE reports.reporting_entity = entities.uuid) AND NOT EXISTS (SELECT 1 FROM blacklist WHERE blacklist.entity = entities.uuid)");
                $stmt->execute($parameters);
                return $stmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to purge entity records: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                    RedisConnection::clearRecords(AuditLogManager::CACHE_PREFIX);
                }
            }
        }

        /**
         * Searches entities by a LIKE pattern across uuid, host, and id columns, and with the extended search also
         * the metadata and relationship_entity columns.
         *
         * @param string $likePattern The SQL LIKE pattern to search with.
         * @param int $limit The maximum number of results to return.
         * @param int $page The page number for pagination.
         * @param bool $extended True to also match the columns of the extended search, available to operators
         * @return EntityRecord[] An array of matching EntityRecord objects.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function searchEntities(string $likePattern, int $limit, int $page, ?EntityCategory $category=null, ?string $by=null, ?OrderType $order=null, bool $extended=false, bool $excludeTelephone=false): array
        {
            $offset = ($page - 1) * $limit;

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new EntityRecord($data), $cached);
                }
            }

            try
            {
                $columns = ['uuid', 'host', 'id'];
                if ($extended)
                {
                    array_push($columns, 'metadata', 'relationship_entity');
                }

                $sql = "SELECT * FROM entities WHERE " . Utilities::buildLikeCondition($columns);
                if ($excludeTelephone)
                {
                    $sql .= ' AND ' . self::NOT_TELEPHONE_CONDITION;
                }

                $categoryCondition = $category?->toCondition() ?? '';
                if ($categoryCondition !== '')
                {
                    $sql .= " AND ($categoryCondition)";
                }

                $sortClause = self::buildEntitySortClause($by, $order);
                $sql .= " $sortClause LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindValue(':q', $likePattern);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $entities = array_map(fn($row) => new EntityRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to search entities: ' . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($entities))
            {
                RedisConnection::cacheSearchResults(
                    $cacheKey,
                    array_map(fn(EntityRecord $r) => $r->toArray(), $entities)
                );
            }

            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $entities, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    ttl: Configuration::getRedisConfiguration()->getEntitiesCacheTtl() ?? 0
                );
            }

            return $entities;
        }

        /**
         * Retrieves the top threats (entities with the lowest reputation scores).
         *
         * @param int $limit The maximum number of entities to return.
         * @return EntityRecord[] An array of EntityRecord objects ordered by reputation ascending.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function getTopThreats(int $limit=25, bool $excludeTelephone=false): array
        {
            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            // Shares the entity search namespace, which every entity insert, update and delete invalidates
            $cacheKey = null;
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, ['top_threats', $limit, $excludeTelephone]);
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if($cached !== null)
                {
                    return array_map(fn($data) => new EntityRecord($data), $cached);
                }
            }

            try
            {
                $condition = $excludeTelephone ? ' WHERE ' . self::NOT_TELEPHONE_CONDITION : '';
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities$condition ORDER BY reputation ASC, uuid DESC LIMIT :limit");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                $entities = array_map(fn($row) => new EntityRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to retrieve top threats: ' . $e->getMessage(), $e->getCode(), $e);
            }

            if($cacheKey !== null && !empty($entities))
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(EntityRecord $r) => $r->toArray(), $entities));
            }

            return $entities;
        }

        /**
         * Sets the whitelist state of an entity.
         *
         * @param string $entityUuid The UUID of the entity to update
         * @param bool $whitelisted The new whitelist state
         * @return bool True if the whitelist state was changed, False if it was already set to the given value
         * @throws InvalidArgumentException If the entity is not found
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function setEntityWhitelist(string $entityUuid, bool $whitelisted): bool
        {
            $entity = self::getEntityByUuid($entityUuid);
            if($entity === null)
            {
                throw new InvalidArgumentException('Entity not found');
            }

            if($entity->isWhitelisted() === $whitelisted)
            {
                return false;
            }

            try
            {
                $now = date('Y-m-d H:i:s');
                $whitelistedInt = $whitelisted ? 1 : 0;
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE entities SET whitelisted=:whitelisted, updated=:updated WHERE uuid=:uuid"
                );
                $stmt->bindParam(':whitelisted', $whitelistedInt, PDO::PARAM_INT);
                $stmt->bindParam(':updated', $now);
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update entity whitelist state: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $entityUuid));
                $hash = Utilities::hashEntity($entity->getHost(), $entity->getId());
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $hash));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ENTITY_UPDATED, $entityUuid);
            return true;
        }

        /**
         * Returns True if caching & entity caching is enabled for this class
         *
         * @return bool True if caching & caching for entities is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEntitiesCacheEnabled();
        }

        /**
         * Builds the Entity Short SQL Clause.
         *
         * @param string|null $by The column to sort by
         * @param OrderType|null $order The order to sort by
         * @return string Returns the condition clause.
         */
        private static function buildEntitySortClause(?string $by, ?OrderType $order): string
        {
            $column = 'created';
            $direction = 'DESC';

            if ($by !== null)
            {
                $filterType = EntityOrderType::tryFromCaseInsensitive($by);
                if ($filterType !== null)
                {
                    $column = $filterType->toColumn();
                }
            }

            if ($order !== null)
            {
                $direction = $order->value;
            }

            $secondaryDirection = $direction === 'ASC' ? 'ASC' : 'DESC';
            return "ORDER BY $column $direction, uuid $secondaryDirection";
        }

        /**
         * Links the existing subdomain host entities of a newly registered registrable domain entity to it as a
         * CHILD, so that sub.example.com registered before example.com ends up linked the same way as when
         * example.com was registered first. Only host entities without a relationship are linked, and hosts that
         * are not a registrable domain are left alone. Failures are logged rather than thrown, since the domain
         * entity itself has already been registered.
         *
         * @param string $entityUuid The UUID of the registrable domain entity
         * @param string $host The canonical host of the registrable domain entity
         * @return int The number of subdomain entities that were linked
         */
        private static function adoptSubdomainEntities(string $entityUuid, string $host): int
        {
            if(Utilities::getRegistrableDomain($host) !== $host)
            {
                return 0;
            }

            try
            {
                $pattern = '%.' . str_replace(['%', '_'], ['\\%', '\\_'], $host);
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT uuid, host FROM entities WHERE id IS NULL AND relationship_entity IS NULL AND host LIKE :pattern ESCAPE '\\\\'"
                );
                $stmt->bindParam(':pattern', $pattern);
                $stmt->execute();
                $subdomainEntities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch(PDOException $e)
            {
                Logger::log()->warning(sprintf('Failed to retrieve subdomain entities of %s: %s', $host, $e->getMessage()), $e);
                return 0;
            }

            $linked = 0;
            foreach($subdomainEntities as $subdomainEntity)
            {
                // Hosts such as sub.example.com.au also end with ".com.au", only link those under this exact domain
                if(Utilities::getRegistrableDomain($subdomainEntity['host']) !== $host)
                {
                    continue;
                }

                try
                {
                    self::assignEntityRelationship($subdomainEntity['uuid'], $entityUuid, EntityRelationshipType::CHILD);
                    $linked++;
                }
                catch(DatabaseOperationException|InvalidArgumentException $e)
                {
                    Logger::log()->warning(sprintf('Failed to assign parent domain %s to entity %s: %s', $host, $subdomainEntity['host'], $e->getMessage()), $e);
                }
            }

            return $linked;
        }
    }
