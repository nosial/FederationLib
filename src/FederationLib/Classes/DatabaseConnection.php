<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Enums\DatabaseTables;
    use FederationLib\Exceptions\DatabaseOperationException;
    use PDO;
    use Pdo\Mysql;
    use PDOException;
    use RuntimeException;
    use Throwable;

    class DatabaseConnection
    {
        public const string SCHEMA_VERSION = '1.4';
        private const string BASELINE_SCHEMA_VERSION = '1.0';
        private const string SCHEMA_VERSION_KEY = 'version';
        private static ?PDO $pdo = null;
        private static bool $databaseCreated = false;
        private static ?string $migratedFrom = null;

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

            self::$databaseCreated = !$existingDatabase;
            self::migrateSchema($existingDatabase);
        }

        /**
         * Returns whether the latest initialization created the database tables, rather than finding them
         *
         * @return bool True if the database was created by the latest initialization
         */
        public static function wasDatabaseCreated(): bool
        {
            return self::$databaseCreated;
        }

        /**
         * Returns the schema version the latest initialization migrated the database from
         *
         * @return string|null The previous schema version, null if no migration was run
         */
        public static function getMigratedFrom(): ?string
        {
            return self::$migratedFrom;
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
            $migrated = false;
            $previousVersion = $currentVersion;

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
                $migrated = true;
            }

            // Records cached before the migration have the shape of the previous schema
            if($migrated)
            {
                self::$migratedFrom = $previousVersion;
                self::clearCachedRecords();
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
         * Removes the records cached in Redis after the schema was migrated, so that no record cached with the shape of
         * the previous schema (eg; an operator without its configuration columns) is served afterwards, along with the
         * keys of the reputation windows of earlier releases. A cache failure is logged, the migration itself succeeded.
         *
         * @return void
         */
        private static function clearCachedRecords(): void
        {
            if(!Configuration::getRedisConfiguration()->isEnabled())
            {
                return;
            }

            try
            {
                foreach([EntitiesManager::CACHE_PREFIX, OperatorManager::CACHE_PREFIX, OperatorManager::ACCESS_TOKEN_POINTER_PREFIX,
                         EvidenceManager::CACHE_PREFIX, BlacklistManager::CACHE_PREFIX, ReportManager::CACHE_PREFIX,
                         FileAttachmentManager::CACHE_PREFIX, AuditLogManager::CACHE_PREFIX] as $prefix)
                {
                    RedisConnection::clearRecords($prefix);
                    RedisConnection::clearSearchCache($prefix);
                }

                // Reputation is settled from the database since 2.0.0, the reputation windows are no longer read
                RedisConnection::clearRecords('reputation_window:');
                Logger::log()->info('Cleared the records cached before the database schema was migrated');
            }
            catch(Throwable $e)
            {
                Logger::log()->warning('Failed to clear the records cached before the database schema was migrated, flush the Redis database if records are served with missing members: ' . $e->getMessage(), $e);
            }
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
            return [
                // OFD 2.0 added the report member to the AuditLog object
                '1.1' => function(PDO $pdo): void
                {
                    $pdo->exec("ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS report varchar(36) null comment 'Optional. The report related to this action' AFTER file_attachment");
                    $pdo->exec('SET SESSION foreign_key_checks = 0');
                    try
                    {
                        $pdo->exec('ALTER TABLE audit_log ADD CONSTRAINT audit_log_reports_uuid_fk FOREIGN KEY IF NOT EXISTS (report) REFERENCES reports (uuid) ON UPDATE CASCADE ON DELETE SET NULL');
                    }
                    finally
                    {
                        $pdo->exec('SET SESSION foreign_key_checks = 1');
                    }
                },
                // When evidence was classified, so plugins can learn the classifications they missed in the order they
                // were made (eg; BayesianPlugin while BayesianServer was unavailable)
                '1.2' => function(PDO $pdo): void
                {
                    $pdo->exec("ALTER TABLE evidence ADD COLUMN IF NOT EXISTS classified timestamp null comment 'Optional. The Timestamp for when the classification flag was assigned' AFTER classification_flag");
                    $pdo->exec('CREATE INDEX IF NOT EXISTS evidence_classified_uuid_index ON evidence (classified, uuid)');
                    $pdo->exec('UPDATE evidence SET classified = COALESCE(updated, created) WHERE classification_flag IS NOT NULL AND classified IS NULL');
                },
                // OFD 2.0 added content types and their context to evidence, and the ADVERTISING and TRACKING incident types;
                // the incident types are appended to the enums, which MariaDB changes in place without copying the tables
                '1.3' => function(PDO $pdo): void
                {
                    $pdo->exec("ALTER TABLE evidence ADD COLUMN IF NOT EXISTS content_type enum ('TEXT', 'EMAIL', 'SMS', 'WEB_PAGE', 'HOSTNAME', 'URL') default 'TEXT' not null comment 'The content type of the text content, how it is interpreted' AFTER text_content");
                    $pdo->exec("ALTER TABLE evidence ADD COLUMN IF NOT EXISTS context TEXT null comment 'Optional. The JSON-encoded context of the content as the client observed it, its keys depend on the content type' AFTER content_type");
                    $pdo->exec("ALTER TABLE blacklist MODIFY COLUMN type enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER', 'ADVERTISING', 'TRACKING') not null comment 'The incident type'");
                    $pdo->exec("ALTER TABLE reports MODIFY COLUMN incident_type enum ('SPAM', 'SCAM', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT', 'MALWARE', 'PHISHING', 'CSAM', 'OTHER', 'ADVERTISING', 'TRACKING') default 'OTHER' not null comment 'The incident type for the report'");
                    // OFD 2.0 allows the report supporting a blacklist record to concern another entity, only the comment changes
                    $pdo->exec("ALTER TABLE blacklist MODIFY COLUMN report varchar(36) null comment 'Optional. The UUID of the report supporting the blacklist, which may concern another entity'");
                },
                // OFD 2.0 split the auto assign opt-in by the kind of report, added bot operators and automated evidence
                '1.4' => function(PDO $pdo): void
                {
                    // The previous opt-in covered every report, so it carries over to both kinds
                    $hasAutoAssign = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operators' AND COLUMN_NAME = 'auto_assign'")->fetchColumn() > 0;
                    if($hasAutoAssign)
                    {
                        $pdo->exec("ALTER TABLE operators CHANGE COLUMN auto_assign auto_assign_reports tinyint(1) default 0 not null comment 'Default: 0, 1=The operator opts in to the automatic assignment of reports that are not automated'");
                    }
                    else
                    {
                        $pdo->exec("ALTER TABLE operators ADD COLUMN IF NOT EXISTS auto_assign_reports tinyint(1) default 0 not null comment 'Default: 0, 1=The operator opts in to the automatic assignment of reports that are not automated' AFTER operator_permissions");
                    }

                    $pdo->exec("ALTER TABLE operators ADD COLUMN IF NOT EXISTS auto_assign_automated_reports tinyint(1) default 0 not null comment 'Default: 0, 1=The operator opts in to the automatic assignment of automated reports' AFTER auto_assign_reports");
                    $pdo->exec('UPDATE operators SET auto_assign_automated_reports = auto_assign_reports');
                    $pdo->exec("ALTER TABLE operators ADD COLUMN IF NOT EXISTS is_bot tinyint(1) default 0 not null comment 'Default: 0, 1=The operator is a bot, the evidence and reports it submits are automated and its evidence is created confidential' AFTER auto_assign_automated_reports");
                    $pdo->exec("ALTER TABLE evidence ADD COLUMN IF NOT EXISTS automated tinyint(1) default 0 not null comment 'Default: 0, 1=The evidence was created by the host itself or submitted by a bot operator' AFTER confidential");
                    $pdo->exec("UPDATE evidence SET automated = 1 WHERE operator IN (SELECT uuid FROM operators WHERE name = 'system')");
                }
            ];
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
