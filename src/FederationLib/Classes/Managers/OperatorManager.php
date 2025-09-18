<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\OperatorRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;

    class OperatorManager
    {
        public const string CACHE_PREFIX = 'operator:';
        public const string API_KEY_POINTER_PREFIX = 'operator_api_key:';

        /**
         * Create a new operator with a unique API key.
         *
         * @param string $name The name of the operator.
         * @return string The generated UUID for the operator.
         * @throws InvalidArgumentException If the name is empty or exceeds 255 characters.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function createOperator(string $name): string
        {
            if(empty($name))
            {
                throw new InvalidArgumentException('Operator name cannot be empty.');
            }

            if(strlen($name) > 32)
            {
                throw new InvalidArgumentException('Operator name cannot exceed 32 characters.');
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
         * Creates the master operator with a predefined API key.
         *
         * @param string $apiKey The API key for the master operator.
         * @return string The UUID of the created master operator.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        private static function createMasterOperator(string $apiKey): string
        {
            if(empty($apiKey))
            {
                throw new InvalidArgumentException('API key cannot be empty.');
            }

            if(strlen($apiKey) !== 32)
            {
                throw new InvalidArgumentException('API key must be exactly 32 characters long.');
            }

            // This method is used to create the master operator with a predefined API key.
            // It should only be called once during the initial setup of the server.
            $uuid = Uuid::v7()->toRfc4122();

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT INTO operators (uuid, api_key, name, manage_operators, manage_blacklist, is_client) VALUES (:uuid, :api_key, 'root', 1, 1, 1)");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':api_key', $apiKey);

                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to create master operator', 0, $e);
            }

            return $uuid;
        }

        /**
         * Check if the given UUID belongs to the master operator.
         *
         * @param string $uuid The UUID to check.
         * @return bool True if the UUID belongs to the master operator, false otherwise.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function isMasterOperator(string $uuid): bool
        {
            return self::getMasterOperator()->getUuid() === $uuid;
        }

        /**
         * Retrieve the master operator.
         *
         * This method checks if the master operator exists in the database.
         * If it does not exist, it creates one with a predefined API key.
         *
         * @return OperatorRecord The master operator record.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws InvalidArgumentException If the API key for the master operator is not set in the configuration.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getMasterOperator(): OperatorRecord
        {
            // This method retrieves the master operator from the database.
            // If the master operator does not exist, it creates one with a predefined API key.
            $apiKey = Configuration::getServerConfiguration()->getApiKey();

            if(empty($apiKey))
            {
                throw new InvalidArgumentException('API key for master operator is not set in configuration.');
            }

            $operator = self::getOperatorByApiKey($apiKey);
            if($operator === null)
            {
                $uuid = self::createMasterOperator($apiKey);
                $operator = self::getOperator($uuid);
            }

            return $operator;
        }

        /**
         * Retrieve an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @return OperatorRecord|null The operator record if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function getOperator(string $uuid): ?OperatorRecord
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            if(self::isCachingEnabled())
            {
                $cached = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                if($cached !== null)
                {
                    return new OperatorRecord($cached);
                }
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

                $operatorRecord = new OperatorRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to retrieve operator with UUID '%s'", $uuid), 0, $e);
            }

            // Cache the operator record if caching is enabled and limit not reached
            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getOperatorCacheLimit() ?? 0))
            {
                RedisConnection::setRecord(
                    record: $operatorRecord, 
                    cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $uuid),
                    ttl: Configuration::getRedisConfiguration()->getOperatorCacheTTL() ?? 0
                );

                // Create API key pointer for quick lookups
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()),
                    expire: Configuration::getRedisConfiguration()->getOperatorCacheTTL() ?? 0,
                    value: $uuid
                );
            }

            return $operatorRecord;
        }

        /**
         * Check if an operator exists by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @return bool True if the operator exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
         */
        public static function operatorExists(string $uuid): bool
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            if(self::isCachingEnabled())
            {
                $cached = RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                if($cached)
                {
                    return true;
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM operators WHERE uuid=:uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();

                $exists = $stmt->fetchColumn() > 0; // Returns true if operator exists, false otherwise
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to check existence of operator with UUID '%s'", $uuid), 0, $e);
            }

            return $exists;
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

            // Try cache with API key pointer first
            if(self::isCachingEnabled())
            {
                $cachedUuid = RedisConnection::getConnection()->get(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $apiKey));
                if($cachedUuid !== false && strlen($cachedUuid) > 0)
                {
                    $data = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $cachedUuid));
                    if($data !== null)
                    {
                        return new OperatorRecord($data);
                    }
                    else
                    {
                        // API key pointer exists but operator record is missing from cache
                        // Remove the stale API key pointer to prevent future inconsistencies
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $apiKey));
                    }
                }
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

                $operatorRecord = new OperatorRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to retrieve operator with API key '%s'", $apiKey), 0, $e);
            }

            // Cache the operator record and create API key pointer if caching is enabled and limit not reached
            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getOperatorCacheLimit() ?? 0))
            {
                RedisConnection::setRecord(
                    record: $operatorRecord,
                    cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $operatorRecord->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getOperatorCacheTTL() ?? 0
                );

                // Create a pointer from API key to UUID for quick lookups
                // Only set the API key pointer if the operator record was successfully cached
                RedisConnection::getConnection()->setex(
                    key: sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $apiKey),
                    expire: Configuration::getRedisConfiguration()->getOperatorCacheTTL() ?? 0,
                    value: $operatorRecord->getUuid()
                );
            }

            return $operatorRecord;
        }

        /**
         * Disable an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($cachedOperator !== null)
                    {
                        $operatorRecord = new OperatorRecord($cachedOperator);
                        // Remove API key pointer
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
            }
        }

        /**
         * Enable an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($cachedOperator !== null)
                    {
                        $operatorRecord = new OperatorRecord($cachedOperator);
                        // Remove API key pointer
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
            }
        }

        /**
         * Delete an operator by their UUID.
         *
         * @param string $uuid The UUID of the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there was an error during the cache operation.
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($cachedOperator !== null)
                    {
                        $operatorRecord = new OperatorRecord($cachedOperator);
                        // Remove API key pointer
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
            }
        }

        /**
         * Refresh the API key for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @return string The new API key for the operator.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException
         */
        public static function refreshApiKey(string $uuid): string
        {
            if(empty($uuid))
            {
                throw new InvalidArgumentException('Operator UUID cannot be empty.');
            }

            // Get the current operator to access the old API key for cache invalidation
            $oldOperator = null;
            if(self::isCachingEnabled())
            {
                $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                if($cachedOperator !== null)
                {
                    $oldOperator = new OperatorRecord($cachedOperator);
                }
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    // Remove old API key pointer if we have the old operator data
                    if($oldOperator !== null)
                    {
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $oldOperator->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
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
         * @throws CacheOperationException If there is an error during the caching operation.
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($cachedOperator !== null)
                    {
                        $operatorRecord = new OperatorRecord($cachedOperator);
                        // Remove API key pointer
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
            }
        }

        /**
         * Set the blacklist management permissions for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @param bool $canManageBlacklist True if the operator can manage the blacklist, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($cachedOperator !== null)
                    {
                        $operatorRecord = new OperatorRecord($cachedOperator);
                        // Remove API key pointer
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
            }
        }

        /**
         * Set the client status for an operator.
         *
         * @param string $uuid The UUID of the operator.
         * @param bool $isClient True if the operator is a client, false otherwise.
         * @throws InvalidArgumentException If the UUID is empty.
         * @throws DatabaseOperationException If there is an error during the database operation.
         * @throws CacheOperationException If there is an error during the caching operation.
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
            finally
            {
                // Invalidate cache entries for this operator
                if(self::isCachingEnabled())
                {
                    $cachedOperator = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                    if($cachedOperator !== null)
                    {
                        $operatorRecord = new OperatorRecord($cachedOperator);
                        // Remove API key pointer
                        RedisConnection::getConnection()->del(sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operatorRecord->getApiKey()));
                    }
                    // Remove main cache entry
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $uuid));
                }
            }
        }

        /**
         * Retrieve a list of operators with pagination support.
         *
         * @param int $limit The maximum number of operators to retrieve.
         * @param int $page The page number for pagination.
         * @return OperatorRecord[] An array of OperatorRecord objects representing the operators.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function getOperators(int $limit=100, int $page=1): array
        {
            if($limit < 1 || $page < 1)
            {
                throw new InvalidArgumentException('Limit and page must be greater than 0.');
            }

            $offset = ($page - 1) * $limit;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM operators ORDER BY created LIMIT :limit OFFSET :offset");
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $operators = [];
                while($data = $stmt->fetch())
                {
                    $operators[] = new OperatorRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to retrieve operators', 0, $e);
            }

            // Pre-cache operators if caching is enabled and pre-caching is enabled
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                foreach($operators as $operator)
                {
                    if(!RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getOperatorCacheLimit() ?? 0))
                    {
                        RedisConnection::setRecord(
                            record: $operator,
                            cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $operator->getUuid()),
                            ttl: Configuration::getRedisConfiguration()->getOperatorCacheTTL() ?? 0
                        );

                        // Create API key pointer for quick lookups
                        RedisConnection::getConnection()->setex(
                            key: sprintf("%s%s", self::API_KEY_POINTER_PREFIX, $operator->getApiKey()),
                            expire: Configuration::getRedisConfiguration()->getOperatorCacheTTL() ?? 0,
                            value: $operator->getUuid()
                        );
                    }
                }
            }

            return $operators;
        }

        /**
         * Get the total count of operators in the database.
         *
         * @return int The total number of operators.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function getTotalOperatorsCount(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->query("SELECT COUNT(*) FROM operators");
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to retrieve total operators count', 0, $e);
            }
        }

        /**
         * Count the total number of operator records in the database.
         *
         * @return int The total number of operator records.
         * @throws DatabaseOperationException If there is an error during the database operation.
         */
        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->query("SELECT COUNT(*) FROM operators");
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to count operator records', 0, $e);
            }
        }

        // Caching operations

        /**
         * Returns True if caching & operator caching is enabled for this class
         *
         * @return bool True if caching & caching for operators is enabled, False otherwise
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isOperatorCacheEnabled();
        }
    }