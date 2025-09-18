<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\BlacklistRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\UuidV4;

    class BlacklistManager
    {
        public const string CACHE_PREFIX = 'blacklist:';

        /**
         * Blacklists an entity with the specified operator and type.
         *
         * @param string $entityUuid The UUID of the entity to blacklist.
         * @param string $operatorUuid The UUID of the operator performing the blacklisting.
         * @param BlacklistType $type The type of blacklist action.
         * @param int|null $expires Optional expiration time in Unix timestamp, null for permanent blacklisting.
         * @param string|null $evidenceUuid Optional evidence UUID, must be a valid UUID if provided.
         * @return string The UUID of the created blacklist entry.
         * @throws InvalidArgumentException If the entity or operator is empty, or if expires is in the past.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function blacklistEntity(string $entityUuid, string $operatorUuid, BlacklistType $type, ?int $expires=null, ?string $evidenceUuid=null): string
        {
            if(empty($entityUuid) || empty($operatorUuid))
            {
                throw new InvalidArgumentException("Entity and operator cannot be empty.");
            }

            if(!is_null($expires) && $expires < time())
            {
                throw new InvalidArgumentException("Expiration time must be in the future or null for permanent blacklisting.");
            }

            if(!is_null($evidenceUuid) && !Validate::uuid($evidenceUuid))
            {
                throw new InvalidArgumentException("Evidence must be a valid UUID.");
            }

            $uuid = UuidV4::v4()->toRfc4122();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO blacklist (uuid, entity, operator, type, expires, evidence) VALUES (:blacklist_uuid, :entity_uuid, :operator_uuid, :type, :expires, :evidence)");
                $stmt->bindParam(':blacklist_uuid', $uuid);
                $type = $type->value;
                $stmt->bindParam(':entity_uuid', $entityUuid);
                $stmt->bindParam(':operator_uuid', $operatorUuid);
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':evidence', $evidenceUuid);

                // Convert expires to datetime
                if(is_null($expires))
                {
                    $stmt->bindValue(':expires', null, PDO::PARAM_NULL);
                }
                else
                {
                    $stmt->bindValue(':expires', date('Y-m-d H:i:s', $expires));
                }
                
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to prepare SQL statement for blacklisting entity: " . $e->getMessage(), 0, $e);
            }

            return $uuid;
        }

        /**
         * Checks if an entity is currently blacklisted.
         *
         * @param string $entityUuid The UUID of the entity to check.
         * @return bool True if the entity is blacklisted, false otherwise.
         * @throws InvalidArgumentException If the entity is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function isBlacklisted(string $entityUuid): bool
        {
            if(empty($entityUuid))
            {
                throw new InvalidArgumentException("Entity cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM blacklist WHERE entity = :entity_uuid AND (expires IS NULL OR expires > NOW())");
                $stmt->bindParam(':entity_uuid', $entityUuid);
                $stmt->execute();
                return $stmt->fetchColumn() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check if entity is blacklisted: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Checks if a blacklist entry exists for a specific UUID.
         *
         * @param string $blacklistUuid The UUID of the blacklist entry.
         * @return bool True if the blacklist entry exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function blacklistExists(string $blacklistUuid): bool
        {
            if(empty($blacklistUuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            if(self::isCachingEnabled())
            {
                $recordExists = RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $blacklistUuid));
                if($recordExists)
                {
                    return true;
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM blacklist WHERE uuid=:blacklist_uuid LIMIT 1");
                $stmt->bindParam(':blacklist_uuid', $blacklistUuid);
                $stmt->execute();
                return $stmt->fetchColumn() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check if blacklist exists: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves a blacklist entry by its UUID.
         *
         * @param string $blacklistUuid The UUID of the blacklist entry.
         * @return BlacklistRecord|null The BlacklistRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getBlacklistEntry(string $blacklistUuid): ?BlacklistRecord
        {
            if(empty($blacklistUuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            if(self::isCachingEnabled())
            {
                $cachedRecord = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $blacklistUuid));
                if($cachedRecord !== null)
                {
                    return new BlacklistRecord($cachedRecord);
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE uuid=:blacklist_uuid LIMIT 1");
                $stmt->bindParam(':blacklist_uuid', $blacklistUuid);
                $stmt->execute();
                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$data)
                {
                    return null;
                }

                $result = new BlacklistRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entry: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getBlacklistCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $result, cacheKey: self::CACHE_PREFIX,
                    ttl: Configuration::getRedisConfiguration()->getBlacklistCacheTTL()
                );
            }

            return $result;
        }

        /**
         * Deletes a blacklist entry for a specific entity.
         *
         * @param string $blacklistUuid The UUID of the blacklist entry to delete.
         * @throws InvalidArgumentException If the entity is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteBlacklistRecord(string $blacklistUuid): void
        {
            if(empty($blacklistUuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM blacklist WHERE uuid = :blacklist_uuid");
                $stmt->bindParam(':blacklist_uuid', $blacklistUuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete blacklist record: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $blacklistUuid)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $blacklistUuid));
                }
            }
        }

        /**
         * Lifts a blacklist record, marking it as no longer active.
         *
         * @param string $blacklistUuid The UUID of the blacklist record to lift.
         * @param string|null $operatorUuid The UUID of the operator that is lifting the blacklist, optional.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function liftBlacklistRecord(string $blacklistUuid, ?string $operatorUuid=null): void
        {
            if(empty($blacklistUuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE blacklist SET lifted=1, lifted_by=:operator_uuid WHERE uuid=:blacklist_uuid");
                $stmt->bindParam(':operator_uuid', $operatorUuid);
                $stmt->bindParam(':blacklist_uuid', $blacklistUuid);

                if(!$stmt->execute() || $stmt->rowCount() === 0)
                {
                    throw new DatabaseOperationException("No blacklist record found with the specified UUID to lift.");
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to lift blacklist record: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $blacklistUuid)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $blacklistUuid));
                }
            }
        }

        /**
         * Returns an array of blacklist records in a pagination style for the global database
         *
         * @param int $limit The total amount of records to return in this database query
         * @param int $page The current page, must start from 1.
         * @param bool $includeLifted If True, lifted blacklist records are included in the result
         * @return BlacklistRecord[] An array of blacklist records as the result
         * @throws DatabaseOperationException Thrown if there was a database issue
         */
        public static function getEntries(int $limit=100, int $page=1, bool $includeLifted=false): array
        {
            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                if ($includeLifted)
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist ORDER BY created DESC LIMIT :limit OFFSET :offset");
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE lifted=0 ORDER BY created DESC LIMIT :limit OFFSET :offset");
                }

                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $results = array_map(fn($data) => new BlacklistRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entries: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $results, prefix: self::CACHE_PREFIX,
                    limit: Configuration::getRedisConfiguration()->getBlacklistCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getBlacklistCacheTTL()
                );
            }

            return $results;
        }

        /**
         * Retrieves all blacklist entries for a specific entity.
         *
         * @param string $operatorUuid The UUID of the operator to filter by.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param bool $includeLifted If True, lifted blacklist records are included in the result
         * @return BlacklistRecord[] An array of BlacklistRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByOperator(string $operatorUuid, int $limit=100, int $page=1, bool $includeLifted=false): array
        {
            if(empty($operatorUuid))
            {
                throw new InvalidArgumentException("Operator cannot be empty.");
            }

            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                if ($includeLifted)
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE operator=:operator_uuid ORDER BY created DESC LIMIT :limit OFFSET :offset");
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE operator=:operator_uuid AND lifted=0 ORDER BY created DESC LIMIT :limit OFFSET :offset");
                }

                $stmt->bindParam(':operator_uuid', $operatorUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $results = array_map(fn($data) => new BlacklistRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entries by operator: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $results, prefix: self::CACHE_PREFIX,
                    limit: Configuration::getRedisConfiguration()->getBlacklistCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getBlacklistCacheTTL()
                );
            }

            return $results;
        }

        /**
         * Retrieves all blacklist entries associated with a specific entity.
         *
         * @param string $entityUuid The UUID of the entity.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param bool $includeLifted If True, lifted entries will be included in the result
         * @return BlacklistRecord[] An array of BlacklistRecord objects.
         * @throws InvalidArgumentException If the entity is empty or limit/page are invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByEntity(string $entityUuid, int $limit=100, int $page=1, bool $includeLifted=false): array
        {
            if(empty($entityUuid))
            {
                throw new InvalidArgumentException("Entity cannot be empty.");
            }

            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                if ($includeLifted)
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE entity=:entity_uuid ORDER BY created DESC LIMIT :limit OFFSET :offset");
                }
                else
                {
                    $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE entity=:entity_uuid AND lifted=0 ORDER BY created DESC LIMIT :limit OFFSET :offset");
                }

                $stmt->bindParam(':entity_uuid', $entityUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $results = array_map(fn($data) => new BlacklistRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entries by entity: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $results, prefix: self::CACHE_PREFIX,
                    limit: Configuration::getRedisConfiguration()->getBlacklistCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getBlacklistCacheTTL()
                );
            }

            return $results;
        }

        /**
         * Cleans up blacklist entries that have expired based on the specified number of days.
         *
         * @param int $getCleanBlacklistDays The number of days to consider for cleaning expired entries.
         * @return int The number of entries cleaned.
         * @throws InvalidArgumentException If the number of days is less than or equal to zero.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function cleanEntries(int $getCleanBlacklistDays): int
        {
            // Remove blacklist records older than $cleanBlacklistDays and if the expiration hasn't been expired yet
            if($getCleanBlacklistDays <= 0)
            {
                throw new InvalidArgumentException("Number of days must be greater than zero.");
            }

            // Mariadb uses Timestamp
            $dateThreshold = date('Y-m-d H:i:s', strtotime("-$getCleanBlacklistDays days"));
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM blacklist WHERE (expires IS NOT NULL AND expires < :date_threshold) OR (expires IS NULL AND created < :date_threshold)");
                $stmt->bindParam(':date_threshold', $dateThreshold);
                $stmt->execute();

                return $stmt->rowCount(); // Return the number of rows affected
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean blacklist entries: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                }
            }
        }

        /**
         * Returns the total number of blacklist entries in the database.
         *
         * @return int The total number of blacklist entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM blacklist");
                $stmt->execute();
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve total blacklist entries: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Checks if caching is enabled based on the configuration.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isBlacklistCacheEnabled();
        }
    }