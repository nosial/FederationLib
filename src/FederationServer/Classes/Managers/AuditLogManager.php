<?php

    namespace FederationServer\Classes\Managers;

    use DateTime;
    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Classes\Logger;
    use FederationServer\Enums\AuditLogType;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\AuditLog;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class AuditLogManager
    {
        /**
         * Creates a new audit log entry.
         *
         * @param AuditLogType $type The type of the audit log entry.
         * @param string $message The message to log.
         * @param string|null $operator The UUID of the operator performing the action, or null if not applicable.
         * @param string|null $entity The UUID of the entity being acted upon, or null if not applicable.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function createEntry(AuditLogType $type, string $message, ?string $operator=null, ?string $entity=null): void
        {
            if(strlen($message) === 0)
            {
                throw new InvalidArgumentException("Message cannot be empty.");
            }

            if($operator !== null && strlen($operator) === 0)
            {
                throw new InvalidArgumentException("Operator UUID cannot be empty.");
            }

            if($entity !== null && strlen($entity) === 0)
            {
                throw new InvalidArgumentException("Entity UUID cannot be empty.");
            }

            if($operator !== null && $entity !== null)
            {
                Logger::log()->info(sprintf("Audit Entry [%s] %s by %s on %s", $type->value, $message, $operator, $entity));
            }
            elseif($operator !== null)
            {
                Logger::log()->info(sprintf("Audit Entry [%s] %s by %s", $type->value, $message, $operator));
            }
            elseif($entity !== null)
            {
                Logger::log()->info(sprintf("Audit Entry [%s] %s on %s", $type->value, $message, $entity));
            }
            else
            {
                Logger::log()->info(sprintf("Audit Entry [%s] %s", $type->value, $message));
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO audit_log (type, message, operator, entity) VALUES (:type, :message, :operator, :entity)");

                $type = $type->value;
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':message', $message);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':entity', $entity);

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
         * @param string $uuid The UUID of the audit log entry to retrieve.
         * @return AuditLog|null An AuditLogRecord object representing the entry, or null if not found.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntry(string $uuid): ?AuditLog
        {
            if(strlen($uuid) === 0)
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM audit_log WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result === false)
                {
                    return null; // No entry found
                }

                return new AuditLog($result);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entry: " . $e->getMessage(), 0, $e);
            }
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

                return $entries;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves a specific audit log entry by its UUID.
         *
         * @param string $operator The UUID of the operator to filter by.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param AuditLogType[]|null $filterType Optional array of AuditLogType to filter by.
         * @return AuditLog[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByOperator(string $operator, int $limit=100, int $page=1, ?array $filterType=null): array
        {
            if(strlen($operator) === 0)
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
                $params = [':operator' => $operator];

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

                return $entries;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by operator: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves audit log entries by entity.
         *
         * @param string $entity The UUID of the entity to filter by.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @param AuditLogType[]|null $filterType Optional array of AuditLogType to filter by.
         * @return AuditLog[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByEntity(string $entity, int $limit=100, int $page=1, ?array $filterType=null): array
        {
            if(strlen($entity) === 0)
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
                $params = [':entity' => $entity];

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

                return $entries;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by entity: " . $e->getMessage(), 0, $e);
            }
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
    }