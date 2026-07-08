<?php

    namespace FederationLib\Classes\CLI;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Interfaces\CommandLineInterface;

    class MaintenanceCommand implements CommandLineInterface
    {
        private const int PAGE_SIZE = 1000;

        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            if(!Configuration::getMaintenanceConfiguration()->isEnabled())
            {
                print("Maintenance mode is not enabled.\n");
                return 1;
            }

            $archivePath = null;
            if(isset($args['archive']) && is_string($args['archive']))
            {
                $archivePath = rtrim($args['archive'], DIRECTORY_SEPARATOR);
                print("Archive directory: $archivePath\n");

                if(!is_dir($archivePath))
                {
                    if(!@mkdir($archivePath, 0755, true))
                    {
                        print("Error: Failed to create archive directory: $archivePath\n");
                        return 1;
                    }
                    print("Created archive directory: $archivePath\n");
                }

                if(!is_writable($archivePath))
                {
                    print("Error: Archive directory is not writable: $archivePath\n");
                    return 1;
                }
            }

            // Process file attachments first (leaf node, depends on evidence)
            if(Configuration::getMaintenanceConfiguration()->isCleanFileAttachmentsEnabled())
            {
                $exitCode = self::cleanFileAttachments($archivePath);
                if($exitCode !== 0)
                {
                    return $exitCode;
                }
            }

            // Process evidence (depends on entities, has FK to reports SET NULL)
            if(Configuration::getMaintenanceConfiguration()->isCleanEvidenceEnabled())
            {
                $exitCode = self::cleanEvidence($archivePath);
                if($exitCode !== 0)
                {
                    return $exitCode;
                }
            }

            // Process reports (depends on entities)
            if(Configuration::getMaintenanceConfiguration()->isCleanReportsEnabled())
            {
                $exitCode = self::cleanReports($archivePath);
                if($exitCode !== 0)
                {
                    return $exitCode;
                }
            }

            // Process blacklist (depends on entities, evidence)
            if(Configuration::getMaintenanceConfiguration()->isCleanBlacklistEnabled())
            {
                $exitCode = self::cleanBlacklist($archivePath);
                if($exitCode !== 0)
                {
                    return $exitCode;
                }
            }

            // Process entities (disabled by default, depends on no other table)
            if(Configuration::getMaintenanceConfiguration()->isCleanEntitiesEnabled())
            {
                $exitCode = self::cleanEntities($archivePath);
                if($exitCode !== 0)
                {
                    return $exitCode;
                }
            }

            // Process audit logs (no FK dependencies for deletion)
            if(Configuration::getMaintenanceConfiguration()->isCleanAuditLogsEnabled())
            {
                $exitCode = self::cleanAuditLogs($archivePath);
                if($exitCode !== 0)
                {
                    return $exitCode;
                }
            }

            return 0;
        }

        private static function cleanFileAttachments(?string $archivePath): int
        {
            $ttl = Configuration::getMaintenanceConfiguration()->getCleanFileAttachmentsTtl();
            print("Cleaning file attachments older than threshold...\n");

            try
            {
                $page = 1;

                do
                {
                    $records = FileAttachmentManager::getOldRecords($ttl, self::PAGE_SIZE, $page);
                    if(empty($records))
                    {
                        break;
                    }

                    if($archivePath !== null)
                    {
                        foreach($records as $record)
                        {
                            self::archiveRecord($archivePath, 'file_attachments', $record);
                        }
                    }

                    $page++;
                }
                while(count($records) === self::PAGE_SIZE);

                $cleaned = FileAttachmentManager::cleanEntries($ttl);

                if($cleaned > 0)
                {
                    print("Cleaned $cleaned file attachment(s).\n");
                }
                else
                {
                    print("No file attachments were cleaned.\n");
                }
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to clean file attachments: ' . $e->getMessage(), $e);
                print("Error: Failed to clean file attachments. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * Archives old evidence records to CSV and removes them.
         * Also archives associated file attachments and blacklist records.
         */
        private static function cleanEvidence(?string $archivePath): int
        {
            $ttl = Configuration::getMaintenanceConfiguration()->getCleanEvidenceTtl();
            print("Cleaning evidence records older than threshold...\n");

            try
            {
                $page = 1;
                $evidenceUuids = [];

                do
                {
                    $records = EvidenceManager::getOldRecords($ttl, self::PAGE_SIZE, $page);
                    if(empty($records))
                    {
                        break;
                    }

                    if($archivePath !== null)
                    {
                        foreach($records as $record)
                        {
                            $evidenceUuids[] = $record['uuid'];
                            self::archiveRecord($archivePath, 'evidence', $record);
                        }
                    }

                    $page++;
                }
                while(count($records) === self::PAGE_SIZE);

                if($archivePath !== null && !empty($evidenceUuids))
                {
                    // Archive file attachments that will be cascade-deleted when their parent evidence is removed.
                    foreach($evidenceUuids as $evUuid)
                    {
                        $attachments = FileAttachmentManager::getRecordsByEvidence($evUuid);
                        foreach($attachments as $attachment)
                        {
                            self::archiveRecord($archivePath, 'file_attachments', $attachment->toArray());
                        }

                        $blacklistEntries = BlacklistManager::getEntriesByEvidence($evUuid);
                        foreach($blacklistEntries as $entry)
                        {
                            self::archiveRecord($archivePath, 'blacklist', $entry->toArray());
                        }
                    }
                }

                $cleaned = EvidenceManager::cleanEntries($ttl);

                if($cleaned > 0)
                {
                    print("Cleaned $cleaned evidence record(s).\n");
                }
                else
                {
                    print("No evidence records were cleaned.\n");
                }
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to clean evidence records: ' . $e->getMessage(), $e);
                print("Error: Failed to clean evidence records. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * Archives old report records to CSV and removes them.
         */
        private static function cleanReports(?string $archivePath): int
        {
            $ttl = Configuration::getMaintenanceConfiguration()->getCleanReportsTtl();
            print("Cleaning reports older than threshold...\n");

            try
            {
                $page = 1;

                do
                {
                    $records = ReportManager::getOldRecords($ttl, self::PAGE_SIZE, $page);
                    if(empty($records))
                    {
                        break;
                    }

                    if($archivePath !== null)
                    {
                        foreach($records as $record)
                        {
                            self::archiveRecord($archivePath, 'reports', $record);
                        }
                    }

                    $page++;
                }
                while(count($records) === self::PAGE_SIZE);

                $cleaned = ReportManager::cleanEntries($ttl);

                if($cleaned > 0)
                {
                    print("Cleaned $cleaned report(s).\n");
                }
                else
                {
                    print("No reports were cleaned.\n");
                }
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to clean reports: ' . $e->getMessage(), $e);
                print("Error: Failed to clean reports. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * Archives old blacklist entries to CSV and removes them.
         */
        private static function cleanBlacklist(?string $archivePath): int
        {
            $ttl = Configuration::getMaintenanceConfiguration()->getCleanBlacklistTtl();
            print("Cleaning blacklist entries older than threshold...\n");

            try
            {
                if($archivePath !== null)
                {
                    // Fetch old records before deletion for archiving
                    $page = 1;
                    do
                    {
                        $records = BlacklistManager::getEntries(self::PAGE_SIZE, $page, true);
                        if(empty($records))
                        {
                            break;
                        }

                        $threshold = time() - $ttl;
                        foreach($records as $record)
                        {
                            $recordCreated = $record->getCreated();
                            $recordExpires = $record->getExpires();
                            $isExpired = ($recordExpires !== null && $recordExpires < $threshold) ||
                                         ($recordExpires === null && $recordCreated < $threshold);
                            if($isExpired)
                            {
                                self::archiveRecord($archivePath, 'blacklist', $record->toArray());
                            }
                        }

                        $page++;
                    }
                    while(count($records) === self::PAGE_SIZE);
                }

                $cleaned = BlacklistManager::cleanEntries($ttl);

                if($cleaned > 0)
                {
                    print("Cleaned $cleaned blacklist entrie(s).\n");
                }
                else
                {
                    print("No blacklist entries were cleaned.\n");
                }
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to clean blacklist entries: ' . $e->getMessage(), $e);
                print("Error: Failed to clean blacklist entries. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * Archives old entity records to CSV and removes them.
         */
        private static function cleanEntities(?string $archivePath): int
        {
            $ttl = Configuration::getMaintenanceConfiguration()->getCleanEntitiesTtl();
            print("Cleaning entity records older than threshold...\n");

            try
            {
                $page = 1;
                $entityUuids = [];

                do
                {
                    $records = EntitiesManager::getOldRecords($ttl, self::PAGE_SIZE, $page);
                    if(empty($records))
                    {
                        break;
                    }

                    if($archivePath !== null)
                    {
                        foreach($records as $record)
                        {
                            $entityUuids[] = $record['uuid'];
                            self::archiveRecord($archivePath, 'entities', $record);
                        }
                    }

                    $page++;
                }
                while(count($records) === self::PAGE_SIZE);

                if($archivePath !== null && !empty($entityUuids))
                {
                    // Archive child records that will be cascade-deleted when their parent entity is removed.
                    foreach($entityUuids as $entUuid)
                    {
                        // Archive reports
                        $reportPage = 1;
                        do
                        {
                            $reports = ReportManager::getReportsByReportingEntity($entUuid, self::PAGE_SIZE, $reportPage);
                            foreach($reports as $report)
                            {
                                self::archiveRecord($archivePath, 'reports', $report->toArray());
                            }
                            $reportPage++;
                        }
                        while(count($reports) === self::PAGE_SIZE);

                        // Archive evidence and their file attachments
                        $evPage = 1;
                        do
                        {
                            $evidenceRecords = EvidenceManager::getEvidenceByEntity($entUuid, self::PAGE_SIZE, $evPage, true);
                            foreach($evidenceRecords as $evRecord)
                            {
                                // Archive file attachments for this evidence before the evidence is cascade-deleted
                                $attachments = FileAttachmentManager::getRecordsByEvidence($evRecord->getUuid());
                                foreach($attachments as $attachment)
                                {
                                    self::archiveRecord($archivePath, 'file_attachments', $attachment->toArray());
                                }

                                self::archiveRecord($archivePath, 'evidence', $evRecord->toArray());
                            }
                            $evPage++;
                        }
                        while(count($evidenceRecords) === self::PAGE_SIZE);

                        // Archive blacklist records
                        $blPage = 1;
                        do
                        {
                            $blacklistEntries = BlacklistManager::getEntriesByEntity($entUuid, self::PAGE_SIZE, $blPage, true);
                            foreach($blacklistEntries as $entry)
                            {
                                self::archiveRecord($archivePath, 'blacklist', $entry->toArray());
                            }
                            $blPage++;
                        }
                        while(count($blacklistEntries) === self::PAGE_SIZE);
                    }
                }

                $cleaned = EntitiesManager::cleanEntries($ttl);

                if($cleaned > 0)
                {
                    print("Cleaned $cleaned entitie(s).\n");
                }
                else
                {
                    print("No entity records were cleaned.\n");
                }
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to clean entity records: ' . $e->getMessage(), $e);
                print("Error: Failed to clean entity records. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * Archives old audit log entries to CSV and removes them.
         */
        private static function cleanAuditLogs(?string $archivePath): int
        {
            $ttl = Configuration::getMaintenanceConfiguration()->getCleanAuditLogsTtl();
            print("Cleaning audit logs older than threshold...\n");

            try
            {
                if($archivePath !== null)
                {
                    // Fetch old entries before deletion for archiving
                    $page = 1;
                    do
                    {
                        $records = AuditLogManager::getEntries(self::PAGE_SIZE, $page);
                        if(empty($records))
                        {
                            break;
                        }

                        $threshold = time() - $ttl;
                        foreach($records as $record)
                        {
                            if($record->getTimestamp() < $threshold)
                            {
                                self::archiveRecord($archivePath, 'audit_log', $record->toArray());
                            }
                        }

                        $page++;
                    }
                    while(count($records) === self::PAGE_SIZE);
                }

                $cleaned = AuditLogManager::cleanEntries($ttl);

                if($cleaned > 0)
                {
                    print("Cleaned $cleaned audit log entrie(s).\n");
                }
                else
                {
                    print("No audit log entries were cleaned.\n");
                }
            }
            catch(Exception $e)
            {
                Logger::log()->error('Failed to clean audit logs: ' . $e->getMessage(), $e);
                print("Error: Failed to clean audit logs. See logs for details.\n");
                return 1;
            }

            return 0;
        }

        /**
         * Appends a record to a CSV archive file.
         *
         * The file is named <table>_YYYY-MM-DD.csv and is created in the archive directory.
         * If the file does not exist, the header row is written first.
         *
         * @param string $archivePath The directory to store archive files in
         * @param string $table The database table name (used for the filename prefix)
         * @param array $record The record data as an associative array
         */
        private static function archiveRecord(string $archivePath, string $table, array $record): void
        {
            $date = date('Y-m-d');
            $filename = $archivePath . DIRECTORY_SEPARATOR . $table . '_' . $date . '.csv';
            $isNewFile = !file_exists($filename);

            $handle = fopen($filename, 'a');
            if($handle === false)
            {
                Logger::log()->error("Failed to open archive file for writing: $filename");
                print("Error: Failed to open archive file for writing: $filename\n");
                return;
            }

            if($isNewFile)
            {
                fputcsv($handle, array_keys($record));
            }

            fputcsv($handle, array_values($record));
            fclose($handle);
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationserver maintenance [--archive <directory>]\n" .
                "\nDescription:\n" .
                "  Runs maintenance tasks such as cleaning old records based on configuration.\n" .
                "  When --archive is provided, removed records are saved as CSV files in the\n" .
                "  specified directory before deletion. The directory will be created if it\n" .
                "  does not exist.\n" .
                "\nOptions:\n" .
                "  --archive <directory>  Archive removed records as CSV files in the given\n" .
                "                         directory. Each table gets its own file named\n" .
                "                         <table>_YYYY-MM-DD.csv.\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Runs maintenance tasks such as cleaning old records if enabled in the configuration.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Example:\n" .
                "  federationserver maintenance\n" .
                "  federationserver maintenance --archive /var/backups/federation\n" .
                "\nThe --archive flag saves removed records as CSV files before deletion.\n";
        }
    }
