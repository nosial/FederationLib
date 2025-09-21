<?php

    namespace FederationLib\Classes\Managers;

    use DateTime;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\AuditLog;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class AuditLogManager
    {
        public const string CACHE_PREFIX = "audit_log:";

        /**
         * Creates a new audit log entry.
         *
         * @param AuditLogType $type The type of the audit log entry.
         * @param string $message The message to log.
         * @param string|null $operatorUuid The UUID of the operator performing the action, or null if not applicable.
         * @param string|null $entityUuid The UUID of the entity being acted upon, or null if not applicable.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function createEntry(AuditLogType $type, string $message, ?string $operatorUuid=null, ?string $entityUuid=null): void
        {
            if(strlen($message) === 0)
            {
                throw new InvalidArgumentException("Message cannot be empty.");
            }

            if($operatorUuid !== null && strlen($operatorUuid) === 0)
            {
                throw new InvalidArgumentException("Operator UUID cannot be empty.");
            }

            if($entityUuid !== null && strlen($entityUuid) === 0)
            {
                throw new InvalidArgumentException("Entity UUID cannot be empty.");
            }

            if($operatorUuid !== null && $entityUuid !== null)
            {
                Logger::log()->info(sprintf("[%s] %s by %s on %s", $type->value, $message, $operatorUuid, $entityUuid));
            }
            elseif($operatorUuid !== null)
            {
                Logger::log()->info(sprintf("[%s] %s by %s", $type->value, $message, $operatorUuid));
            }
            elseif($entityUuid !== null)
            {
                Logger::log()->info(sprintf("[%s] %s on %s", $type->value, $message, $entityUuid));
            }
            else
            {
                Logger::log()->info(sprintf("[%s] %s", $type->value, $message));
            }

            $type = $type->value;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO audit_log (type, message, operator, entity) VALUES (:type, :message, :operator, :entity)");
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':message', $message);
                $stmt->bindParam(':operator', $operatorUuid);
                $stmt->bindParam(':entity', $entityUuid);

                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to prepare SQL statement for audit log entry: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves a specific audit log entry by its UUID.
         *
         * @param string $auditLogUuid The UUID of the audit log entry to retrieve.
         * @return AuditLog|null An AuditLogRecord object representing the entry, or null if not found.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntry(string $auditLogUuid): ?AuditLog
        {
            if(strlen($auditLogUuid) === 0)
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            if(self::isCachingEnabled())
            {
                $cached = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $auditLogUuid));
                if($cached !== null)
                {
                    return new AuditLog($cached);
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM audit_log WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $auditLogUuid);
                $stmt->execute();

                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result === false)
                {
                    return null; // No entry found
                }

                $result = new AuditLog($result);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entry: " . $e->getMessage(), 0, $e);
            }

            // If caching is enabled and limit not reached
            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getAuditLogCacheLimit() ?? 0))
            {
                RedisConnection::setRecord(
                    record: $result, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $result->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getAuditLogCacheTtl() ?? 0
                );
            }

            return $result;
        }

        /**
         * Retrieves audit log entries with optional pagination and filtering.
         *
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param AuditLogType[]|null $filterType Optional array of AuditLogType to filter by.
         * @return AuditLog[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntries(int $limit=100, int $page=1, ?array $filterType=null): array
        {
            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $sql = "SELECT * FROM audit_log";
                $params = [];

                if ($filterType !== null && count($filterType) > 0)
                {
                    foreach ($filterType as $i => $t)
                    {
                        if (!$t instanceof AuditLogType)
                        {
                            throw new InvalidArgumentException("All filterType elements must be of type AuditLogType.");
                        }
                        $params[":type$i"] = $t->value;
                    }
                    $placeholders = implode(", ", array_keys($params));
                    $sql .= " WHERE type IN ($placeholders)";
                }

                $sql .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);

                foreach ($params as $key => $value)
                {
                    $stmt->bindValue($key, $value);
                }

                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];
                foreach ($results as $row)
                {
                    $entries[] = new AuditLog($row);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $entries, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getAuditLogCacheLimit() ?? 0,
                    ttl: Configuration::getRedisConfiguration()->getAuditLogCacheTtl() ?? 0
                );
            }

            return $entries;
        }

        /**
         * Retrieves a specific audit log entry by its UUID.
         *
         * @param string $operatorUuid The UUID of the operator to filter by.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param AuditLogType[]|null $filterType Optional array of AuditLogType to filter by.
         * @return AuditLog[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByOperator(string $operatorUuid, int $limit=100, int $page=1, ?array $filterType=null): array
        {
            if(strlen($operatorUuid) === 0)
            {
                throw new InvalidArgumentException("Operator UUID cannot be empty.");
            }

            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $sql = "SELECT * FROM audit_log WHERE operator = :operator";
                $params = [':operator' => $operatorUuid];

                if ($filterType !== null && count($filterType) > 0)
                {
                    foreach ($filterType as $i => $t)
                    {
                        if (!$t instanceof AuditLogType)
                        {
                            throw new InvalidArgumentException("All filterType elements must be of type AuditLogType.");
                        }

                        $params[":type$i"] = $t->value;
                    }

                    $placeholders = implode(", ", array_keys(array_slice($params, 1)));
                    $sql .= " AND type IN ($placeholders)";
                }

                $sql .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($sql);

                foreach ($params as $key => $value)
                {
                    $stmt->bindValue($key, $value);
                }

                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];

                foreach ($results as $row)
                {
                    $entries[] = new AuditLog($row);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by operator: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $entries, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getAuditLogCacheLimit() ?? 0,
                    ttl: Configuration::getRedisConfiguration()->getAuditLogCacheTtl() ?? 0
                );
            }

            return $entries;
        }

        /**
         * Retrieves audit log entries by entity.
         *
         * @param string $entityUuid The UUID of the entity to filter by.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param AuditLogType[]|null $filterType Optional array of AuditLogType to filter by.
         * @return AuditLog[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByEntity(string $entityUuid, int $limit=100, int $page=1, ?array $filterType=null): array
        {
            if(strlen($entityUuid) === 0)
            {
                throw new InvalidArgumentException("Entity UUID cannot be empty.");
            }

            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;
            try
            {
                $sql = "SELECT * FROM audit_log WHERE entity = :entity";
                $params = [':entity' => $entityUuid];

                if ($filterType !== null && count($filterType) > 0)
                {
                    foreach ($filterType as $i => $t)
                    {
                        if (!$t instanceof AuditLogType)
                        {
                            throw new InvalidArgumentException("All filterType elements must be of type AuditLogType.");
                        }

                        $params[":type$i"] = $t->value;
                    }
                    $placeholders = implode(", ", array_keys(array_slice($params, 1)));
                    $sql .= " AND type IN ($placeholders)";
                }

                $sql .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                foreach ($params as $key => $value)
                {
                    $stmt->bindValue($key, $value);
                }

                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];
                foreach ($results as $row)
                {
                    $entries[] = new AuditLog($row);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by entity: " . $e->getMessage(), 0, $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $entries, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getAuditLogCacheLimit() ?? 0,
                    ttl: Configuration::getRedisConfiguration()->getAuditLogCacheTtl() ?? 0
                );
            }

            return $entries;
        }

        /**
         * Deletes all audit log entries.
         *
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @return int The number of rows deleted.
         */
        public static function cleanEntries(int $olderThanDays): int
        {
            if($olderThanDays <= 0)
            {
                throw new InvalidArgumentException("Days must be greater than zero.");
            }

            $timestamp = time() - ($olderThanDays * 86400); // Convert days to seconds
            $timestamp = DateTime::createFromFormat('U', $timestamp)->getTimestamp();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM audit_log WHERE timestamp < :timestamp");
                $stmt->bindParam(':timestamp', $timestamp);
                $stmt->execute();
                return $stmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean audit log entries: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if (self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                }
            }
        }

        /**
         * Counts the number of audit log records.
         *
         * @param AuditLogType|null $type Optional type to filter the count by.
         * @return int The number of audit log records.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countRecords(?AuditLogType $type=null): int
        {
            try
            {
                $sql = "SELECT COUNT(*) FROM audit_log";
                $params = [];

                if ($type !== null)
                {
                    if (!$type instanceof AuditLogType)
                    {
                        throw new InvalidArgumentException("Type must be of type AuditLogType.");
                    }
                    $params[':type'] = $type->value;
                    $sql .= " WHERE type = :type";
                }

                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                foreach ($params as $key => $value)
                {
                    $stmt->bindValue($key, $value);
                }
                $stmt->execute();

                return (int) $stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count audit log records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Checks if caching is enabled based on the configuration.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isAuditLogCacheEnabled();
        }
    }