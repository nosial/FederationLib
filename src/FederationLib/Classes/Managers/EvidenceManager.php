<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EvidenceRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use RedisException;
    use Symfony\Component\Uid\UuidV4;

    class EvidenceManager
    {
        private const string EVIDENCE_CACHE_PREFIX = 'evidence_';

        /**
         * Adds a new evidence record to the database.
         *
         * @param string $entity The UUID of the entity associated with the evidence.
         * @param string $operator The UUID of the operator associated with the evidence.
         * @param string|null $textContent Optional text content, can be null.
         * @param string|null $note Optional note, can be null.
         * @param string|null $tag Optional tag, must be underscore and alphanumeric
         * @param bool $confidential Whether the evidence is confidential (default is false).
         * @throws InvalidArgumentException If the entity or operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         * @return string The UUID of the newly created evidence record.
         */
        public static function addEvidence(string $entity, string $operator, ?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false): string
        {
            if(strlen($entity) < 1 || strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Entity and operator must be provided.');
            }

            // TODO: Validate $tag

            $uuid = UuidV4::v4()->toRfc4122();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO evidence (uuid, entity, operator, confidential, text_content, note, tag) VALUES (:uuid, :entity, :operator, :confidential, :text_content, :note, :tag)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':confidential', $confidential);
                $stmt->bindParam(':text_content', $textContent);
                $stmt->bindParam(':note', $note);
                $stmt->bindParam(':tag', $tag);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to add evidence: " . $e->getMessage(), $e->getCode(), $e);
            }

            // If caching and pre-caching is enabled, retrieve the existing evidence record and cache it
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                // If the limit has not been exceeded, cache the evidence record
                if (!RedisConnection::limitExceeded(self::EVIDENCE_CACHE_PREFIX, Configuration::getRedisConfiguration()->getEvidenceCacheLimit()))
                {
                    RedisConnection::setCacheRecord(self::getEvidence($uuid), self::getCacheKey($uuid), Configuration::getRedisConfiguration()->getEvidenceCacheTtl());
                }
            }

            return $uuid;
        }

        /**
         * Deletes an evidence record by its UUID.
         *
         * @param string $uuid The UUID of the evidence record to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function deleteEvidence(string $uuid): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete evidence: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && RedisConnection::cacheRecordExists(self::getCacheKey($uuid)))
            {
                Logger::log()->debug(sprintf("Deleting cache for evidence with UUID '%s'", $uuid));
                $cacheKey = self::getCacheKey($uuid);

                try
                {
                    RedisConnection::getConnection()->del($cacheKey);
                }
                catch (RedisException $e)
                {
                    throw new CacheOperationException(sprintf("Failed to delete cache for evidence with UUID '%s'", $uuid), 0, $e);
                }
            }
        }

        /**
         * Retrieves a specific evidence record by its UUID.
         *
         * @param string $uuid The UUID of the evidence record.
         * @return EvidenceRecord|null The EvidenceRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getEvidence(string $uuid): ?EvidenceRecord
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            if(self::isCachingEnabled() && RedisConnection::cacheRecordExists(self::getCacheKey($uuid)))
            {
                // If caching is enabled and the evidence exists in the cache, return it
                return new EvidenceRecord(RedisConnection::getRecordFromCache(self::getCacheKey($uuid)));
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data === false)
                {
                    return null; // No evidence found with the given UUID
                }

                $evidenceRecord = new EvidenceRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                try
                {
                    if(RedisConnection::getConnection()->exists(self::getCacheKey($uuid)))
                    {
                        return $evidenceRecord; // Return the cached record if it exists
                    }

                    Logger::log()->debug(sprintf("Caching evidence with UUID '%s'", $uuid));
                    RedisConnection::setCacheRecord($evidenceRecord, self::getCacheKey($uuid), Configuration::getRedisConfiguration()->getEvidenceCacheTtl());
                }
                // Database operations can fail, but we don't want to throw cache exceptions if it could be ignored
                catch (RedisException $e)
                {
                    Logger::log()->error(sprintf("Failed to cache evidence with UUID '%s': %s", $uuid, $e->getMessage()));
                    if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                    {
                        throw new CacheOperationException(sprintf("Failed to cache evidence with UUID '%s'", $uuid), 0, $e);
                    }
                }
            }

            return $evidenceRecord;
        }
        
        /**
         * Retrieves all evidence records from the database.
         *
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceRecords(int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence";
                if(!$includeConfidential)
                {
                    $query .= " WHERE confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves all evidence records associated with a specific operator.
         *
         * @param string $entity The UUID of the entity.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByEntity(string $entity, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($entity) < 1)
            {
                throw new InvalidArgumentException('Entity must be provided.');
            }
            
            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE entity = :entity";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by entity: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves all evidence records associated with a specific operator.
         *
         * @param string $operator The UUID of the operator.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws InvalidArgumentException If the operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByOperator(string $operator, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Operator must be provided.');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE operator = :operator";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by operator: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves all evidence records associated with a specific tag.
         *
         * @param string $tagName The tag name
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByTag(string $tagName, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($tagName) < 1)
            {
                throw new InvalidArgumentException('Tag name must be provided.');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE tag = :tag";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':tag', $tagName);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by entity: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an evidence record exists by its UUID.
         *
         * @param string $uuid The UUID of the evidence record to check.
         * @return bool True if the evidence exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function evidenceExists(string $uuid): bool
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            if(self::isCachingEnabled() && RedisConnection::cacheRecordExists(self::getCacheKey($uuid)))
            {
                // If caching is enabled and the evidence exists in the cache, return true
                return true;
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $exists = $stmt->fetchColumn() > 0; // Returns true if evidence exists, false otherwise
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check evidence existence: " . $e->getMessage(), $e->getCode(), $e);
            }

            if($exists && self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                self::getEvidence($uuid);
            }

            return $exists;
        }

        /**
         * Updates the confidentiality status of an evidence record.
         *
         * @param string $uuid The UUID of the evidence record to update.
         * @param bool $confidential The new confidentiality status (true for confidential, false for non-confidential).
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function updateConfidentiality(string $uuid, bool $confidential): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET confidential = :confidential WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':confidential', $confidential, PDO::PARAM_BOOL);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update confidentiality: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                Logger::log()->debug(sprintf("Updating cache for evidence with UUID '%s' after confidentiality update", $uuid));
                $updateSuccess = RedisConnection::updateCacheRecord(self::getCacheKey($uuid), 'confidential', $confidential);
                if(!$updateSuccess && Configuration::getRedisConfiguration()->isPreCacheEnabled())
                {
                    self::getEvidence($uuid);
                }
            }
        }

        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->query("SELECT COUNT(*) FROM evidence");
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if caching is enabled.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEvidenceCacheEnabled();
        }

        /**
         * Generates a cache key for the given UUID.
         *
         * @param string $uuid The UUID of the evidence record.
         * @return string The cache key.
         */
        private static function getCacheKey(string $uuid): string
        {
            return sprintf("%s%s", self::EVIDENCE_CACHE_PREFIX, $uuid);
        }
    }
