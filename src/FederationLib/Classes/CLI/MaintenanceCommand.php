<?php

    namespace FederationLib\Classes\CLI;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DnsDetails;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;
    use FederationLib\Objects\Plugin\Maintenance;
    use RuntimeException;

    class MaintenanceCommand implements CommandLineInterface
    {
        private const int BATCH_SIZE = 1000;

        /**
         * The maximum number of domain entities resolved per maintenance run
         */
        private const int DNS_REFRESH_LIMIT = 500;

        /**
         * @inheritDoc
         */
        public static function handle(array $tokens): int
        {
            $arguments = Arguments::parse($tokens, ['archive']);
            $startedAt = time();

            $configuration = Configuration::getMaintenanceConfiguration();
            if(!$configuration->isEnabled())
            {
                // Nothing to clean is not a failure, the command is run on a schedule regardless of the configuration;
                // plugins still do their own periodic work
                print("Maintenance is not enabled, nothing to clean.\n");
                return PluginManager::dispatchMaintenance(new Maintenance($startedAt, false, null, [])) ? 0 : 1;
            }

            $archivePath = null;
            if($arguments->has('archive'))
            {
                $archive = $arguments->option('archive');
                if($archive === null || trim($archive) === '')
                {
                    print("Error: --archive requires a directory\n");
                    return 1;
                }

                $archivePath = rtrim($archive, DIRECTORY_SEPARATOR);
                if(!is_dir($archivePath))
                {
                    if(!@mkdir($archivePath, 0755, true) && !is_dir($archivePath))
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

                print("Archiving removed records to: $archivePath\n");
            }

            // Each task runs even if a previous one failed, a record is only removed after the records depending on it
            // were removed by their own task (eg; an entity is only removed once it has no evidence, reports or
            // blacklist records left), so a failed task can't cause the removal of records it should have kept
            $tasks = [
                'file attachment' => [$configuration->isCleanFileAttachmentsEnabled(), $configuration->getCleanFileAttachmentsTtl(), self::cleanFileAttachments(...)],
                'evidence' => [$configuration->isCleanEvidenceEnabled(), $configuration->getCleanEvidenceTtl(), self::cleanEvidence(...)],
                'report' => [$configuration->isCleanReportsEnabled(), $configuration->getCleanReportsTtl(), self::cleanReports(...)],
                'blacklist' => [$configuration->isCleanBlacklistEnabled(), $configuration->getCleanBlacklistTtl(), self::cleanBlacklist(...)],
                'entity' => [$configuration->isCleanEntitiesEnabled(), $configuration->getCleanEntitiesTtl(), self::cleanEntities(...)],
                'audit log' => [$configuration->isCleanAuditLogsEnabled(), $configuration->getCleanAuditLogsTtl(), self::cleanAuditLogs(...)],
            ];

            $exitCode = 0;
            $removed = [];
            foreach($tasks as $name => [$enabled, $ttl, $task])
            {
                if(!$enabled)
                {
                    continue;
                }

                if($ttl <= 0)
                {
                    print("Error: The TTL of $name records must be greater than zero, skipping\n");
                    $exitCode = 1;
                    continue;
                }

                // The same point in time is used to find and delete the old records of a task
                $before = time() - $ttl;
                print(sprintf("Cleaning %s records older than %s...\n", $name, date('Y-m-d H:i:s', $before)));

                try
                {
                    $cleaned = $task($before, $archivePath);
                    $removed[$name] = $cleaned;
                    print(sprintf("Cleaned %d %s record(s)\n", $cleaned, $name));
                }
                catch(Exception $e)
                {
                    Logger::log()->error(sprintf('Failed to clean %s records: %s', $name, $e->getMessage()), $e);
                    print(sprintf("Error: Failed to clean %s records: %s\n", $name, $e->getMessage()));
                    $exitCode = 1;
                }
            }

            // The DNS details of the domain entities that remain are refreshed once their interval passed
            if($configuration->isDnsRefreshEnabled())
            {
                $before = time() - $configuration->getDnsRefresh();
                print(sprintf("Refreshing the DNS details of domain entities resolved before %s...\n", date('Y-m-d H:i:s', $before)));

                try
                {
                    [$refreshed, $remaining] = self::refreshDnsDetails($before);
                    print(sprintf("Refreshed the DNS details of %d domain entit%s%s\n", $refreshed, $refreshed === 1 ? 'y' : 'ies',
                        $remaining ? ', more remain for the next run' : ''));
                }
                catch(Exception $e)
                {
                    Logger::log()->error(sprintf('Failed to refresh the DNS details of domain entities: %s', $e->getMessage()), $e);
                    print(sprintf("Error: Failed to refresh the DNS details of domain entities: %s\n", $e->getMessage()));
                    $exitCode = 1;
                }
            }

            // Plugins do their own periodic work after FederationLib cleaned its records
            if(!PluginManager::dispatchMaintenance(new Maintenance($startedAt, true, $archivePath, $removed)))
            {
                print("Error: A plugin failed its maintenance, see the logs for details\n");
                $exitCode = 1;
            }

            return $exitCode;
        }

        /**
         * Resolves the domain entities whose DNS details are missing or were resolved before the given time, and stores
         * the details in their metadata (see DnsDetails). At most DNS_REFRESH_LIMIT names are resolved per run, as each
         * lookup may wait on the resolver; the remaining entities are refreshed by the next runs, those never
         * resolved first.
         *
         * @param int $before The Unix timestamp before which DNS details are refreshed
         * @return array{0: int, 1: bool} The number of refreshed entities, and whether more entities are due
         * @throws DatabaseOperationException If the entities cannot be retrieved or updated
         */
        private static function refreshDnsDetails(int $before): array
        {
            $entities = EntitiesManager::getDnsRefreshCandidates($before, self::DNS_REFRESH_LIMIT + 1);
            $remaining = count($entities) > self::DNS_REFRESH_LIMIT;

            $refreshed = 0;
            foreach(array_slice($entities, 0, self::DNS_REFRESH_LIMIT) as $entity)
            {
                // Hosts are canonical, but only DNS names are resolved
                if(!Validate::dnsName($entity->getHost()))
                {
                    continue;
                }

                if(EntitiesManager::setDnsDetails($entity, DnsDetails::lookup($entity->getHost())))
                {
                    $refreshed++;
                    continue;
                }

                Logger::log()->warning(sprintf('The metadata of entity %s has no room for its DNS details', $entity->getUuid()));
            }

            return [$refreshed, $remaining];
        }

        /**
         * Removes old records in batches, each batch is archived (when archiving) before it's deleted. A batch that
         * can't be archived is not deleted.
         *
         * @param callable(): array[] $fetch Returns the next batch of old records as raw record data
         * @param callable(array[]): void $archive Archives a batch of records
         * @param callable(string[]): int $purge Deletes the records with the given UUIDs and returns how many it deleted
         * @return int The number of deleted records
         */
        private static function cleanInBatches(callable $fetch, callable $archive, callable $purge): int
        {
            $cleaned = 0;
            while(!empty($records = $fetch()))
            {
                $archive($records);
                $purged = $purge(array_column($records, 'uuid'));
                $cleaned += $purged;

                // The records of the batch changed since they were retrieved and are no longer old, they would be
                // retrieved again. The remaining records are cleaned up by the next run.
                if($purged === 0)
                {
                    break;
                }
            }

            return $cleaned;
        }

        /**
         * Removes file attachments created before the given time, along with their stored files.
         */
        private static function cleanFileAttachments(int $before, ?string $archivePath): int
        {
            return self::cleanInBatches(
                fn() => FileAttachmentManager::getOldRecords($before, self::BATCH_SIZE),
                fn(array $records) => self::archiveRecords($archivePath, 'file_attachments', $records),
                fn(array $uuids) => FileAttachmentManager::purgeRecords($uuids, $before)
            );
        }

        /**
         * Removes evidence created before the given time, the file attachments of the evidence are removed with it.
         */
        private static function cleanEvidence(int $before, ?string $archivePath): int
        {
            return self::cleanInBatches(
                fn() => EvidenceManager::getOldRecords($before, self::BATCH_SIZE),
                function(array $records) use ($archivePath)
                {
                    self::archiveRecords($archivePath, 'evidence', $records);
                    if($archivePath !== null)
                    {
                        self::archiveRecords($archivePath, 'file_attachments', FileAttachmentManager::getRawRecordsByEvidence(array_column($records, 'uuid')));
                    }
                },
                fn(array $uuids) => EvidenceManager::purgeRecords($uuids, $before)
            );
        }

        /**
         * Removes closed reports last changed before the given time.
         */
        private static function cleanReports(int $before, ?string $archivePath): int
        {
            return self::cleanInBatches(
                fn() => ReportManager::getOldRecords($before, self::BATCH_SIZE),
                fn(array $records) => self::archiveRecords($archivePath, 'reports', $records),
                fn(array $uuids) => ReportManager::purgeRecords($uuids, $before)
            );
        }

        /**
         * Removes blacklist records no longer in effect since before the given time.
         */
        private static function cleanBlacklist(int $before, ?string $archivePath): int
        {
            return self::cleanInBatches(
                fn() => BlacklistManager::getOldRecords($before, self::BATCH_SIZE),
                fn(array $records) => self::archiveRecords($archivePath, 'blacklist', $records),
                fn(array $uuids) => BlacklistManager::purgeRecords($uuids, $before)
            );
        }

        /**
         * Removes entities last changed before the given time that have no evidence, reports or blacklist records.
         */
        private static function cleanEntities(int $before, ?string $archivePath): int
        {
            return self::cleanInBatches(
                fn() => EntitiesManager::getOldRecords($before, self::BATCH_SIZE),
                fn(array $records) => self::archiveRecords($archivePath, 'entities', $records),
                fn(array $uuids) => EntitiesManager::purgeRecords($uuids, $before)
            );
        }

        /**
         * Removes audit log entries created before the given time.
         */
        private static function cleanAuditLogs(int $before, ?string $archivePath): int
        {
            return self::cleanInBatches(
                fn() => AuditLogManager::getOldRecords($before, self::BATCH_SIZE),
                fn(array $records) => self::archiveRecords($archivePath, 'audit_log', $records),
                fn(array $uuids) => AuditLogManager::purgeRecords($uuids, $before)
            );
        }

        /**
         * Appends raw database records to the CSV archive file of their table, named <table>_YYYY-MM-DD.csv in the
         * archive directory. The header row (the column names) is written when the file is created. Does nothing
         * when not archiving.
         *
         * @param string|null $archivePath The directory to store archive files in, null when not archiving
         * @param string $table The database table of the records (the filename prefix)
         * @param array[] $records The raw records as associative arrays of their columns
         * @throws RuntimeException If the records could not be written, the records must not be deleted then
         */
        private static function archiveRecords(?string $archivePath, string $table, array $records): void
        {
            if($archivePath === null || empty($records))
            {
                return;
            }

            $filename = $archivePath . DIRECTORY_SEPARATOR . $table . '_' . date('Y-m-d') . '.csv';
            $handle = @fopen($filename, 'a');
            if($handle === false)
            {
                throw new RuntimeException("Failed to open archive file for writing: $filename");
            }

            try
            {
                $rows = [];
                // fstat() rather than filesize(), which is cached and would repeat the header for every batch
                if(fstat($handle)['size'] === 0)
                {
                    $rows[] = array_keys($records[0]);
                }

                foreach($records as $record)
                {
                    $rows[] = array_values($record);
                }

                foreach($rows as $row)
                {
                    if(fputcsv($handle, $row, escape: '') === false)
                    {
                        throw new RuntimeException("Failed to write to archive file: $filename");
                    }
                }

                if(!fflush($handle))
                {
                    throw new RuntimeException("Failed to write to archive file: $filename");
                }
            }
            finally
            {
                fclose($handle);
            }
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationlib maintenance [--archive <directory>]\n" .
                "\nDescription:\n" .
                "  Removes records that are older than their configured retention (see the maintenance\n" .
                "  configuration): file attachments (and their files), evidence, closed reports, blacklist\n" .
                "  records no longer in effect, entities without any evidence, reports or blacklist records,\n" .
                "  and audit log entries. Blacklist records in effect, open reports and whitelisted entities\n" .
                "  are never removed. Then resolves the domain entities whose DNS details are missing or older\n" .
                "  than maintenance.dns_refresh (90 days by default) and stores their A, AAAA, CNAME, MX and NS\n" .
                "  records in their metadata (dns_* keys), at most " . self::DNS_REFRESH_LIMIT . " per run. Does nothing if maintenance\n" .
                "  is disabled.\n" .
                "\nOptions:\n" .
                "  --archive <directory>  Archive removed records as CSV files in the given directory before\n" .
                "                         they are removed, the directory is created if it does not exist.\n" .
                "                         Each table gets its own file named <table>_YYYY-MM-DD.csv.\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Removes records older than their configured retention and refreshes the DNS details of domain entities.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Example:\n" .
                "  federationlib maintenance\n" .
                "  federationlib maintenance --archive /var/backups/federation\n" .
                "\nThe --archive option saves removed records as CSV files before they are removed.\n";
        }
    }
