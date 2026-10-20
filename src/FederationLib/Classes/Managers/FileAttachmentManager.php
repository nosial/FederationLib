<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\Categories\AttachmentCategory;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\AttachmentOrderType;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\FileAttachmentRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class FileAttachmentManager
    {
        public const string CACHE_PREFIX = 'file_attachment:';

        /**
         * Creates a new file attachment record.
         *
         * @param string $uuid The UUID of the file attachment.
         * @param string $evidence The UUID of the evidence associated with the file attachment.
         * @param string $fileMime The MIME type of the file.
         * @param string $fileName The name of the file being attached.
         * @param int $fileSize The size of the file in bytes.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function createRecord(string $uuid, string $evidence, string $fileMime, string $fileName, int $fileSize): void
        {
            if(strlen($fileName) > 255)
            {
                throw new InvalidArgumentException('File name exceeds maximum length of 255 characters.');
            }

            if(!is_numeric($fileSize) || $fileSize <= 0)
            {
                throw new InvalidArgumentException('File size must be a positive integer.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO file_attachments (uuid, evidence, file_mime, file_name, file_size) VALUES (:uuid, :evidence, :file_mime, :file_name, :file_size)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':evidence', $evidence);
                $stmt->bindParam(':file_mime', $fileMime);
                $stmt->bindParam(':file_name', $fileName);
                $stmt->bindParam(':file_size', $fileSize, PDO::PARAM_INT);

                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to create file attachment record: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::ATTACHMENT_CREATED, $uuid);
        }

        /**
         * Retrieves a file attachment record by its UUID.
         *
         * @param string $uuid The UUID of the file attachment record.
         * @return FileAttachmentRecord|null The FileAttachmentRecord object if found, null otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getRecord(string $uuid): ?FileAttachmentRecord
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('File attachment UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM file_attachments WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if($result === false)
                {
                    return null; // No record found
                }

                $result = new FileAttachmentRecord($result);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment record: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $result, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $uuid),
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl()
                );
            }

            return $result;
        }

        /**
         * Retrieves all file attachment records associated with a specific evidence UUID.
         *
         * @param string $evidenceUuid The UUID of the evidence record.
         * @return FileAttachmentRecord[] An array of FileAttachmentRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getRecordsByEvidence(string $evidenceUuid): array
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM file_attachments WHERE evidence=:evidence");
                $stmt->bindParam(':evidence', $evidenceUuid);
                $stmt->execute();

                $results = array_map(fn($data) => new FileAttachmentRecord($data), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $results, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl()
                );
            }

            return $results;
        }

        /**
         * Deletes a file attachment record by its UUID.
         *
         * @param string $uuid The UUID of the file attachment record to delete.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws CacheOperationException If there was an error during the cache operation.
         */
        public static function deleteRecord(string $uuid): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('File attachment UUID cannot be empty.');
            }

            if(!Validate::uuid($uuid))
            {
                throw new InvalidArgumentException('File attachment UUID must be valid');
            }

            // Retrieve the attachment first before deleting it.
            $existingAttachment = self::getRecord($uuid);
            if($existingAttachment === null)
            {
                throw new DatabaseOperationException("File attachment record not found");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM file_attachments WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete file attachment record: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                // Check if the file is writable and or it exists
                if(file_exists($existingAttachment->getFilePath()) && is_writeable($existingAttachment->getFilePath()))
                {
                    if(!@unlink($existingAttachment->getFilePath()))
                    {
                        Logger::log()->error(sprintf("Failed to delete file attachment %s from %s due to an IO error", $existingAttachment->getUuid(), $existingAttachment->getFilePath()));
                    }
                }
                else
                {
                    Logger::log()->warning(sprintf("Unable to delete file attachment %s from %s, because the file cannot be deleted or does not exist.", $existingAttachment->getUuid(), $existingAttachment->getFilePath()));
                }
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::ATTACHMENT_DELETED, $uuid);
            }
        }

        /**
         * Retrieves all file attachment records from the database with pagination support.
         *
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @return FileAttachmentRecord[] An array of FileAttachmentRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws InvalidArgumentException If the limit or page parameters are invalid.
         */
        public static function getAttachmentRecords(int $limit=100, int $page=1, ?AttachmentCategory $category=null, ?string $by=null, ?OrderType $order=null): array
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
                    return array_map(fn($data) => new FileAttachmentRecord($data), $cached);
                }
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $categoryCondition = $category?->toCondition() ?? '';
                $sortClause = self::buildAttachmentSortClause($by, $order);
                $sql = "SELECT * FROM file_attachments";
                if ($categoryCondition !== '')
                {
                    $sql .= " WHERE $categoryCondition";
                }
                $sql .= " $sortClause LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = array_map(fn($data) => new FileAttachmentRecord($data), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($results))
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(FileAttachmentRecord $r) => $r->toArray(), $results));
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $results, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl()
                );
            }

            return $results;
        }

        /**
         * Counts the total number of file attachment records in the database.
         *
         * @return int The total number of file attachment records.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->query("SELECT COUNT(*) FROM file_attachments");
                return (int) $stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves the oldest file attachment records that are eligible for cleanup, as raw record data.
         *
         * @param int $before The Unix timestamp before which a record is old
         * @param int $limit The maximum number of records to return
         * @return array[] An array of raw file attachment record data, oldest first
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
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM file_attachments WHERE created < :before ORDER BY created ASC, uuid ASC LIMIT :limit");
                $stmt->bindValue(':before', date('Y-m-d H:i:s', $before));
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old file attachment records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves the file attachment records of the given evidence records, as raw record data.
         *
         * @param string[] $evidenceUuids The UUIDs of the evidence records
         * @return array[] An array of raw file attachment record data
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getRawRecordsByEvidence(array $evidenceUuids): array
        {
            if(empty($evidenceUuids))
            {
                return [];
            }

            [$placeholders, $parameters] = Utilities::getInPlaceholders($evidenceUuids);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM file_attachments WHERE evidence IN ($placeholders) ORDER BY created ASC, uuid ASC");
                $stmt->execute($parameters);

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Deletes the given file attachment records that were created before the given time along with their stored
         * files. No record changes are dispatched for the deleted records.
         *
         * @param string[] $uuids The UUIDs of the file attachment records to delete
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
            $deletedUuids = [];

            try
            {
                // The creation time of an attachment never changes, every selected record is deleted below
                $selectStmt = DatabaseConnection::getConnection()->prepare("SELECT uuid FROM file_attachments WHERE uuid IN ($placeholders) AND created < :before");
                $selectStmt->execute($parameters);
                $deletedUuids = $selectStmt->fetchAll(PDO::FETCH_COLUMN);
                if(empty($deletedUuids))
                {
                    return 0;
                }

                [$deletePlaceholders, $deleteParameters] = Utilities::getInPlaceholders($deletedUuids);
                $deleteStmt = DatabaseConnection::getConnection()->prepare("DELETE FROM file_attachments WHERE uuid IN ($deletePlaceholders)");
                $deleteStmt->execute($deleteParameters);
                $deletedCount = $deleteStmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to purge file attachment records: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled() && !empty($deletedUuids))
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                    RedisConnection::clearRecords(AuditLogManager::CACHE_PREFIX);
                }
            }

            self::deleteFiles($deletedUuids);
            return $deletedCount;
        }

        /**
         * Retrieves the UUIDs of the file attachments of the evidence records whose given column has the given value,
         * which the database deletes along with that evidence.
         *
         * @param string $column The evidence column to match, one of uuid, entity or operator
         * @param string $value The value of the column
         * @return string[] The UUIDs of the file attachments
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getUuidsByEvidenceColumn(string $column, string $value): array
        {
            if(!in_array($column, ['uuid', 'entity', 'operator'], true))
            {
                throw new InvalidArgumentException(sprintf("'%s' is not a supported evidence column", $column));
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT f.uuid FROM file_attachments f INNER JOIN evidence e ON e.uuid = f.evidence WHERE e.$column = :value");
                $stmt->bindParam(':value', $value);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Forgets the file attachments the database deleted along with their evidence, deleting their cached records
         * and their stored files, which the database can't delete (OFD "Deletion of Records"). No record changes are
         * dispatched for the deleted records.
         *
         * @param string[] $uuids The UUIDs of the deleted file attachments
         */
        public static function forgetDeletedRecords(array $uuids): void
        {
            if(empty($uuids))
            {
                return;
            }

            if(self::isCachingEnabled())
            {
                foreach($uuids as $uuid)
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }

                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            self::deleteFiles($uuids);
        }

        /**
         * Deletes the stored files of the given file attachments, a file that can't be deleted is logged.
         *
         * @param string[] $uuids The UUIDs of the file attachments whose files to delete
         */
        public static function deleteFiles(array $uuids): void
        {
            $storagePath = rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR);
            foreach($uuids as $uuid)
            {
                $filePath = $storagePath . DIRECTORY_SEPARATOR . $uuid;
                if(file_exists($filePath) && !@unlink($filePath))
                {
                    Logger::log()->error(sprintf('Failed to delete the file of file attachment %s from %s', $uuid, $filePath));
                }
            }
        }

        /**
         * Searches file attachments by a LIKE pattern across uuid, file_name, and evidence columns, and with the extended
         * search also the file_mime column.
         *
         * @param string $likePattern The SQL LIKE pattern to search with.
         * @param int $limit The maximum number of results to return.
         * @param int $page The page number for pagination.
         * @param bool $includeConfidential if True, confidential records are included in the search results
         * @param bool $extended True to also match the columns of the extended search, available to operators
         * @return FileAttachmentRecord[] An array of matching FileAttachmentRecord objects.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function searchAttachments(string $likePattern, int $limit, int $page, bool $includeConfidential=false, ?AttachmentCategory $category=null, ?string $by=null, ?OrderType $order=null, bool $extended=false): array
        {
            $offset = ($page - 1) * $limit;

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new FileAttachmentRecord($data), $cached);
                }
            }

            try
            {
                $sql = "SELECT fa.* FROM file_attachments fa";

                if (!$includeConfidential)
                {
                    $sql .= " LEFT JOIN evidence e ON fa.evidence = e.uuid";
                }

                $columns = ['fa.uuid', 'fa.file_name', 'fa.evidence'];
                if ($extended)
                {
                    $columns[] = 'fa.file_mime';
                }

                $sql .= " WHERE " . Utilities::buildLikeCondition($columns);

                if (!$includeConfidential)
                {
                    $sql .= " AND (e.confidential IS NULL OR e.confidential = 0)";
                }

                $categoryCondition = $category?->toCondition() ?? '';
                if ($categoryCondition !== '')
                {
                    $sql .= " AND ($categoryCondition)";
                }

                $sortClause = self::buildAttachmentSortClause($by, $order);
                $sql .= " $sortClause LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindValue(':q', $likePattern);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $attachments = array_map(fn($row) => new FileAttachmentRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to search attachments: ' . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($attachments))
            {
                RedisConnection::cacheSearchResults(
                    $cacheKey,
                    array_map(fn(FileAttachmentRecord $r) => $r->toArray(), $attachments)
                );
            }

            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $attachments, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl()
                );
            }

            return $attachments;
        }

        /**
         * Returns True if caching & file attachment caching is enabled for this class
         *
         * @return bool True if caching & caching for file attachments is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isFileAttachmentCacheEnabled();
        }

        /**
         * Builds the attachment sort clause
         *
         * @param string|null $by The column to sort by
         * @param OrderType|null $order The order to sort by
         * @return string Returns the SQL sort clause
         */
        private static function buildAttachmentSortClause(?string $by, ?OrderType $order): string
        {
            $column = 'created';
            $direction = 'DESC';

            if ($by !== null)
            {
                $filterType = AttachmentOrderType::tryFromCaseInsensitive($by);
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

