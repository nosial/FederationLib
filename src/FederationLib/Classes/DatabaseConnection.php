<?php

    namespace FederationLib\Classes;

    use FederationLib\Enums\DatabaseTables;
    use FederationLib\Exceptions\DatabaseOperationException;
    use PDO;
    use PDOException;
    use RuntimeException;

    class DatabaseConnection
    {
        private static ?PDO $pdo = null;

        /**
         * Get the PDO connection instance. If it does not exist, create it using the configuration.
         *
         * @return PDO Returns the PDO Connection to the Database
         */
        public static function getConnection(): PDO
        {
            // If the connection is not already established, create a new PDO instance.
            if (self::$pdo === null)
            {
                self::$pdo = new PDO(
                    Configuration::getDatabaseConfiguration()->getDsn(),
                    Configuration::getDatabaseConfiguration()->getUsername(),
                    Configuration::getDatabaseConfiguration()->getPassword(),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . Configuration::getDatabaseConfiguration()->getCharset() . ' COLLATE ' . Configuration::getDatabaseConfiguration()->getCollation(),
                    ]
                );
            }

            return self::$pdo;
        }

        /**
         * Initializes/Updates all the database tables required for FederationLib to run
         *
         * @return void
         * @throws DatabaseOperationException Thrown if there was an error during initialization
         */
        public static function initializeDatabase(): void
        {
            foreach(DatabaseTables::getOrderedTables() as $sql)
            {
                // Skip if the table already exists
                if(self::tableExists($sql->getTableName()))
                {
                    continue;
                }

                Logger::log()->info("Creating table {$sql->getTableName()}");
                $path = $sql->getPath();
                if (!file_exists($path))
                {
                    throw new RuntimeException("SQL file for table $sql->name does not exist at path: $path");
                }

                $sqlContent = @file_get_contents($path);
                if ($sqlContent === false)
                {
                    throw new RuntimeException("Failed to read SQL file for table $sql->name at path: $path");
                }

                try
                {
                    // Execute the SQL content to create or update the table.
                    self::getConnection()->exec($sqlContent);
                    if(!self::tableExists($sql->getTableName()))
                    {
                        throw new DatabaseOperationException("Failed to create table {$sql->getTableName()} verify if the SQL in {$sql->getPath()} is valid");
                    }

                    Logger::log()->info("Database table {$sql->getTableName()} initialized successfully.");
                }
                catch (PDOException $e)
                {
                    throw new DatabaseOperationException("Failed to execute SQL for table $sql->name: " . $e->getMessage());
                }
            }
        }

        /**
         * Checks if the given table exists in the database
         *
         * @param string $table The table to check its existence
         * @return bool Returns True if the table exists, False otherwise.
         */
        private static function tableExists(string $table): bool
        {
            $stmt = self::getConnection()->query("SHOW TABLES LIKE '$table'");
            if($stmt === false || $stmt->rowCount() === 0)
            {
                return false;
            }

            return true;
        }
    }
