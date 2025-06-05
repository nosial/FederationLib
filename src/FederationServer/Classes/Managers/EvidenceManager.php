<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\EvidenceRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\UuidV4;

    class EvidenceManager
    {
        /**
         * Adds a new evidence record to the database.
         *
         * @param string $entity The UUID of the entity associated with the evidence.
         * @param string $operator The UUID of the operator associated with the evidence.
         * @param string|null $textContent Optional text content, can be null.
         * @param string|null $note Optional note, can be null.
         * @param bool $confidential Whether the evidence is confidential (default is false).
         * @throws InvalidArgumentException If the entity or operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @return string The UUID of the newly created evidence record.
         */
        public static function addEvidence(string $entity, string $operator, ?string $textContent=null, ?string $note=null, bool $confidential=false): string
        {
            if(strlen($entity) < 1 || strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Entity and operator must be provided.');
            }

            $uuid = UuidV4::v4()->toRfc4122();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO evidence (uuid, entity, operator, confidential, text_content, note) VALUES (:uuid, :entity, :operator, :confidential, :text_content, :note)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':confidential', $confidential);
                $stmt->bindParam(':text_content', $textContent);
                $stmt->bindParam(':note', $note);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to add evidence: " . $e->getMessage(), $e->getCode(), $e);
            }

            return $uuid;
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

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
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
         * Retrieves all evidence records from the database.
         *
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceRecords(int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence";
                if(!$includeConfidential)
                {
                    $query .= " WHERE confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $evidenceRecords = [];
                foreach ($results as $data)
                {
                    $evidenceRecords[] = new EvidenceRecord($data);
                }

                return $evidenceRecords;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve evidence records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves all evidence records associated with a specific operator.
         *
         * @param string $entity The UUID of the entity.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByEntity(string $entity, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($entity) < 1)
            {
                throw new InvalidArgumentException('Entity must be provided.');
            }
            
            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE entity = :entity";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @param bool $includeConfidential Whether to include confidential evidence records (default is false).
         * @return EvidenceRecord[] An array of EvidenceRecord objects.
         * @throws InvalidArgumentException If the operator is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getEvidenceByOperator(string $operator, int $limit=100, int $page=1, bool $includeConfidential=false): array
        {
            if(strlen($operator) < 1)
            {
                throw new InvalidArgumentException('Operator must be provided.');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $query = "SELECT * FROM evidence WHERE operator = :operator";
                if(!$includeConfidential)
                {
                    $query .= " AND confidential = 0";
                }
                $query .= " ORDER BY created DESC LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($query);
                $stmt->bindParam(':operator', $operator);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        /**
         * Checks if an evidence record exists by its UUID.
         *
         * @param string $uuid The UUID of the evidence record to check.
         * @return bool True if the evidence exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function evidenceExists(string $uuid): bool
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM evidence WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                return $stmt->fetchColumn() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check evidence existence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Updates the confidentiality status of an evidence record.
         *
         * @param string $uuid The UUID of the evidence record to update.
         * @param bool $confidential The new confidentiality status (true for confidential, false for non-confidential).
         * @throws InvalidArgumentException If the UUID is not provided or is empty.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function updateConfidentiality(string $uuid, bool $confidential): void
        {
            if(strlen($uuid) < 1)
            {
                throw new InvalidArgumentException('UUID must be provided.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE evidence SET confidential = :confidential WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':confidential', $confidential, PDO::PARAM_BOOL);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to update confidentiality: " . $e->getMessage(), $e->getCode(), $e);
            }
        }
    }
