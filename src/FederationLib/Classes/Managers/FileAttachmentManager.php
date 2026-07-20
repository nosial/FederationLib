<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\Categories\AttachmentCategory;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\AttachmentOrderType;
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
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTTL()
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
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTTL()
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

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $results, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getFileAttachmentCacheTTL()
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
         * Retrieves file attachment records older than the specified TTL.
         *
         * @param int $ttl The TTL in seconds to look back
         * @param int $limit The maximum number of records to return
         * @param int $page The page number for pagination
         * @return array[] An array of raw file attachment record data
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getOldRecords(int $ttl, int $limit=1000, int $page=1): array
        {
            if($ttl <= 0)
            {
                throw new InvalidArgumentException('TTL must be greater than zero.');
            }

            if($limit < 1 || $limit > 10000)
            {
                throw new InvalidArgumentException('Limit must be between 1 and 10000.');
            }

            if($page < 1)
            {
                throw new InvalidArgumentException('Page must be greater than zero.');
            }

            $timestamp = date('Y-m-d H:i:s', time() - $ttl);
            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT * FROM file_attachments WHERE created < :timestamp ORDER BY created ASC LIMIT :limit OFFSET :offset"
                );
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes file attachment records older than the specified TTL.
         * Physical files on disk are also removed.
         *
         * @param int $ttl The TTL in seconds after which file attachment records are considered old
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
            $deletedCount = 0;

            try
            {
                // First, fetch records to delete physical files
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT uuid FROM file_attachments WHERE created < :timestamp"
                );
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->execute();

                $uuids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // Delete physical files
                $storagePath = Configuration::getServerConfiguration()->getStoragePath();
                foreach ($uuids as $uuid)
                {
                    $filePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uuid;
                    if(file_exists($filePath) && is_writable($filePath))
                    {
                        @unlink($filePath);
                    }
                }

                // Delete database records
                $deleteStmt = DatabaseConnection::getConnection()->prepare("DELETE FROM file_attachments WHERE created < :timestamp");
                $deleteStmt->bindParam(':timestamp', $timestamp);
                $deleteStmt->execute();
                $deletedCount = $deleteStmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                }
            }

            return $deletedCount;
        }

        /**
         * Searches file attachments by a LIKE pattern across uuid, file_name, and evidence columns.
         *
         * @param string $likePattern The SQL LIKE pattern to search with.
         * @param int $limit The maximum number of results to return.
         * @param int $page The page number for pagination.
         * @param bool $includeConfidential if True, confidential records are included in the search results
         * @return FileAttachmentRecord[] An array of matching FileAttachmentRecord objects.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function searchAttachments(string $likePattern, int $limit, int $page, bool $includeConfidential=false, ?AttachmentCategory $category=null, ?string $by=null, ?OrderType $order=null): array
        {
            $offset = ($page - 1) * $limit;

            try
            {
                $sql = "SELECT fa.* FROM file_attachments fa";

                if (!$includeConfidential)
                {
                    $sql .= " LEFT JOIN evidence e ON fa.evidence = e.uuid";
                }

                $sql .= " WHERE (fa.uuid LIKE :q ESCAPE '\\\\' OR fa.file_name LIKE :q ESCAPE '\\\\' OR fa.evidence LIKE :q ESCAPE '\\\\')";

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

                return array_map(fn($row) => new FileAttachmentRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to search attachments: ' . $e->getMessage(), $e->getCode(), $e);
            }
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

