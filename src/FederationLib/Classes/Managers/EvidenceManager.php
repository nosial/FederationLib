<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EvidenceRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\UuidV4;

    class EvidenceManager
    {
        public const string CACHE_PREFIX = 'evidence:';

        /**
         * Adds a new evidence record to the database.
         *
         * @param string $entity The UUID of the entity associated with the evidence.
         * @param string $operator The UUID of the operator associated with the evidence.
         * @param string|null $textContent Optional text content, can be null.
         * @param string|null $note Optional note, can be null.
         * @param string|null $tag Optional tag, must be underscored and alphanumeric
         * @param bool $confidential Whether the evidence is confidential (default is false).
         * @param string|null $report Optional. The UUID of the report record that this evidence record is associated with
         * @param array|null $metadata Optional. Metadata to associate with the evidence record
         * @throws InvalidArgumentException If the entity or operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @return string The UUID of the newly created evidence record.
         */
        public static function addEvidence(string $entity, string $operator, ?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false, ?string $report=null, ?array $metadata=null): string
        {
            if(strlen($entity) < 1 || strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Entity and operator must be provided.');
            }

            if($textContent !== null)
            {
                if(strlen($textContent) === 0)
                {
                    throw new InvalidArgumentException('Text content cannot be empty if provided');
                }

                if(strlen($textContent) > 16777215)
                {
                    throw new InvalidArgumentException('Text content cannot be longer than 16777215 characters');
                }
            }

            if($note !== null)
            {
                if(strlen($note) === 0)
                {
                    throw new InvalidArgumentException('Note cannot be empty if provided');
                }

                if(strlen($note) > 65535)
                {
                    throw new InvalidArgumentException('Note cannot be longer than 65535 characters');
                }
            }

            if($tag !== null)
            {
                if(strlen($tag) === 0)
                {
                    throw new InvalidArgumentException('Tag cannot be empty if provided');
                }

                if(strlen($tag) > 32)
                {
                    throw new InvalidArgumentException('Tag cannot be longer than 32 characters');
                }

                if(!Validate::evidenceTag($tag))
                {
                    throw new InvalidArgumentException('Tag must be alphanumeric and spaces must be underscores');
                }
            }

            if($report !== null)
            {
                if(!Validate::uuid($report))
                {
                    throw new InvalidArgumentException('Invalid report UUID');
                }

                if(!ReportManager::reportExists($report))
                {
                    throw new InvalidArgumentException('The referenced report UUID does not exist');
                }
            }

            if($metadata !== null)
            {
                if(!Validate::entityMetadata($metadata))
                {
                    throw new InvalidArgumentException('Invalid evidence metadata provided');
                }
            }

            $uuid = UuidV4::v4()->toRfc4122();

            try
            {
                $columns = 'uuid, entity, operator, confidential, text_content, note, tag, report';
                $values = ':uuid, :entity, :operator, :confidential, :text_content, :note, :tag, :report';

                if($metadata !== null)
                {
                    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $columns .= ', metadata';
                    $values .= ', :metadata';
                }

                $sql = "INSERT INTO evidence ($columns) VALUES ($values)";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':confidential', $confidential, PDO::PARAM_BOOL);
                $stmt->bindParam(':text_content', $textContent);
                $stmt->bindParam(':note', $note);
                $stmt->bindParam(':tag', $tag);
                $stmt->bindParam(':report', $report);

                if($metadata !== null)
                {
                    $stmt->bindParam(':metadata', $metadataJson);
                }
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to add evidence: " . $e->getMessage(), $e->getCode(), $e);
            }

            return $uuid;
        }

        /**
         * Deletes an evidence record by its UUID.
         *
         * @param string $uuid The UUID of the evidence record to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws InvalidArgumentException If the UUID is not a valid UUID format.
         */
        public static function deleteEvidence(string $uuid): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('Evidence UUID must be provided.');
            }

            if(!Validate::uuid($uuid))
            {
                throw new InvalidArgumentException('Evidence UUID must be valid');
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
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    RedisConnection::deleteRecordsByField(BlacklistManager::CACHE_PREFIX, 'evidence', $uuid);
                    RedisConnection::deleteRecordsByField(FileAttachmentManager::CACHE_PREFIX, 'evidence', $uuid);
                }
            }
        }

        /**
         * Retrieves a specific evidence record by its UUID.
         *
         * @param string $evidenceUuid The UUID of the evidence record.
         * @return EvidenceRecord|null The EvidenceRecord object if found, null otherwise.
         * @throws DatabaseOperationException Thrown if there was a database exception
         */
        public static function getEvidence(string $evidenceUuid): ?EvidenceRecord
        {
            if(strlen($evidenceUuid) < 1)
            {
                throw new InvalidArgumentException('Evidence UUID must be provided');
            }

            if(!Validate::uuid($evidenceUuid))
            {
                throw new InvalidArgumentException('Invalid Evidence UUID');
            }

            if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)))
            {
                return new EvidenceRecord(RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)));
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data === false)
                {
                    return null; // No evidence found with the given UUID
                }

                $data = new EvidenceRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getEvidenceCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getEvidenceCacheTtl()
                );
            }

            return $data;
        }
        
        /**
         * Retrieves all evidence records from the database.
         *
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws InvalidArgumentException If the limit or page parameters are invalid.
         */
        public static function getEvidenceRecords(int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence";

                if(!$includeConfidential)
                {
                    $query .= " WHERE confidential=0";
                }

                $query .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";

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
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $evidenceRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getEvidenceCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getEvidenceCacheTtl()
                );
            }

            return $evidenceRecords;
        }

        /**
         * Retrieves all evidence records associated with a specific entity.
         *
         * @param string $entityUuid The UUID of the entity.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws InvalidArgumentException If the entity UUID is not provided, is empty, or is not a valid UUID.
         */
        public static function getEvidenceByEntity(string $entityUuid, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($entityUuid) < 1)
            {
                throw new InvalidArgumentException('Entity UUID must be provided.');
            }

            if(!Validate::uuid($entityUuid))
            {
                throw new InvalidArgumentException('Entity UUID must be valid');
            }

            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE entity = :entity";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':entity', $entityUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by entity: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $evidenceRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getEvidenceCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getEvidenceCacheTtl()
                );
            }

            return $evidenceRecords;
        }

        /**
         * Retrieves all evidence records associated with a specific operator.
         *
         * @param string $operatorUuid The UUID of the operator.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws InvalidArgumentException If the operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByOperator(string $operatorUuid, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($operatorUuid) < 1)
            {
                throw new InvalidArgumentException('Operator must be provided.');
            }
            
            if(!Validate::uuid($operatorUuid))
            {
                throw new InvalidArgumentException('Operator UUID must be valid');
            }

            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE operator = :operator";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':operator', $operatorUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by operator: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $evidenceRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getEvidenceCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getEvidenceCacheTtl()
                );
            }

            return $evidenceRecords;
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

            if(!Validate::evidenceTag($tagName))
            {
                throw new InvalidArgumentException('Tag name must be alphanumeric and spaces must be underscores');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE tag = :tag";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";

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
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by tag: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $evidenceRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getEvidenceCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getEvidenceCacheTtl()
                );
            }

            return $evidenceRecords;
        }

        /**
         * Returns an array of evidence records assigned to a report record
         *
         * @param string $report The report UUID to search by
         * @param int $limit The maximum number of records to return
         * @param int $page The page to view
         * @param bool $includeConfidential True to include confidential evidence records, False otherwise
         * @return EvidenceRecord[] An array of EvidenceRecord objcts
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function getEvidenceByReport(string $report, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($report) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($report))
            {
                throw new InvalidArgumentException('Invalid report UUID');
            }

            if(!ReportManager::reportExists($report))
            {
                throw new InvalidArgumentException('Report record not found');
            }

            if($limit < 1)
            {
                throw new InvalidArgumentException('Limit must be greater than zero.');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than zero.');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE report=:report";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':report', $report);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by report: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $evidenceRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getEvidenceCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getEvidenceCacheTtl()
                );
            }

            return $evidenceRecords;
        }

        /**
         * Checks if an evidence record exists by its UUID.
         *
         * @param string $evidenceUuid The UUID of the evidence record to check.
         * @return bool True if the evidence exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function evidenceExists(string $evidenceUuid): bool
        {
            if(strlen($evidenceUuid) < 1)
            {
                throw new InvalidArgumentException('Evidence UUID must be provided.');
            }

            if(!Validate::uuid($evidenceUuid))
            {
                throw new InvalidArgumentException('Evidence UUID must be valid');
            }

            if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)))
            {
                return true;
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->execute();

                $exists = $stmt->fetchColumn() > 0; // Returns true if evidence exists, false otherwise
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check evidence existence: " . $e->getMessage(), $e->getCode(), $e);
            }

            return $exists;
        }

        /**
         * Updates the confidentiality status of an evidence record.
         *
         * @param string $evidenceUuid The UUID of the evidence record to update.
         * @param bool $confidential The new confidentiality status (true for confidential, false for non-confidential).
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function updateConfidentiality(string $evidenceUuid, bool $confidential): void
        {
            if(strlen($evidenceUuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET confidential = :confidential WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->bindParam(':confidential', $confidential, PDO::PARAM_BOOL);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update confidentiality: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid));
                }
            }
        }

        /**
         * Updates the tag of an existing evidence record
         *
         * @param string $evidenceUuid The evidence UUID record to update
         * @param string $tagName The new tag to set
         * @throws DatabaseOperationException Thrown if the record could not be updated
         */
        public static function updateTag(string $evidenceUuid, string $tagName): void
        {
            if(strlen($tagName) < 1)
            {
                throw new InvalidArgumentException('Tag name must be provided.');
            }

            if(!Validate::evidenceTag($tagName))
            {
                throw new InvalidArgumentException('Tag name must be alphanumeric and spaces must be underscores');
            }

            if(strlen($evidenceUuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            $now = date('Y-m-d H:i:s');

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET tag=:tag, updated=:updated WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->bindParam(':tag', $tagName);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update tag: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid));
                }
            }
        }

        /**
         * Updates/sets the classification flag for an evidence record
         *
         * @param string $evidence The UUID of the evidence record to update
         * @param ClassificationFlag $classification The classification flag to set
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function updateClassificationFlag(string $evidence, ClassificationFlag $classification): void
        {
            $now = date('Y-m-d H:i:s');
            $classificationValue = $classification->value;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET classification_flag=:classification_flag, updated=:updated WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $evidence);
                $stmt->bindParam(':classification_flag', $classificationValue);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update classification flag: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidence)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $evidence));
                }
            }
        }

        /**
         * Updates the report association of an existing evidence record.
         *
         * @param string $evidenceUuid The UUID of the evidence record to update
         * @param string $reportUuid The UUID of the report to associate with the evidence record
         * @throws InvalidArgumentException If the evidence or report UUID is invalid
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function updateEvidenceReport(string $evidenceUuid, string $reportUuid): void
        {
            if(strlen($evidenceUuid) < 1)
            {
                throw new InvalidArgumentException('Evidence UUID must be provided.');
            }

            if(!Validate::uuid($evidenceUuid))
            {
                throw new InvalidArgumentException('Invalid evidence UUID');
            }

            if(strlen($reportUuid) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($reportUuid))
            {
                throw new InvalidArgumentException('Invalid report UUID');
            }

            if(!ReportManager::reportExists($reportUuid))
            {
                throw new InvalidArgumentException('The referenced report UUID does not exist');
            }

            $now = date('Y-m-d H:i:s');

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET report=:report, updated=:updated WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->bindParam(':report', $reportUuid);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update evidence report: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid));
                }
            }
        }

        /**
         * Counts the total number of evidence records in the database.
         *
         * @return int The total number of evidence records.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
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
         * Retrieves evidence records older than the specified TTL.
         *
         * @param int $ttl The TTL in seconds to look back
         * @param int $limit The maximum number of records to return
         * @param int $page The page number for pagination
         * @return array[] An array of raw evidence record data
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getOldRecords(int $ttl, int $limit=1000, int $page=1): array
        {
            if($ttl <= 0)
            {
                throw new InvalidArgumentException('TTL must be greater than zero.');
            }

            $timestamp = date('Y-m-d H:i:s', time() - $ttl);
            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT * FROM evidence WHERE created < :timestamp ORDER BY created ASC LIMIT :limit OFFSET :offset"
                );
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes evidence records older than the specified TTL.
         * Related file_attachments and blacklist records are cascade-deleted by the database.
         *
         * @param int $ttl The TTL in seconds after which evidence records are considered old
         * @return int The number of deleted records
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function cleanEntries(int $ttl): int
        {
            if($ttl <= 0)
            {
                throw new InvalidArgumentException('TTL must be greater than zero.');
            }

            $timestamp = date('Y-m-d H:i:s', time() - $ttl);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM evidence WHERE created < :timestamp");
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->execute();
                return $stmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                    RedisConnection::clearRecords(FileAttachmentManager::CACHE_PREFIX);
                    RedisConnection::clearRecords(BlacklistManager::CACHE_PREFIX);
                }
            }
        }

        /**
         * Checks if caching is enabled based on the configuration.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEvidenceCacheEnabled();
        }
    }
