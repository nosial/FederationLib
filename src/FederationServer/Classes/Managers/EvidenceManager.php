<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\EvidenceRecord;
    use InvalidArgumentException;
    use PDOException;

    class EvidenceManager
    {
        /**
         * Adds a new evidence record to the database.
         *
         * @param string $entity The UUID of the entity associated with the evidence.
         * @param string $operator The UUID of the operator associated with the evidence.
         * @param string|null $blacklist Optional blacklist value, can be null.
         * @param string|null $textContent Optional text content, can be null.
         * @param string|null $note Optional note, can be null.
         * @throws InvalidArgumentException If the entity or operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function addEvidence(string $entity, string $operator, ?string $blacklist=null, ?string $textContent=null, ?string $note=null): void
        {
            if(strlen($entity) < 1 || strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Entity and operator must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO evidence (entity, operator, blacklist, text_content, note) VALUES (:entity, :operator, :blacklist, :text_content, :note)");
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':blacklist', $blacklist);
                $stmt->bindParam(':text_content', $textContent);
                $stmt->bindParam(':note', $note);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to add evidence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes an evidence record by its UUID.
         *
         * @param string $uuid The UUID of the evidence record to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteEvidence(string $uuid): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete evidence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves a specific evidence record by its UUID.
         *
         * @param string $uuid The UUID of the evidence record.
         * @return EvidenceRecord|null The EvidenceRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidence(string $uuid): ?EvidenceRecord
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch(\PDO::FETCH_ASSOC);
                if($data)
                {
                    return new EvidenceRecord($data);
                }
                return null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves all evidence records associated with a specific operator.
         *
         * @param string $entity The UUID of the entity.
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByEntity(string $entity): array
        {
            if(strlen($entity) < 1)
            {
                throw new InvalidArgumentException('Entity must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM evidence WHERE entity = :entity");
                $stmt->bindParam(':entity', $entity);
                $stmt->execute();

                $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by entity: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves all evidence records associated with a specific operator.
         *
         * @param string $operator The UUID of the operator.
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws InvalidArgumentException If the operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByOperator(string $operator): array
        {
            if(strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Operator must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM evidence WHERE operator = :operator");
                $stmt->bindParam(':operator', $operator);
                $stmt->execute();

                $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence by operator: " . $e->getMessage(), $e->getCode(), $e);
            }
        }
    }