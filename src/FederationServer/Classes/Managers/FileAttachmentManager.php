<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\FileAttachmentRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class FileAttachmentManager
    {
        /**
         * Creates a new file attachment record.
         *
         * @param string $uuid The UUID of the file attachment.
         * @param string $evidence The UUID of the evidence associated with the file attachment.
         * @param string $fileName The name of the file being attached.
         * @param string $fileSize The size of the file in bytes.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         * @throws InvalidArgumentException If the file name exceeds 255 characters or if the file size is not a positive integer.
         */
        public static function createRecord(string $uuid, string $evidence, string $fileName, string $fileSize): void
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
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO file_attachments (uuid, evidence, file_name, file_size) VALUES (:uuid, :evidence, :file_name, :file_size)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':evidence', $evidence);
                $stmt->bindParam(':file_name', $fileName);
                $stmt->bindParam(':file_size', $fileSize);

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
         */
        public static function getRecord(string $uuid): ?FileAttachmentRecord
        {
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

                return new FileAttachmentRecord($result);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment record: " . $e->getMessage(), $e->getCode(), $e);
            }
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
                return array_map(fn($data) => new FileAttachmentRecord($data), $results);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve file attachment records: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Deletes a file attachment record by its UUID.
         *
         * @param string $uuid The UUID of the file attachment record to delete.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteRecord(string $uuid): void
        {
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
        }
    }