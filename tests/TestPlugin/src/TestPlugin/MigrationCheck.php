<?php

    namespace TestPlugin;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Enums\DatabaseTables;
    use PDO;
    use ReflectionMethod;
    use RuntimeException;
    use Throwable;

    /**
     * Checks the upgrade of a fully populated database of FederationLib 1.0.14 to the current schema, the way
     * `federationlib init` upgrades it on startup: the tables that do not exist yet are created from the current table
     * definitions, then every migration newer than the recorded schema version is run in ascending order.
     *
     * Two scratch databases are created as the database root user (TEST_PLUGIN_DATABASE_ROOT_PASSWORD): one built with
     * the 1.0.14 schema and populated with records, then migrated, and one created fresh from the current table
     * definitions. Their columns, indexes and foreign keys must be identical (comments aside), and the records must have
     * been carried over. Both databases are dropped afterwards, the database of the server is never touched.
     */
    class MigrationCheck
    {
        /**
         * The UUIDs of the records populated in the 1.0.14 database
         */
        private const string SYSTEM_OPERATOR = '00000000-0000-7000-8000-000000000001';
        private const string AUTO_ASSIGN_OPERATOR = '00000000-0000-7000-8000-000000000002';
        private const string PLAIN_OPERATOR = '00000000-0000-7000-8000-000000000003';
        private const string PARENT_ENTITY = '00000000-0000-7000-8000-000000000010';
        private const string CHILD_ENTITY = '00000000-0000-7000-8000-000000000011';
        private const string AUTHOR_ENTITY = '00000000-0000-7000-8000-000000000012';
        private const string SPAM_REPORT = '00000000-0000-7000-8000-000000000020';
        private const string CSAM_REPORT = '00000000-0000-7000-8000-000000000021';
        private const string SYSTEM_EVIDENCE = '00000000-0000-7000-8000-000000000030';
        private const string OPERATOR_EVIDENCE = '00000000-0000-7000-8000-000000000031';
        private const string UNCLASSIFIED_EVIDENCE = '00000000-0000-7000-8000-000000000032';
        private const string FILE_ATTACHMENT = '00000000-0000-7000-8000-000000000040';
        private const string CSAM_BLACKLIST = '00000000-0000-7000-8000-000000000050';
        private const string AUDIT_LOG = '00000000-0000-7000-8000-000000000060';

        /**
         * Runs the check
         *
         * @return array{migrated_from: string, schema_version: string, migrations: string[], differences: string[], data_errors: string[]} The result, the check passed when there are no differences and no data errors
         * @throws RuntimeException If the scratch databases cannot be created
         */
        public static function run(): array
        {
            $suffix = bin2hex(random_bytes(4));
            $migratedName = 'test_migration_migrated_' . $suffix;
            $freshName = 'test_migration_fresh_' . $suffix;

            $root = self::connect(null);
            $root->exec("CREATE DATABASE `$migratedName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $root->exec("CREATE DATABASE `$freshName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            try
            {
                $migrated = self::connect($migratedName);
                foreach(LegacySchema::TABLES as $sql)
                {
                    $migrated->exec($sql);
                }

                self::populate($migrated);
                $migrations = self::upgrade($migrated);

                $fresh = self::connect($freshName);
                foreach(DatabaseTables::getOrderedTables() as $table)
                {
                    $fresh->exec((string)file_get_contents($table->getPath()));
                }

                return [
                    'migrated_from' => LegacySchema::VERSION,
                    'schema_version' => (string)$migrated->query("SELECT value FROM database_metadata WHERE `key` = 'version'")->fetchColumn(),
                    'migrations' => $migrations,
                    'differences' => array_merge(
                        self::compare('columns', self::getColumns($migrated, $migratedName), self::getColumns($fresh, $freshName)),
                        self::compare('indexes', self::getIndexes($migrated, $migratedName), self::getIndexes($fresh, $freshName)),
                        self::compare('foreign keys', self::getForeignKeys($migrated, $migratedName), self::getForeignKeys($fresh, $freshName))
                    ),
                    'data_errors' => self::checkData($migrated)
                ];
            }
            finally
            {
                $root->exec("DROP DATABASE IF EXISTS `$migratedName`");
                $root->exec("DROP DATABASE IF EXISTS `$freshName`");
            }
        }

        /**
         * Connects to the database server of FederationLib as the root user
         *
         * @param string|null $database The database to use, null for none
         * @return PDO The connection
         */
        private static function connect(?string $database): PDO
        {
            $password = getenv('TEST_PLUGIN_DATABASE_ROOT_PASSWORD');
            if($password === false || $password === '')
            {
                throw new RuntimeException('TEST_PLUGIN_DATABASE_ROOT_PASSWORD is not set, the migration check needs to create scratch databases');
            }

            $configuration = Configuration::getDatabaseConfiguration();
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $configuration->getHost(), $configuration->getPort());
            if($database !== null)
            {
                $dsn .= ';dbname=' . $database;
            }

            return new PDO($dsn, 'root', $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        /**
         * Populates the 1.0.14 database with records as 1.0.14 wrote them
         *
         * @param PDO $pdo The 1.0.14 database
         * @return void
         */
        private static function populate(PDO $pdo): void
        {
            $pdo->exec("INSERT INTO database_metadata (`key`, value) VALUES ('version', '" . LegacySchema::VERSION . "')");

            $pdo->exec(sprintf(
                "INSERT INTO operators (uuid, name, access_token, client_permissions, management_permissions, operator_permissions, auto_assign, disabled) VALUES
                    ('%s', 'system', 'none', 1, 1, 1, 0, 0),
                    ('%s', 'auto_assign_operator', '%s', 1, 1, 0, 1, 0),
                    ('%s', 'plain_operator', '%s', 1, 0, 0, 0, 1)",
                self::SYSTEM_OPERATOR, self::AUTO_ASSIGN_OPERATOR, hash('sha256', 'auto_assign_operator'), self::PLAIN_OPERATOR, hash('sha256', 'plain_operator')
            ));

            $pdo->exec(sprintf(
                "INSERT INTO entities (uuid, hash, host, id, metadata, whitelisted, reputation, reputation_last_updated, relationship_entity, relationship_type) VALUES
                    ('%s', '%s', 'example.com', null, null, 1, 120, now(), null, null),
                    ('%s', '%s', 'sub.example.com', null, '{\"note\":\"child\"}', 0, -30, now(), '%s', 'CHILD'),
                    ('%s', '%s', 'example.org', 'author', null, 0, 0, null, null, null)",
                self::PARENT_ENTITY, hash('sha256', 'example.com'),
                self::CHILD_ENTITY, hash('sha256', 'sub.example.com'), self::PARENT_ENTITY,
                self::AUTHOR_ENTITY, hash('sha256', 'author@example.org')
            ));

            $pdo->exec(sprintf(
                "INSERT INTO reports (uuid, submitting_operator, reporting_entity, assigned_operator, automated, incident_type, opened, message, updated) VALUES
                    ('%s', '%s', '%s', '%s', 0, 'SPAM', 1, 'A spam report', now()),
                    ('%s', '%s', null, null, 1, 'CSAM', 0, null, null)",
                self::SPAM_REPORT, self::PLAIN_OPERATOR, self::AUTHOR_ENTITY, self::AUTO_ASSIGN_OPERATOR,
                self::CSAM_REPORT, self::SYSTEM_OPERATOR
            ));

            $pdo->exec(sprintf(
                "INSERT INTO evidence (uuid, entity, operator, confidential, text_content, metadata, tag, report, classification_flag, note, updated) VALUES
                    ('%s', '%s', '%s', 0, 'Automatically reported content', null, 'auto', '%s', 'MALICIOUS', null, now()),
                    ('%s', '%s', '%s', 1, 'Submitted content', '{\"source\":\"test\"}', null, '%s', 'NORMAL', 'A note', null),
                    ('%s', '%s', '%s', 0, 'Unclassified content', null, null, null, null, null, null)",
                self::SYSTEM_EVIDENCE, self::CHILD_ENTITY, self::SYSTEM_OPERATOR, self::CSAM_REPORT,
                self::OPERATOR_EVIDENCE, self::AUTHOR_ENTITY, self::PLAIN_OPERATOR, self::SPAM_REPORT,
                self::UNCLASSIFIED_EVIDENCE, self::PARENT_ENTITY, self::AUTO_ASSIGN_OPERATOR
            ));

            $pdo->exec(sprintf(
                "INSERT INTO file_attachments (uuid, evidence, file_name, file_mime, file_size) VALUES ('%s', '%s', 'screenshot.png', 'image/png', 1024)",
                self::FILE_ATTACHMENT, self::OPERATOR_EVIDENCE
            ));

            $pdo->exec(sprintf(
                "INSERT INTO blacklist (uuid, operator, entity, report, type, lifted, lifted_by, expires) VALUES ('%s', '%s', '%s', '%s', 'CSAM', 0, null, null)",
                self::CSAM_BLACKLIST, self::SYSTEM_OPERATOR, self::CHILD_ENTITY, self::CSAM_REPORT
            ));

            $pdo->exec(sprintf(
                "INSERT INTO audit_log (uuid, operator, entity, blacklist, evidence, file_attachment, type, message) VALUES ('%s', '%s', '%s', '%s', '%s', '%s', 'ENTITY_BLACKLISTED', 'A legacy audit log entry')",
                self::AUDIT_LOG, self::SYSTEM_OPERATOR, self::CHILD_ENTITY, self::CSAM_BLACKLIST, self::SYSTEM_EVIDENCE, self::FILE_ATTACHMENT
            ));
        }

        /**
         * Upgrades the database the way DatabaseConnection::initializeDatabase() does: the tables that do not exist are
         * created from the current definitions, then the pending migrations are run in ascending order
         *
         * @param PDO $pdo The database to upgrade
         * @return string[] The versions of the migrations that were run
         */
        private static function upgrade(PDO $pdo): array
        {
            foreach(DatabaseTables::getOrderedTables() as $table)
            {
                $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
                $stmt->bindValue(':table', $table->getTableName());
                $stmt->execute();
                if($stmt->rowCount() === 0)
                {
                    $pdo->exec((string)file_get_contents($table->getPath()));
                }
            }

            $method = new ReflectionMethod(DatabaseConnection::class, 'getMigrations');
            $migrations = $method->invoke(null);
            uksort($migrations, 'version_compare');

            $current = (string)$pdo->query("SELECT value FROM database_metadata WHERE `key` = 'version'")->fetchColumn();
            $ran = [];
            foreach($migrations as $version => $migration)
            {
                if(version_compare($version, $current, '<=') || version_compare($version, DatabaseConnection::SCHEMA_VERSION, '>'))
                {
                    continue;
                }

                $migration($pdo);
                $stmt = $pdo->prepare("UPDATE database_metadata SET value = :version WHERE `key` = 'version'");
                $stmt->bindValue(':version', $version);
                $stmt->execute();
                $ran[] = (string)$version;
            }

            return $ran;
        }

        /**
         * Returns the columns of a database, keyed by table and column
         *
         * @param PDO $pdo The connection
         * @param string $database The database name
         * @return array<string, string> The definition of each column, without its comment
         */
        private static function getColumns(PDO $pdo, string $database): array
        {
            $stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :database');
            $stmt->bindValue(':database', $database);
            $stmt->execute();

            $columns = [];
            foreach($stmt->fetchAll() as $row)
            {
                $columns[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = sprintf(
                    '%s %s default=%s extra=%s charset=%s collation=%s',
                    $row['COLUMN_TYPE'], $row['IS_NULLABLE'] === 'YES' ? 'null' : 'not null', var_export($row['COLUMN_DEFAULT'], true),
                    $row['EXTRA'], var_export($row['CHARACTER_SET_NAME'], true), var_export($row['COLLATION_NAME'], true)
                );
            }

            return $columns;
        }

        /**
         * Returns the indexes of a database, keyed by table and index
         *
         * @param PDO $pdo The connection
         * @param string $database The database name
         * @return array<string, string> The columns of each index in their order, with their direction and uniqueness
         */
        private static function getIndexes(PDO $pdo, string $database): array
        {
            $stmt = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :database ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
            $stmt->bindValue(':database', $database);
            $stmt->execute();

            $indexes = [];
            foreach($stmt->fetchAll() as $row)
            {
                $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
                $indexes[$key] ??= ((int)$row['NON_UNIQUE'] === 0 ? 'unique' : 'index');
                $indexes[$key] .= sprintf(' %s %s', $row['COLUMN_NAME'], $row['COLLATION'] ?? '');
            }

            return $indexes;
        }

        /**
         * Returns the foreign keys of a database, keyed by table and constraint
         *
         * @param PDO $pdo The connection
         * @param string $database The database name
         * @return array<string, string> The columns, references and rules of each foreign key
         */
        private static function getForeignKeys(PDO $pdo, string $database): array
        {
            $stmt = $pdo->prepare(
                'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = :database AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
            );
            $stmt->bindValue(':database', $database);
            $stmt->execute();

            $foreignKeys = [];
            foreach($stmt->fetchAll() as $row)
            {
                $key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
                $foreignKeys[$key] = ($foreignKeys[$key] ?? '') . sprintf(
                    '%s -> %s.%s on update %s on delete %s; ',
                    $row['COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME'], $row['UPDATE_RULE'], $row['DELETE_RULE']
                );
            }

            return $foreignKeys;
        }

        /**
         * Compares the structure of the migrated database with the fresh one
         *
         * @param string $kind What is compared, used in the messages
         * @param array<string, string> $migrated The structure of the migrated database
         * @param array<string, string> $fresh The structure of the fresh database
         * @return string[] The differences
         */
        private static function compare(string $kind, array $migrated, array $fresh): array
        {
            $differences = [];
            foreach($fresh as $key => $definition)
            {
                if(!array_key_exists($key, $migrated))
                {
                    $differences[] = sprintf('%s: %s is missing from the migrated database', $kind, $key);
                }
                elseif($migrated[$key] !== $definition)
                {
                    $differences[] = sprintf('%s: %s is "%s" in the migrated database but "%s" in a fresh one', $kind, $key, $migrated[$key], $definition);
                }
            }

            foreach(array_diff_key($migrated, $fresh) as $key => $definition)
            {
                $differences[] = sprintf('%s: %s exists only in the migrated database', $kind, $key);
            }

            return $differences;
        }

        /**
         * Checks that the records of the 1.0.14 database were carried over by the migrations
         *
         * @param PDO $pdo The migrated database
         * @return string[] The errors
         */
        private static function checkData(PDO $pdo): array
        {
            $errors = [];
            $expect = function(string $description, mixed $expected, string $sql) use ($pdo, &$errors): void
            {
                try
                {
                    $actual = $pdo->query($sql)->fetchColumn();
                    if((string)$actual !== (string)$expected)
                    {
                        $errors[] = sprintf('%s: expected %s, got %s', $description, var_export($expected, true), var_export($actual, true));
                    }
                }
                catch(Throwable $e)
                {
                    $errors[] = sprintf('%s: %s', $description, $e->getMessage());
                }
            };

            // Every record survived
            foreach(['operators' => 3, 'entities' => 3, 'reports' => 2, 'evidence' => 3, 'file_attachments' => 1, 'blacklist' => 1, 'audit_log' => 1] as $table => $count)
            {
                $expect("The number of $table records", $count, "SELECT COUNT(*) FROM $table");
            }

            // The opt-in of 1.0.14 covered every report, it carries over to both kinds
            $expect('auto_assign_reports of the opted in operator', 1, sprintf("SELECT auto_assign_reports FROM operators WHERE uuid = '%s'", self::AUTO_ASSIGN_OPERATOR));
            $expect('auto_assign_automated_reports of the opted in operator', 1, sprintf("SELECT auto_assign_automated_reports FROM operators WHERE uuid = '%s'", self::AUTO_ASSIGN_OPERATOR));
            $expect('auto_assign_reports of the operator not opted in', 0, sprintf("SELECT auto_assign_reports FROM operators WHERE uuid = '%s'", self::PLAIN_OPERATOR));
            $expect('auto_assign_automated_reports of the operator not opted in', 0, sprintf("SELECT auto_assign_automated_reports FROM operators WHERE uuid = '%s'", self::PLAIN_OPERATOR));
            $expect('No operator is a bot', 0, 'SELECT COUNT(*) FROM operators WHERE is_bot = 1');
            $expect('The permissions of the opted in operator', '1,1,0', sprintf("SELECT CONCAT_WS(',', client_permissions, management_permissions, operator_permissions) FROM operators WHERE uuid = '%s'", self::AUTO_ASSIGN_OPERATOR));
            $expect('The disabled operator', 1, sprintf("SELECT disabled FROM operators WHERE uuid = '%s'", self::PLAIN_OPERATOR));

            // Evidence the host created itself is automated, its confidentiality is left as it was
            $expect('automated of the evidence of the system operator', 1, sprintf("SELECT automated FROM evidence WHERE uuid = '%s'", self::SYSTEM_EVIDENCE));
            $expect('confidential of the evidence of the system operator', 0, sprintf("SELECT confidential FROM evidence WHERE uuid = '%s'", self::SYSTEM_EVIDENCE));
            $expect('automated of the evidence of an operator', 0, sprintf("SELECT automated FROM evidence WHERE uuid = '%s'", self::OPERATOR_EVIDENCE));
            $expect('confidential of the evidence of an operator', 1, sprintf("SELECT confidential FROM evidence WHERE uuid = '%s'", self::OPERATOR_EVIDENCE));
            $expect('Every evidence record is TEXT content', 3, "SELECT COUNT(*) FROM evidence WHERE content_type = 'TEXT' AND context IS NULL");
            $expect('The text content of evidence', 'Submitted content', sprintf("SELECT text_content FROM evidence WHERE uuid = '%s'", self::OPERATOR_EVIDENCE));

            // The time of earlier classifications is approximated by the last update
            $expect('classified of updated classified evidence', 1, sprintf("SELECT classified = updated FROM evidence WHERE uuid = '%s'", self::SYSTEM_EVIDENCE));
            $expect('classified of never updated classified evidence', 1, sprintf("SELECT classified = created FROM evidence WHERE uuid = '%s'", self::OPERATOR_EVIDENCE));
            $expect('classified of unclassified evidence', 1, sprintf("SELECT classified IS NULL FROM evidence WHERE uuid = '%s'", self::UNCLASSIFIED_EVIDENCE));

            // The incident types of 1.0.14 keep their values after the new ones were appended to the enums
            $expect('The incident type of the CSAM report', 'CSAM', sprintf("SELECT incident_type FROM reports WHERE uuid = '%s'", self::CSAM_REPORT));
            $expect('The incident type of the SPAM report', 'SPAM', sprintf("SELECT incident_type FROM reports WHERE uuid = '%s'", self::SPAM_REPORT));
            $expect('The type of the CSAM blacklist record', 'CSAM', sprintf("SELECT type FROM blacklist WHERE uuid = '%s'", self::CSAM_BLACKLIST));
            $expect('The assigned operator of the SPAM report', self::AUTO_ASSIGN_OPERATOR, sprintf("SELECT assigned_operator FROM reports WHERE uuid = '%s'", self::SPAM_REPORT));

            // The new audit log column is empty, the references of the entry are kept
            $expect('report of the legacy audit log entry', 1, sprintf("SELECT report IS NULL FROM audit_log WHERE uuid = '%s'", self::AUDIT_LOG));
            $expect('The references of the legacy audit log entry', implode(',', [self::SYSTEM_OPERATOR, self::CHILD_ENTITY, self::CSAM_BLACKLIST, self::SYSTEM_EVIDENCE, self::FILE_ATTACHMENT]),
                sprintf("SELECT CONCAT_WS(',', operator, entity, blacklist, evidence, file_attachment) FROM audit_log WHERE uuid = '%s'", self::AUDIT_LOG));

            // Entities, their relationships and reputation are untouched
            $expect('The relationship of the child entity', self::PARENT_ENTITY . ',CHILD', sprintf("SELECT CONCAT_WS(',', relationship_entity, relationship_type) FROM entities WHERE uuid = '%s'", self::CHILD_ENTITY));
            $expect('The reputation of the parent entity', 120, sprintf("SELECT reputation FROM entities WHERE uuid = '%s'", self::PARENT_ENTITY));
            $expect('The reputation of the child entity', -30, sprintf("SELECT reputation FROM entities WHERE uuid = '%s'", self::CHILD_ENTITY));
            $expect('No reputation activity exists', 0, 'SELECT COUNT(*) FROM entity_activity');

            // The new columns and enum values accept the values of the current release
            try
            {
                $pdo->exec(sprintf("UPDATE reports SET incident_type = 'ADVERTISING' WHERE uuid = '%s'", self::SPAM_REPORT));
                $pdo->exec(sprintf("UPDATE blacklist SET type = 'TRACKING' WHERE uuid = '%s'", self::CSAM_BLACKLIST));
                $pdo->exec(sprintf("UPDATE evidence SET content_type = 'SMS', context = '{\"region\":\"US\"}' WHERE uuid = '%s'", self::UNCLASSIFIED_EVIDENCE));
                $pdo->exec(sprintf("INSERT INTO entity_activity (entity, day, normal_scans) VALUES ('%s', utc_date(), 1)", self::AUTHOR_ENTITY));
                $pdo->exec(sprintf("INSERT INTO entity_mention_authors (entity, day, author) VALUES ('%s', utc_date(), '%s')", self::PARENT_ENTITY, self::AUTHOR_ENTITY));
            }
            catch(Throwable $e)
            {
                $errors[] = 'Writing the values of the current release: ' . $e->getMessage();
            }

            // A migration is safe to run again if an upgrade was interrupted
            try
            {
                $method = new ReflectionMethod(DatabaseConnection::class, 'getMigrations');
                foreach($method->invoke(null) as $version => $migration)
                {
                    if(version_compare((string)$version, LegacySchema::VERSION, '>'))
                    {
                        $migration($pdo);
                    }
                }
            }
            catch(Throwable $e)
            {
                $errors[] = 'Running the migrations again: ' . $e->getMessage();
            }

            $expect('auto_assign_automated_reports after running the migrations again', 1, sprintf("SELECT auto_assign_automated_reports FROM operators WHERE uuid = '%s'", self::AUTO_ASSIGN_OPERATOR));
            return $errors;
        }
    }
