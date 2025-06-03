<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\EntityRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class EntitiesManager
    {
        /**
         * Registers a new entity with the given ID and domain.
         *
         * @param string $id The ID of the entity.
         * @param string|null $domain The domain of the entity, can be null.
         * @throws InvalidArgumentException If the ID exceeds 255 characters or if the domain is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function registerEntity(string $id, ?string $domain=null): void
        {
            if(strlen($id) > 255)
            {
                throw new InvalidArgumentException("Entity ID cannot exceed 255 characters.");
            }
            if(!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && $domain !== null)
            {
                throw new InvalidArgumentException("Invalid domain format.");
            }
            if(strlen($domain) > 255)
            {
                throw new InvalidArgumentException("Domain cannot exceed 255 characters.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO entities (id, domain) VALUES (:id, :domain)");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':domain', $domain);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to register entity: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves an entity by its ID and domain.
         *
         * @param string $id The ID of the entity.
         * @param string $domain The domain of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the ID or domain is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByDomain(string $id, string $domain): ?EntityRecord
        {
            if(strlen($id) < 1 || strlen($domain) < 1)
            {
                throw new InvalidArgumentException("Entity ID and domain must be provided.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE id = :id AND domain = :domain");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':domain', $domain);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data)
                {
                    return new EntityRecord($data);
                }
                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by domain: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity.
         * @return EntityRecord|null The EntityRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntityByUuid(string $uuid): ?EntityRecord
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data)
                {
                    return new EntityRecord($data);
                }
                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entity by UUID: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes an entity by its UUID.
         *
         * @param string $uuid The UUID of the entity to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEntity(string $uuid): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException("Entity UUID must be provided.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes an entity by its ID and domain.
         *
         * @param string $id The ID of the entity to delete.
         * @param string $domain The domain of the entity to delete.
         * @throws InvalidArgumentException If the ID or domain is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEntityById(string $id, string $domain): void
        {
            if(strlen($id) < 1 || strlen($domain) < 1)
            {
                throw new InvalidArgumentException("Entity ID and domain must be provided.");
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entities WHERE id = :id AND domain = :domain");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':domain', $domain);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete entity by ID and domain: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves a list of entities with pagination.
         *
         * @param int $limit The maximum number of entities to retrieve per page.
         * @param int $page The page number to retrieve.
         * @return EntityRecord[] An array of EntityRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEntities(int $limit=100, int $page=1): array
        {
            if($limit < 1)
            {
                $limit = 100;
            }
            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM entities ORDER BY created DESC LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $entities = [];
                while($row = $stmt->fetch(PDO::FETCH_ASSOC))
                {
                    $entities[] = new EntityRecord($row);
                }
                return $entities;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve entities: " . $e->getMessage(), $e->getCode(), $e);
            }
        }
    }