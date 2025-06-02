<?php

    namespace FederationServer\Classes\Managers;

    use FederationServer\Classes\DatabaseConnection;
    use FederationServer\Classes\Utilities;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Objects\OperatorRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;

    class OperatorManager
    {
        /**
         * Create a new operator with a unique API key.
         *
         * @param string $name The name of the operator.
         * @return string The generated UUID for the operator.
         * @throws InvalidArgumentException If the name is empty or exceeds 255 characters.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function createOperator(string $name): string
        {
            if(empty($name))
            {
                throw new InvalidArgumentException('Operator name cannot be empty.');
            }

            if(strlen($name) > 255)
            {
                throw new InvalidArgumentException('Operator name cannot exceed 255 characters.');
            }

            $uuid = Uuid::v7()->toRfc4122();
            $apiKey = Utilities::generateString();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO operators (uuid, api_key, name) VALUES (:uuid, :api_key, :name)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':api_key', $apiKey);
                $stmt->bindParam(':name', $name);

                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to create operator '%s'", $name), 0, $e);
            }

            return $uuid;
        }

        /**
         * Retrieve an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @return OperatorRecord|null The operator record if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function getOperator(string $uuid): ?OperatorRecord
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM operators WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $data = $stmt->fetch();

                if($data === false)
                {
                    return null; // No operator found with the given UUID
                }

                return new OperatorRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to retrieve operator with UUID '%s'", $uuid), 0, $e);
            }
        }

        /**
         * Retrieve an operator by their API key.
         *
         * @param string $apiKey The API key of the operator.
         * @return OperatorRecord|null The operator record if found, null otherwise.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function getOperatorByApiKey(string $apiKey): ?OperatorRecord
        {
            if(empty($apiKey))
            {
                throw new InvalidArgumentException('API key cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM operators WHERE api_key=:api_key");
                $stmt->bindParam(':api_key', $apiKey);
                $stmt->execute();

                $data = $stmt->fetch();

                if($data === false)
                {
                    return null; // No operator found with the given API key
                }

                return new OperatorRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to retrieve operator with API key '%s'", $apiKey), 0, $e);
            }
        }

        /**
         * Disable an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function disableOperator(string $uuid): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE operators SET disabled=1 WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to disable operator with UUID '%s'", $uuid), 0, $e);
            }
        }

        /**
         * Enable an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function enableOperator(string $uuid): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE operators SET disabled=0 WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to enable operator with UUID '%s'", $uuid), 0, $e);
            }
        }

        /**
         * Delete an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function deleteOperator(string $uuid): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM operators WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to delete operator with UUID '%s'", $uuid), 0, $e);
            }
        }

        /**
         * Refresh the API key for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @return string The new API key for the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function refreshApiKey(string $uuid): string
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            $newApiKey = Utilities::generateString();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE operators SET api_key=:api_key WHERE uuid=:uuid");
                $stmt->bindParam(':api_key', $newApiKey);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to refresh API key for operator with UUID '%s'", $uuid), 0, $e);
            }

            return $newApiKey;
        }

        /**
         * Set the management permissions for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @param bool $canManageOperators True if the operator can manage other operators, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function setManageOperators(string $uuid, bool $canManageOperators): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE operators SET manage_operators=:manage_operators WHERE uuid=:uuid");
                $stmt->bindParam(':manage_operators', $canManageOperators, PDO::PARAM_BOOL);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to set operator management permissions for operator with UUID '%s'", $uuid), 0, $e);
            }
        }

        /**
         * Set the blacklist management permissions for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @param bool $canManageBlacklist True if the operator can manage the blacklist, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function setManageBlacklist(string $uuid, bool $canManageBlacklist): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE operators SET manage_blacklist=:manage_blacklist WHERE uuid=:uuid");
                $stmt->bindParam(':manage_blacklist', $canManageBlacklist, PDO::PARAM_BOOL);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to set blacklist management permissions for operator with UUID '%s'", $uuid), 0, $e);
            }
        }

        /**
         * Set the client status for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @param bool $isClient True if the operator is a client, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function setClient(string $uuid, bool $isClient): void
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE operators SET is_client=:is_client WHERE uuid=:uuid");
                $stmt->bindParam(':is_client', $isClient, PDO::PARAM_BOOL);
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to set client status for operator with UUID '%s'", $uuid), 0, $e);
            }
        }
    }