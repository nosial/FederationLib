<?php

    namespace FederationServer\Classes;

    use PDO;
    use PDOException;

    class DatabaseConnection
    {
        private static ?PDO $pdo = null;

        /**
         * Get the PDO connection instance. If it does not exist, create it using the configuration.
         *
         * @return PDO
         * @throws PDOException
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
    }
