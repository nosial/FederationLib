<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Classes\Validate;
    use FederationServer\Enums\BlacklistType;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\BlacklistRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\UuidV4;

    class BlacklistManager
    {
        /**
         * Blacklists an entity with the specified operator and type.
         *
         * @param string $entity The UUID of the entity to blacklist.
         * @param string $operator The UUID of the operator performing the blacklisting.
         * @param BlacklistType $type The type of blacklist action.
         * @param int|null $expires Optional expiration time in Unix timestamp, null for permanent blacklisting.
         * @param string|null $evidence Optional evidence UUID, must be a valid UUID if provided.
         * @return string The UUID of the created blacklist entry.
         * @throws InvalidArgumentException If the entity or operator is empty, or if expires is in the past.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function blacklistEntity(string $entity, string $operator, BlacklistType $type, ?int $expires=null, ?string $evidence=null): string
        {
            if(empty($entity) || empty($operator))
            {
                throw new InvalidArgumentException("Entity and operator cannot be empty.");
            }

            if(!is_null($expires) && $expires < time())
            {
                throw new InvalidArgumentException("Expiration time must be in the future or null for permanent blacklisting.");
            }

            if(!is_null($evidence) && !Validate::uuid($evidence))
            {
                throw new InvalidArgumentException("Evidence must be a valid UUID.");
            }

            $uuid = UuidV4::v4()->toRfc4122();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO blacklist (uuid, entity, operator, type, expires, evidence) VALUES (:uuid, :entity, :operator, :type, :expires, :evidence)");
                $stmt->bindParam(':uuid', $uuid);
                $type = $type->value;
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':evidence', $evidence);

                // Convert expires to datetime
                if(is_null($expires))
                {
                    $stmt->bindValue(':expires', null, PDO::PARAM_NULL);
                }
                else
                {
                    $stmt->bindValue(':expires', date('Y-m-d H:i:s', $expires));
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to prepare SQL statement for blacklisting entity: " . $e->getMessage(), 0, $e);
            }

            return $uuid;
        }

        /**
         * Checks if an entity is currently blacklisted.
         *
         * @param string $entity The UUID of the entity to check.
         * @return bool True if the entity is blacklisted, false otherwise.
         * @throws InvalidArgumentException If the entity is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function isBlacklisted(string $entity): bool
        {
            if(empty($entity))
            {
                throw new InvalidArgumentException("Entity cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM blacklist WHERE entity = :entity AND (expires IS NULL OR expires > NOW())");
                $stmt->bindParam(':entity', $entity);
                $stmt->execute();
                return $stmt->fetchColumn() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check if entity is blacklisted: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Checks if a blacklist entry exists for a specific UUID.
         *
         * @param string $uuid The UUID of the blacklist entry.
         * @return bool True if the blacklist entry exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function blacklistExists(string $uuid): bool
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM blacklist WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                return $stmt->fetchColumn() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check if blacklist exists: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves a blacklist entry by its UUID.
         *
         * @param string $uuid The UUID of the blacklist entry.
         * @return BlacklistRecord|null The BlacklistRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getBlacklistEntry(string $uuid): ?BlacklistRecord
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if($data)
                {
                    return new BlacklistRecord($data);
                }
                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entry: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Deletes a blacklist entry for a specific entity.
         *
         * @param string $uuid The UUID of the blacklist entry to delete.
         * @throws InvalidArgumentException If the entity is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteBlacklistRecord(string $uuid): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM blacklist WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete blacklist record: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Lifts a blacklist record, marking it as no longer active.
         *
         * @param string $uuid The UUID of the blacklist record to lift.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function liftBlacklistRecord(string $uuid): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException("UUID cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE blacklist SET lifted=1 WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to lift blacklist record: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Returns an array of blacklist records in a pagination style for the global database
         *
         * @param int $limit The total amount of records to return in this database query
         * @param int $page The current page, must start from 1.
         * @return BlacklistRecord[] An array of blacklist records as the result
         * @throws DatabaseOperationException Thrown if there was a database issue
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
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist ORDER BY created DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return array_map(fn($data) => new BlacklistRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entries by operator: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves all blacklist entries for a specific entity.
         *
         * @param string $operator The UUID of the operator to filter by.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @return BlacklistRecord[] An array of BlacklistRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByOperator(string $operator, int $limit = 100, int $page = 1): array
        {
            if(empty($operator))
            {
                throw new InvalidArgumentException("Operator cannot be empty.");
            }

            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE operator = :operator ORDER BY created DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return array_map(fn($data) => new BlacklistRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entries by operator: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Retrieves all blacklist entries associated with a specific entity.
         *
         * @param string $entity The UUID of the entity.
         * @param int $limit The maximum number of entries to retrieve.
         * @param int $page The page number for pagination.
         * @return BlacklistRecord[] An array of BlacklistRecord objects.
         * @throws InvalidArgumentException If the entity is empty or limit/page are invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntriesByEntity(string $entity, int $limit = 100, int $page = 1): array
        {
            if(empty($entity))
            {
                throw new InvalidArgumentException("Entity cannot be empty.");
            }

            if($limit <= 0 || $page <= 0)
            {
                throw new InvalidArgumentException("Limit and page must be greater than zero.");
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM blacklist WHERE entity = :entity ORDER BY created DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return array_map(fn($data) => new BlacklistRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve blacklist entries by entity: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Attaches evidence to a blacklist entry.
         *
         * @param string $blacklist The UUID of the blacklist entry.
         * @param string $evidence The UUID of the evidence to attach.
         * @throws InvalidArgumentException If the blacklist or evidence UUIDs are empty or invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function attachEvidence(string $blacklist, string $evidence): void
        {
            if(empty($blacklist) || empty($evidence))
            {
                throw new InvalidArgumentException("Blacklist and evidence UUIDs cannot be empty.");
            }

            if(!Validate::uuid($blacklist) || !Validate::uuid($evidence))
            {
                throw new InvalidArgumentException("Blacklist and evidence must be valid UUIDs.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE blacklist SET evidence = :evidence WHERE uuid = :blacklist");
                $stmt->bindParam(':blacklist', $blacklist);
                $stmt->bindParam(':evidence', $evidence);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to attach evidence to blacklist: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Cleans up blacklist entries that have expired based on the specified number of days.
         *
         * @param int $getCleanBlacklistDays The number of days to consider for cleaning expired entries.
         * @return int The number of entries cleaned.
         * @throws InvalidArgumentException If the number of days is less than or equal to zero.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function cleanEntries(int $getCleanBlacklistDays): int
        {
            // Remove blacklist records older than $cleanBlacklistDays and if the expiriation hasn't been expired yet
            if($getCleanBlacklistDays <= 0)
            {
                throw new InvalidArgumentException("Number of days must be greater than zero.");
            }

            // Mariadb uses Timestamp
            $dateThreshold = date('Y-m-d H:i:s', strtotime("-$getCleanBlacklistDays days"));
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM blacklist WHERE (expires IS NOT NULL AND expires < :dateThreshold) OR (expires IS NULL AND created < :dateThreshold)");
                $stmt->bindParam(':dateThreshold', $dateThreshold);
                $stmt->execute();

                return $stmt->rowCount(); // Return the number of rows affected
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to clean blacklist entries: " . $e->getMessage(), 0, $e);
            }
        }
    }