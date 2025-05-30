<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Classes\Enums\BlacklistType;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\BlacklistRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class BlacklistManager
    {
        /**
         * Blacklists an entity with the specified operator and type.
         *
         * @param string $entity The UUID of the entity to blacklist.
         * @param string $operator The UUID of the operator performing the blacklisting.
         * @param BlacklistType $type The type of blacklist action.
         * @param int|null $expires Optional expiration time in Unix timestamp, null for permanent blacklisting.
         * @throws InvalidArgumentException If the entity or operator is empty, or if expires is in the past.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function blacklistEntity(string $entity, string $operator, BlacklistType $type, ?int $expires = null): void
        {
            if(empty($entity) || empty($operator))
            {
                throw new InvalidArgumentException("Entity and operator cannot be empty.");
            }

            if(!is_null($expires) && $expires < time())
            {
                throw new InvalidArgumentException("Expiration time must be in the future or null for permanent blacklisting.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO blacklist (entity, operator, type, expires) VALUES (:entity, :operator, :type, :expires)");
                $type = $type->value;
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':type', $type);

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
         * @param string $entity The UUID of the entity to remove from the blacklist.
         * @throws InvalidArgumentException If the entity is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteBlacklistEntry(string $entity): void
        {
            if(empty($entity))
            {
                throw new InvalidArgumentException("Entity cannot be empty.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM blacklist WHERE entity = :entity");
                $stmt->bindParam(':entity', $entity);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete blacklist entry: " . $e->getMessage(), 0, $e);
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
    }