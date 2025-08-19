<?php

    namespace FederationLib\Enums;

    use FederationLib\Classes\Logger;

    enum DatabaseTables : string
    {
        case AUDIT_LOG = 'audit_log.sql';
        case BLACKLIST = 'blacklist.sql';
        case ENTITIES = 'entities.sql';
        case EVIDENCE  = 'evidence.sql';
        case FILE_ATTACHMENTS = 'file_attachments.sql';
        case OPERATORS = 'operators.sql';

        /**
         * Returns the full path to the SQL file for the database table.
         *
         * @return string The full path to the SQL file. (Realpath)
         */
        public function getPath(): string
        {
            return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Classes' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . $this->value);
        }

        /**
         * Returns the name of the database table.
         *
         * @return string The name of the database table.
         */
        public function getTableName(): string
        {
            return match($this)
            {
                self::AUDIT_LOG => 'audit_log',
                self::BLACKLIST => 'blacklist',
                self::ENTITIES => 'entities',
                self::EVIDENCE => 'evidence',
                self::FILE_ATTACHMENTS => 'file_attachments',
                self::OPERATORS => 'operators',
            };
        }

        /**
         * Returns an array of DatabaseTables in the order they should be processed or created.
         *
         * @return DatabaseTables[]
         */
        public static function getOrderedTables(): array
        {
            return [
                self::OPERATORS,
                self::ENTITIES,
                self::AUDIT_LOG,
                self::EVIDENCE,
                self::FILE_ATTACHMENTS,
                self::BLACKLIST
            ];
        }
    }
