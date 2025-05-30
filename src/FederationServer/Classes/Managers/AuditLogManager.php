<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\AuditLogRecord;
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
         * Retrieves all audit log entries.
         *
         * @return AuditLogRecord[] An array of associative arrays representing the audit log entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntries(int $limit=100, int $page=1): array
        {
            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM audit_log ORDER BY timestamp DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];
                foreach ($results as $row)
                {
                    $entries[] = new AuditLogRecord($row);
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
         * @return AuditLogRecord[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByOperator(string $operator, int $limit=100, int $page=1): array
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
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM audit_log WHERE operator = :operator ORDER BY timestamp DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];
                foreach ($results as $row)
                {
                    $entries[] = new AuditLogRecord($row);
                }

                return $entries;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by operator: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves the total count of audit log entries.
         *
         * @return int The total number of audit log entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByEntity(string $entity, int $limit=100, int $page=1): array
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
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM audit_log WHERE entity = :entity ORDER BY timestamp DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];
                foreach ($results as $row)
                {
                    $entries[] = new AuditLogRecord($row);
                }

                return $entries;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by entity: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves audit log entries by type.
         *
         * @param AuditLogType $type The type of audit log entries to retrieve.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @return AuditLogRecord[] An array of AuditLogRecord objects representing the entries.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByType(AuditLogType $type, int $limit=100, int $page=1): array
        {
            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM audit_log WHERE type = :type ORDER BY timestamp DESC LIMIT :limit OFFSET :offset");
                $type = $type->value;
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entries = [];
                foreach ($results as $row)
                {
                    $entries[] = new AuditLogRecord($row);
                }

                return $entries;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve audit log entries by type: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Deletes all audit log entries.
         *
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function cleanEntries(int $olderThanDays): void
        {
            if($olderThanDays <= 0)
            {
                throw new InvalidArgumentException("Days must be greater than zero.");
            }

            $timestamp = time() - ($olderThanDays * 86400); // Convert days to seconds

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM audit_log WHERE timestamp < :timestamp");
                $stmt->bindParam(':timestamp', $timestamp, PDO::PARAM_INT);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean audit log entries: " . $e->getMessage(), 0, $e);
            }
        }
    }