<?php

    namespace FederationLib\Classes;

    use FederationLib\Enums\DatabaseTables;
    use FederationLib\Exceptions\DatabaseOperationException;
    use PDO;
    use Pdo\Mysql;
    use PDOException;
    use RuntimeException;

    class DatabaseConnection
    {
        public const string SCHEMA_VERSION = '1.0';
        private const string BASELINE_SCHEMA_VERSION = '1.0';
        private const string SCHEMA_VERSION_KEY = 'version';

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
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];

                // Add MySQL-specific init command only if the constant is available
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND'))
                {
                    $options[Mysql::ATTR_INIT_COMMAND] = 'SET NAMES ' . Configuration::getDatabaseConfiguration()->getCharset() . ' COLLATE ' . Configuration::getDatabaseConfiguration()->getCollation();
                }

                self::$pdo = new PDO(
                    Configuration::getDatabaseConfiguration()->getDsn(),
                    Configuration::getDatabaseConfiguration()->getUsername(),
                    Configuration::getDatabaseConfiguration()->getPassword(),
                    $options
                );

                // If MySQL constant wasn't available, execute charset command after connection
                if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND'))
                {
                    self::$pdo->exec('SET NAMES ' . Configuration::getDatabaseConfiguration()->getCharset() . ' COLLATE ' . Configuration::getDatabaseConfiguration()->getCollation());
                }
            }

            return self::$pdo;
        }

        /**
         * Initializes/Updates all the database tables required for FederationLib to run, then brings the database
         * schema up to SCHEMA_VERSION by running any pending migrations
         *
         * @return void
         * @throws DatabaseOperationException Thrown if there was an error during initialization or migration
         */
        public static function initializeDatabase(): void
        {
            $existingDatabase = false;
            foreach(DatabaseTables::getOrderedTables() as $sql)
            {
                // Skip if the table already exists
                if(self::tableExists($sql->getTableName()))
                {
                    if($sql !== DatabaseTables::DATABASE_METADATA)
                    {
                        $existingDatabase = true;
                    }

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

            self::migrateSchema($existingDatabase);
        }

        /**
         * Brings the database schema up to SCHEMA_VERSION.
         *
         * A database without a recorded version is either new, in which case its tables were just created from the
         * latest table definitions and it is already at SCHEMA_VERSION, or it was created before schema versions were
         * recorded, in which case it is at the baseline version. Every migration newer than the recorded version is
         * then run in ascending order, recording the version after each one so an interrupted upgrade resumes from
         * the last completed migration.
         *
         * @param bool $existingDatabase True if the database tables existed before this initialization
         * @return void
         * @throws DatabaseOperationException Thrown if the database is newer than this release or a migration fails
         */
        private static function migrateSchema(bool $existingDatabase): void
        {
            $currentVersion = self::getMetadata(self::SCHEMA_VERSION_KEY);
            if($currentVersion === null)
            {
                $currentVersion = $existingDatabase ? self::BASELINE_SCHEMA_VERSION : self::SCHEMA_VERSION;
                self::setMetadata(self::SCHEMA_VERSION_KEY, $currentVersion);
                Logger::log()->info("Database schema version set to $currentVersion");
            }

            if(version_compare($currentVersion, self::SCHEMA_VERSION, '>'))
            {
                throw new DatabaseOperationException(sprintf(
                    'The database schema version %s is newer than the version %s supported by this release of FederationLib',
                    $currentVersion, self::SCHEMA_VERSION
                ));
            }

            $migrations = self::getMigrations();
            uksort($migrations, 'version_compare');

            foreach($migrations as $version => $migration)
            {
                if(version_compare($version, $currentVersion, '<=') || version_compare($version, self::SCHEMA_VERSION, '>'))
                {
                    continue;
                }

                Logger::log()->info("Migrating database schema from $currentVersion to $version");

                try
                {
                    $migration(self::getConnection());
                }
                catch (PDOException $e)
                {
                    throw new DatabaseOperationException("Failed to migrate the database schema to $version: " . $e->getMessage(), 0, $e);
                }

                self::setMetadata(self::SCHEMA_VERSION_KEY, $version);
                $currentVersion = $version;
            }

            if($currentVersion !== self::SCHEMA_VERSION)
            {
                throw new DatabaseOperationException(sprintf(
                    'No migration brings the database schema from version %s to %s', $currentVersion, self::SCHEMA_VERSION
                ));
            }

            Logger::log()->info("Database schema version $currentVersion");
        }

        /**
         * Returns the schema migrations, keyed by the schema version each one upgrades the database to.
         *
         * When a release changes the database schema, update the table's SQL file in Resources so new databases are
         * created with the new schema, raise SCHEMA_VERSION, and add a migration here that upgrades an existing
         * database from the previous version, for example:
         *
         *     '1.1' => function(PDO $pdo): void
         *     {
         *         $pdo->exec('ALTER TABLE entities ADD COLUMN IF NOT EXISTS ...');
         *     }
         *
         * Schema changes are not transactional in MariaDB, so migrations should be safe to run again if one is
         * interrupted, and must not depend on tables that are created during initialization only.
         *
         * @return array<string, callable(PDO): void> The migrations keyed by their target schema version
         */
        private static function getMigrations(): array
        {
            return [];
        }

        /**
         * Returns a value from the database metadata table
         *
         * @param string $key The key of the value
         * @return string|null The value, or null if it is not set
         * @throws DatabaseOperationException Thrown if there was an error reading the value
         */
        public static function getMetadata(string $key): ?string
        {
            try
            {
                $stmt = self::getConnection()->prepare('SELECT value FROM database_metadata WHERE `key` = :key');
                $stmt->bindValue(':key', $key);
                $stmt->execute();
                $value = $stmt->fetchColumn();

                return $value === false ? null : $value;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to read database metadata '$key': " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Sets a value in the database metadata table, replacing any existing value
         *
         * @param string $key The key of the value
         * @param string $value The value to store
         * @return void
         * @throws DatabaseOperationException Thrown if there was an error writing the value
         */
        public static function setMetadata(string $key, string $value): void
        {
            try
            {
                $stmt = self::getConnection()->prepare(
                    'INSERT INTO database_metadata (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)'
                );
                $stmt->bindValue(':key', $key);
                $stmt->bindValue(':value', $value);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to write database metadata '$key': " . $e->getMessage(), 0, $e);
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
            $stmt = self::getConnection()->prepare('SHOW TABLES LIKE :table');
            $stmt->bindValue(':table', $table);
            $stmt->execute();
            if($stmt->rowCount() === 0)
            {
                return false;
            }

            return true;
        }
    }
