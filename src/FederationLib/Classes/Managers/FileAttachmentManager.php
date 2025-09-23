<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\FileAttachmentRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use RedisException;

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
            catch (RedisException $e)
            {
                throw new CacheOperationException("Cache operation failed: " . $e->getMessage(), $e->getCode(), $e);
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
         * Returns True if caching & file attachment caching is enabled for this class
         *
         * @return bool True if caching & caching for file attachments is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isFileAttachmentCacheEnabled();
        }
    }

