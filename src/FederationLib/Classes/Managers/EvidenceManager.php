<?php

    namespace FederationLib\Classes\Managers;

    use DomainException;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\Categories\EvidenceCategory;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\ContentType;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\EvidenceOrderType;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EvidenceRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;
    use Throwable;

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
         * @param ClassificationFlag|null $classification Optional classification assigned when evidence is created
         * @param ContentType $contentType Optional. The content type of the text content (default TEXT)
         * @param array|null $context Optional. The context of the content, its keys depend on the content type
         * @param bool $automated Optional. Whether the evidence is automated: created by the host itself or submitted
         *                        by a bot operator. Automated evidence is always created confidential.
         * @throws InvalidArgumentException If the entity or operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @return string The UUID of the newly created evidence record.
         */
        public static function addEvidence(string $entity, string $operator, ?string $textContent=null, ?string $note=null, ?string $tag=null, bool $confidential=false, ?string $report=null, ?array $metadata=null, ?ClassificationFlag $classification=null, ContentType $contentType=ContentType::TEXT, ?array $context=null, bool $automated=false): string
        {
            if(strlen($entity) < 1 || strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Entity and operator must be provided.');
            }

            if($textContent !== null)
            {
                $textContent = $contentType->normalizeTextContent($textContent);
            }

            $contentType->validate($textContent, $context);
            if($context === [])
            {
                $context = null;
            }

            if($automated)
            {
                $confidential = true;
            }

            if(!$confidential && EntitiesManager::getEntityByUuid($entity)?->isTelephone())
            {
                $confidential = true;
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
                if(!Validate::metadata($metadata))
                {
                    throw new InvalidArgumentException('Invalid evidence metadata provided');
                }
            }

            $uuid = Uuid::v7()->toRfc4122();

            try
            {
                $columns = 'uuid, entity, operator, confidential, automated, text_content, content_type, note, tag, report, classification_flag';
                $values = ':uuid, :entity, :operator, :confidential, :automated, :text_content, :content_type, :note, :tag, :report, :classification_flag';
                $classificationValue = $classification?->value;
                $contentTypeValue = $contentType->value;

                if($context !== null)
                {
                    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $columns .= ', context';
                    $values .= ', :context';
                }

                if($classification !== null)
                {
                    $columns .= ', classified';
                    $values .= ', :classified';
                    $classified = date('Y-m-d H:i:s');
                }

                if($metadata !== null)
                {
                    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $columns .= ', metadata';
                    $values .= ', :metadata';
                }

                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO evidence ($columns) VALUES ($values)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':confidential', $confidential, PDO::PARAM_BOOL);
                $stmt->bindParam(':automated', $automated, PDO::PARAM_BOOL);
                $stmt->bindParam(':text_content', $textContent);
                $stmt->bindParam(':content_type', $contentTypeValue);
                if($context !== null)
                {
                    $stmt->bindParam(':context', $contextJson);
                }
                $stmt->bindParam(':note', $note);
                $stmt->bindParam(':tag', $tag);
                $stmt->bindParam(':report', $report);
                $stmt->bindParam(':classification_flag', $classificationValue);
                if($classification !== null)
                {
                    $stmt->bindParam(':classified', $classified);
                }

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

            if(self::isCachingEnabled())
            {
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_CREATED, $uuid);
            if($classification !== null)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_CLASSIFIED, $uuid);
            }

            return $uuid;
        }

        /**
         * Deletes an evidence record by its UUID.
         *
         * @param string $uuid The UUID of the evidence record to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DomainException If declassification would expose evidence of illegal content.
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

            // The record as it was is handed to the EVIDENCE_DELETED event handlers, eg; to forget its content
            $deletedRecord = self::getEvidence($uuid);
            // The database deletes the attachments of the evidence, but not their stored files
            $attachmentUuids = FileAttachmentManager::getUuidsByEvidenceColumn('uuid', $uuid);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
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
                    RedisConnection::deleteRecordsByField(FileAttachmentManager::CACHE_PREFIX, 'evidence', $uuid);
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                FileAttachmentManager::forgetDeletedRecords($attachmentUuids);
                PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_DELETED, $uuid, $deletedRecord);
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

            if(self::isCachingEnabled())
            {
                $cached = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid));
                if($cached !== null)
                {
                    return new EvidenceRecord($cached);
                }
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
        public static function getEvidenceRecords(int $limit=100, int $page=1, bool $includeConfidential=false, ?EvidenceCategory $category=null, ?string $by=null, ?OrderType $order=null): array
        {
            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new EvidenceRecord($data), $cached);
                }
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $conditions = [];

                if(!$includeConfidential)
                {
                    $conditions[] = "confidential=0";
                }

                $categoryCondition = $category?->toCondition() ?? '';
                if ($categoryCondition !== '')
                {
                    $conditions[] = $categoryCondition;
                }

                $query = "SELECT * FROM evidence";
                if (!empty($conditions))
                {
                    $query .= " WHERE " . implode(' AND ', $conditions);
                }

                $query .= " " . self::buildEvidenceSortClause($by, $order) . " LIMIT :limit OFFSET :offset";

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

            if ($cacheKey !== null && !empty($evidenceRecords))
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(EvidenceRecord $r) => $r->toArray(), $evidenceRecords));
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
            $cacheKey = null;
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if($cached !== null)
                {
                    return array_map(fn($data) => new EvidenceRecord($data), $cached);
                }
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

            if($cacheKey !== null)
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(EvidenceRecord $record) => $record->toArray(), $evidenceRecords));
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
         * @param EvidenceCategory|null $category Optional. Filter evidence by category
         * @param string|null $by Optional. Field to sort by
         * @param OrderType|null $order Optional. Sort direction
         * @return EvidenceRecord[] An array of EvidenceRecord objcts
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function getEvidenceByReport(string $report, int $limit=100, int $page=1, bool $includeConfidential=false, ?EvidenceCategory $category=null, ?string $by=null, ?OrderType $order=null): array
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

                $categoryCondition = $category?->toCondition() ?? '';
                if ($categoryCondition !== '')
                {
                    $query .= " AND ($categoryCondition)";
                }

                $query .= " " . self::buildEvidenceSortClause($by, $order) . " LIMIT :limit OFFSET :offset";

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
         * Updates the confidentiality status of an evidence record under a row lock.
         * Evidence linked to an ILLEGAL_CONTENT report cannot be made public.
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

            $pdo = DatabaseConnection::getConnection();
            $ownsTransaction = !$pdo->inTransaction();
            try
            {
                if($ownsTransaction)
                {
                    $pdo->beginTransaction();
                }

                $lock = $pdo->prepare("SELECT r.incident_type FROM evidence e LEFT JOIN reports r ON r.uuid = e.report WHERE e.uuid = :uuid FOR UPDATE");
                $lock->execute([':uuid' => $evidenceUuid]);
                if(!$confidential && $lock->fetchColumn() === 'ILLEGAL_CONTENT')
                {
                    throw new DomainException('Evidence linked to an ILLEGAL_CONTENT report must remain confidential');
                }

                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET confidential = :confidential WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->bindParam(':confidential', $confidential, PDO::PARAM_BOOL);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
                if($ownsTransaction)
                {
                    $pdo->commit();
                }
            }
            catch (Throwable $e)
            {
                if($ownsTransaction && $pdo->inTransaction())
                {
                    $pdo->rollBack();
                }
                if($e instanceof PDOException)
                {
                    throw new DatabaseOperationException("Failed to update confidentiality: " . $e->getMessage(), $e->getCode(), $e);
                }
                throw $e;
            }
            finally
            {
                if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid)))
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $evidenceUuid));
                }
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_UPDATED, $evidenceUuid);
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
                $changed = $stmt->rowCount() > 0;
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
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_UPDATED, $evidenceUuid);
            }
        }

        /**
         * Assigns a classification flag to an unclassified evidence record, or replaces the classification flag of a
         * classified one when overwriting.
         *
         * Without overwriting, the conditional update makes classification immutable even when concurrent
         * requests attempt to classify the same evidence.
         *
         * @param string $evidence The UUID of the evidence record to update
         * @param ClassificationFlag $classification The classification flag to set
         * @param bool $overwrite True to replace an existing classification flag that differs from the given one
         * @return bool True when the classification flag changed, false when it was already assigned (or already equal
         *              to the given one when overwriting) or the record does not exist
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function updateClassificationFlag(string $evidence, ClassificationFlag $classification, bool $overwrite=false): bool
        {
            $now = date('Y-m-d H:i:s');
            $classificationValue = $classification->value;
            $condition = $overwrite
                ? '(classification_flag IS NULL OR classification_flag<>:replaced_flag)'
                : 'classification_flag IS NULL';

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET classification_flag=:classification_flag, classified=:classified, updated=:updated WHERE uuid=:uuid AND $condition");
                $stmt->bindParam(':uuid', $evidence);
                $stmt->bindParam(':classification_flag', $classificationValue);
                $stmt->bindParam(':classified', $now);
                $stmt->bindParam(':updated', $now);
                if($overwrite)
                {
                    $stmt->bindParam(':replaced_flag', $classificationValue);
                }
                $stmt->execute();

                $updated = $stmt->rowCount() === 1;
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
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($updated)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_CLASSIFIED, $evidence);
            }

            return $updated;
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
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence e JOIN reports r ON r.uuid = :report SET e.report = r.uuid, e.confidential = CASE WHEN r.incident_type = 'ILLEGAL_CONTENT' THEN 1 ELSE e.confidential END, e.updated = :updated WHERE e.uuid = :uuid");
                $stmt->bindParam(':uuid', $evidenceUuid);
                $stmt->bindParam(':report', $reportUuid);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
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
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::EVIDENCE_UPDATED, $evidenceUuid);
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
         * Returns the classified evidence records with text content classified after the given position, in the
         * order they were classified, as raw data with the columns uuid, text_content, classification_flag and
         * classified. A plugin uses it to learn the classifications it missed.
         *
         * @param string|null $afterClassified The classification time (Y-m-d H:i:s) of the last record seen, null to
         *                                     start from the first classified record
         * @param string|null $afterUuid The UUID of the last record seen, breaking ties of the classification time
         * @param int $limit The maximum number of records to return
         * @return array[] The classified evidence records
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getClassifiedSince(?string $afterClassified, ?string $afterUuid, int $limit=1000): array
        {
            $condition = $afterClassified === null ? '' : 'AND (classified > :after OR (classified = :after_same AND uuid > :after_uuid))';
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT uuid, text_content, classification_flag, classified FROM evidence "
                    . "WHERE classification_flag IS NOT NULL AND classified IS NOT NULL AND LENGTH(text_content) > 0 $condition "
                    . "ORDER BY classified ASC, uuid ASC LIMIT :limit"
                );
                if($afterClassified !== null)
                {
                    $stmt->bindValue(':after', $afterClassified);
                    $stmt->bindValue(':after_same', $afterClassified);
                    $stmt->bindValue(':after_uuid', $afterUuid ?? '');
                }

                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve classified evidence: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Returns the most recently classified evidence record with the given text content, other than the given one,
         * as raw data with the columns uuid and classification_flag. A plugin forgetting the text of
         * deleted evidence uses it to keep what other evidence with the same text says about it.
         *
         * @param string $textContent The text content
         * @param string $excludeUuid The UUID of the evidence record to ignore
         * @return array|null The evidence record, null if no other classified evidence has the text
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getLatestClassifiedByText(string $textContent, string $excludeUuid): ?array
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT uuid, classification_flag FROM evidence WHERE text_content=:text_content AND uuid<>:uuid "
                    . "AND classification_flag IS NOT NULL ORDER BY COALESCE(classified, updated, created) DESC, uuid DESC LIMIT 1"
                );
                $stmt->bindParam(':text_content', $textContent);
                $stmt->bindParam(':uuid', $excludeUuid);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row === false ? null : $row;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by text: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Counts the evidence records that can be used to train a classifier, evidence records with a classification
         * and text content (confidential evidence records included)
         *
         * @return int The number of classified evidence records with text content
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countClassifiedEvidence(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->query("SELECT COUNT(*) FROM evidence WHERE classification_flag IS NOT NULL AND LENGTH(text_content) > 0");
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count classified evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves the oldest evidence records that are eligible for cleanup, as raw record data.
         *
         * @param int $before The Unix timestamp before which a record is old
         * @param int $limit The maximum number of records to return
         * @return array[] An array of raw evidence record data, oldest first
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
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM evidence WHERE created < :before ORDER BY created ASC, uuid ASC LIMIT :limit");
                $stmt->bindValue(':before', date('Y-m-d H:i:s', $before));
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old evidence records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Deletes the given evidence records that were created before the given time, the database deletes their file
         * attachments whose stored files are deleted as well. No record changes are dispatched for the deleted records.
         *
         * @param string[] $uuids The UUIDs of the evidence records to delete
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
            $pdo = DatabaseConnection::getConnection();
            $evidenceUuids = [];

            try
            {
                $pdo->beginTransaction();
                $selectStmt = $pdo->prepare("SELECT uuid FROM evidence WHERE uuid IN ($placeholders) AND created < :before FOR UPDATE");
                $selectStmt->execute($parameters);
                $evidenceUuids = $selectStmt->fetchAll(PDO::FETCH_COLUMN);
                if(empty($evidenceUuids))
                {
                    $pdo->commit();
                    return 0;
                }

                [$evidencePlaceholders, $evidenceParameters] = Utilities::getInPlaceholders($evidenceUuids);
                $attachmentStmt = $pdo->prepare("SELECT uuid FROM file_attachments WHERE evidence IN ($evidencePlaceholders)");
                $attachmentStmt->execute($evidenceParameters);
                $attachmentUuids = $attachmentStmt->fetchAll(PDO::FETCH_COLUMN);

                $deleteStmt = $pdo->prepare("DELETE FROM evidence WHERE uuid IN ($evidencePlaceholders)");
                $deleteStmt->execute($evidenceParameters);
                $deletedCount = $deleteStmt->rowCount();
                $pdo->commit();
            }
            catch (PDOException $e)
            {
                if($pdo->inTransaction())
                {
                    $pdo->rollBack();
                }

                throw new DatabaseOperationException("Failed to purge evidence records: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled() && !empty($evidenceUuids))
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                    RedisConnection::clearRecords(FileAttachmentManager::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(FileAttachmentManager::CACHE_PREFIX);
                    RedisConnection::clearRecords(AuditLogManager::CACHE_PREFIX);
                }
            }

            FileAttachmentManager::deleteFiles($attachmentUuids);
            return $deletedCount;
        }

        /**
         * Searches evidence by a LIKE pattern across uuid, text_content, tag, and entity columns, and with the extended
         * search also the note, report, and operator columns.
         *
         * @param string $likePattern The SQL LIKE pattern to search with.
         * @param int $limit The maximum number of results to return.
         * @param int $page The page number for pagination.
         * @param bool $includeConfidential if True, confidential records are included in the search results
         * @param bool $extended True to also match the columns of the extended search, available to operators
         * @return EvidenceRecord[] An array of matching EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function searchEvidence(string $likePattern, int $limit, int $page, bool $includeConfidential=false, ?EvidenceCategory $category=null, ?string $by=null, ?OrderType $order=null, bool $extended=false): array
        {
            $offset = ($page - 1) * $limit;

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new EvidenceRecord($data), $cached);
                }
            }

            try
            {
                $columns = ['uuid', 'text_content', 'tag', 'entity'];
                if ($extended)
                {
                    array_push($columns, 'note', 'report', 'operator');
                }

                $sql = "SELECT * FROM evidence WHERE " . Utilities::buildLikeCondition($columns);

                if (!$includeConfidential)
                {
                    $sql .= " AND confidential = 0";
                }

                $categoryCondition = $category?->toCondition() ?? '';
                if ($categoryCondition !== '')
                {
                    $sql .= " AND ($categoryCondition)";
                }

                $sortClause = self::buildEvidenceSortClause($by, $order);
                $sql .= " $sortClause LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindValue(':q', $likePattern);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $evidenceRecords = array_map(fn($row) => new EvidenceRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to search evidence: ' . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($evidenceRecords))
            {
                RedisConnection::cacheSearchResults(
                    $cacheKey,
                    array_map(fn(EvidenceRecord $r) => $r->toArray(), $evidenceRecords)
                );
            }

            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
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
         * Checks if caching is enabled based on the configuration.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isEvidenceCacheEnabled();
        }

        /**
         * Builds the evidence sort clause
         *
         * @param string|null $by The column to sort by
         * @param OrderType|null $order The order to sort by
         * @return string Returns the SQL sort clause
         */
        private static function buildEvidenceSortClause(?string $by, ?OrderType $order): string
        {
            $column = 'created';
            $direction = 'DESC';

            if ($by !== null)
            {
                $filterType = EvidenceOrderType::tryFromCaseInsensitive($by);
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
    }
