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
        private const string FILE_ATTACHMENT_CACHE_PREFIX = 'file_attachment_';

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

            // If caching and pre-caching is enabled, retrieve the existing file attachment record and cache it
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                // If the limit has not been exceeded, cache the file attachment record
                if (!RedisConnection::limitExceeded(self::FILE_ATTACHMENT_CACHE_PREFIX, Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit()))
                {
                    RedisConnection::setCacheRecord(self::getRecord($uuid), self::getCacheKey($uuid), Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl());
                }
            }

            // TODO: If caching is enabled, clear the current storage space cache size so it could be calculated again
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

            if(self::isCachingEnabled() && RedisConnection::cacheRecordExists(self::getCacheKey($uuid)))
            {
                // If caching is enabled and the file attachment exists in the cache, return it
                return new FileAttachmentRecord(RedisConnection::getRecordFromCache(self::getCacheKey($uuid)));
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

                $fileAttachmentRecord = new FileAttachmentRecord($result);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment record: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                try
                {
                    if(RedisConnection::getConnection()->exists(self::getCacheKey($uuid)))
                    {
                        return $fileAttachmentRecord; // Return the cached record if it exists
                    }

                    Logger::log()->debug(sprintf("Caching file attachment with UUID '%s'", $uuid));
                    RedisConnection::setCacheRecord($fileAttachmentRecord, self::getCacheKey($uuid), Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl());
                }
                // Database operations can fail, but we don't want to throw cache exceptions if it could be ignored
                catch (RedisException $e)
                {
                    Logger::log()->error(sprintf("Failed to cache file attachment with UUID '%s': %s", $uuid, $e->getMessage()));
                    if(Configuration::getRedisConfiguration()->shouldThrowOnErrors())
                    {
                        throw new CacheOperationException(sprintf("Failed to cache file attachment with UUID '%s'", $uuid), 0, $e);
                    }
                }
            }

            return $fileAttachmentRecord;
        }

        /**
         * Retrieves all file attachment records associated with a specific evidence UUID.
         *
         * @param string $evidence The UUID of the evidence record.
         * @return FileAttachmentRecord[] An array of FileAttachmentRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getRecordsByEvidence(string $evidence): array
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM file_attachments WHERE evidence = :evidence");
                $stmt->bindParam(':evidence', $evidence);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $fileAttachments = array_map(fn($data) => new FileAttachmentRecord($data), $results);

                // If caching is enabled and pre-caching is enabled, cache each file attachment
                if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
                {
                    $currentCount = RedisConnection::countKeys(self::FILE_ATTACHMENT_CACHE_PREFIX);
                    foreach($fileAttachments as $fileAttachment)
                    {
                        // If the cache limit has not been exceeded, cache the file attachment record
                        if($currentCount < Configuration::getRedisConfiguration()->getFileAttachmentCacheLimit())
                        {
                            $cacheKey = self::getCacheKey($fileAttachment->getUuid());
                            if(!RedisConnection::cacheRecordExists($cacheKey))
                            {
                                Logger::log()->debug(sprintf("Caching file attachment with UUID '%s'", $fileAttachment->getUuid()));
                                RedisConnection::setCacheRecord($fileAttachment, $cacheKey, Configuration::getRedisConfiguration()->getFileAttachmentCacheTtl());
                                $currentCount++;
                            }
                        }
                        else
                        {
                            break; // Stop caching if the limit has been reached
                        }
                    }
                }

                return $fileAttachments;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }
            catch (RedisException $e)
            {
                throw new CacheOperationException("Cache operation failed: " . $e->getMessage(), $e->getCode(), $e);
            }
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

            if(self::isCachingEnabled() && RedisConnection::cacheRecordExists(self::getCacheKey($uuid)))
            {
                Logger::log()->debug(sprintf("Deleting cache for file attachment with UUID '%s'", $uuid));
                $cacheKey = self::getCacheKey($uuid);

                try
                {
                    RedisConnection::getConnection()->del($cacheKey);
                }
                catch (RedisException $e)
                {
                    throw new CacheOperationException(sprintf("Failed to delete cache for file attachment with UUID '%s'", $uuid), 0, $e);
                }
            }
        }

        public static function getUsedSpace(): int
        {
            // TODO: Implement a cachable (if configured) way to calculate the total size usage of the storage path (where all the files are uploaded to)
            $storagePath = Configuration::getServerConfiguration()->getStoragePath();
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

        // Caching operations

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
         * Returns the cache key based off the given file attachment UUID
         *
         * @param string $uuid The File Attachment UUID
         * @return string The returned cache key
         */
        private static function getCacheKey(string $uuid): string
        {
            return sprintf("%s%s", self::FILE_ATTACHMENT_CACHE_PREFIX, $uuid);
        }
    }

