<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\Categories\ReportCategory;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\ReportOrderType;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;

    class ReportManager
    {
        public const string CACHE_PREFIX = 'report:';

        /**
         * Creates a new report record in the database.
         *
         * @param string $submittingOperator The UUID of the operator submitting the report.
         * @param string|null $reportingEntity Optional UUID of the entity submitting the report.
         * @param IncidentType $type The incident type for the report.
         * @param string|null $message Optional message attached to the report.
         * @param bool $automated Whether the report was automatically generated.
         * @return string The UUID of the newly created report.
         * @throws InvalidArgumentException If the submitting operator is not provided.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function createReport(string $submittingOperator, ?string $reportingEntity, IncidentType $type, ?string $message=null, bool $automated=false): string
        {
            if(strlen($submittingOperator) < 1)
            {
                throw new InvalidArgumentException('Submitting operator must be provided.');
            }

            if(!Validate::uuid($submittingOperator))
            {
                throw new InvalidArgumentException('Invalid submitting operator UUID');
            }

            if($reportingEntity !== null && !Validate::uuid($reportingEntity))
            {
                throw new InvalidArgumentException('Invalid reporting entity UUID');
            }

            if($message !== null && strlen($message) === 0)
            {
                throw new InvalidArgumentException('Message cannot be empty if provided');
            }

            $uuid = Uuid::v7()->toRfc4122();
            $incidentType = $type->value;
            // Automated reports go to operators that opt in to automated reports, any other report to operators that
            // opt in to ordinary reports (OFD "Reports")
            $assignedOperator = OperatorManager::getNextAutoAssignOperator($automated);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "INSERT INTO reports (uuid, submitting_operator, reporting_entity, automated, incident_type, message) 
                     VALUES (:uuid, :submitting_operator, :reporting_entity, :automated, :incident_type, :message)"
                );
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':submitting_operator', $submittingOperator);
                $stmt->bindParam(':reporting_entity', $reportingEntity);
                $stmt->bindParam(':automated', $automated, PDO::PARAM_BOOL);
                $stmt->bindParam(':incident_type', $incidentType);
                $stmt->bindParam(':message', $message);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to create report: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled())
            {
                RedisConnection::clearSearchCache(self::CACHE_PREFIX);
            }

            PluginManager::dispatchRecordChange(RecordChangeType::REPORT_CREATED, $uuid);

            if($assignedOperator !== null)
            {
                self::assignOperator($uuid, $assignedOperator->getUuid());
            }

            return $uuid;
        }

        /**
         * Retrieves a specific report record by its UUID.
         *
         * @param string $reportUuid The UUID of the report record.
         * @return ReportRecord|null The ReportRecord object if found, null otherwise.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getReport(string $reportUuid): ?ReportRecord
        {
            if(strlen($reportUuid) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($reportUuid))
            {
                throw new InvalidArgumentException('Invalid Report UUID');
            }

            if(self::isCachingEnabled())
            {
                $cached = RedisConnection::getRecord(sprintf("%s%s", self::CACHE_PREFIX, $reportUuid));
                if($cached !== null)
                {
                    return new ReportRecord($cached);
                }
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM reports WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $reportUuid);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if($data === false)
                {
                    return null;
                }

                $data = new ReportRecord($data);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve report: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && !RedisConnection::limitReached(self::CACHE_PREFIX, Configuration::getRedisConfiguration()->getReportCacheLimit()))
            {
                RedisConnection::setRecord(
                    record: $data, cacheKey: sprintf("%s%s", self::CACHE_PREFIX, $data->getUuid()),
                    ttl: Configuration::getRedisConfiguration()->getReportCacheTtl()
                );
            }

            return $data;
        }

        /**
         * Checks if a report record exists by its UUID.
         *
         * @param string $reportUuid The UUID of the report record to check.
         * @return bool True if the report exists, false otherwise.
         * @throws InvalidArgumentException If the UUID is not provided.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function reportExists(string $reportUuid): bool
        {
            if(strlen($reportUuid) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($reportUuid))
            {
                throw new InvalidArgumentException('Invalid Report UUID');
            }

            if(self::isCachingEnabled() && RedisConnection::recordExists(sprintf("%s%s", self::CACHE_PREFIX, $reportUuid)))
            {
                return true;
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT COUNT(*) FROM reports WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $reportUuid);
                $stmt->execute();

                return $stmt->fetchColumn() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check report existence: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Checks if an entity has any opened reports against it that have not yet been concluded.
         *
         * @param string $entityUuid The UUID of the reported entity
         * @return bool True if at least one opened report exists for the entity, false otherwise.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function hasOpenReports(string $entityUuid): bool
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT EXISTS(SELECT 1 FROM reports WHERE reporting_entity = :uuid AND opened = 1)");
                $stmt->bindParam(':uuid', $entityUuid);
                $stmt->execute();

                return (bool)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check opened reports: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Assigns an operator to handle a report.
         *
         * @param string $reportUuid The UUID of the report.
         * @param string $operatorUuid The UUID of the operator to assign.
         * @throws InvalidArgumentException If the UUIDs are not provided or are invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function assignOperator(string $reportUuid, string $operatorUuid): void
        {
            if(strlen($reportUuid) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($reportUuid))
            {
                throw new InvalidArgumentException('Invalid Report UUID');
            }

            if(strlen($operatorUuid) < 1)
            {
                throw new InvalidArgumentException('Operator UUID must be provided.');
            }

            if(!Validate::uuid($operatorUuid))
            {
                throw new InvalidArgumentException('Invalid Operator UUID');
            }

            $now = date('Y-m-d H:i:s');

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE reports SET assigned_operator = :assigned_operator, updated = :updated WHERE uuid = :uuid"
                );
                $stmt->bindParam(':uuid', $reportUuid);
                $stmt->bindParam(':assigned_operator', $operatorUuid);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to assign operator to report: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $reportUuid));
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::REPORT_OPERATOR_ASSIGNED, $reportUuid);
            }
        }


        /**
         * Closes a report and optionally performs related actions such as classifying evidence or blacklisting an entity.
         *
         * @param string $reportUuid The UUID of the report to be closed.
         * @param OperatorRecord $operator The operator closing the report.
         * @param ClassificationFlag|null $classificationFlag Optional classification flag to assign to evidence records linked to the report.
         * @param IncidentType|null $blacklistType Optional type of blacklist to apply to the reporting entity of the report.
         * @param int|null $blacklistExpires Optional UNIX timestamp indicating when the blacklist should expire. Must be in the future if provided.
         * @return string|null The UUID of the created blacklist if a blacklist was applied, or null if no blacklist was created.
         * @throws DatabaseOperationException Thrown if there is an issue with the database operation.
         */
        public static function closeReport(string $reportUuid, OperatorRecord $operator, ?ClassificationFlag $classificationFlag=null, ?IncidentType $blacklistType=null, ?int $blacklistExpires=null): ?string
        {
            if(strlen($reportUuid) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($reportUuid))
            {
                throw new InvalidArgumentException('Invalid Report UUID');
            }

            $report = self::getReport($reportUuid);
            if($report === null)
            {
                throw new InvalidArgumentException('The report does not exist');
            }

            if(!$report->isOpened())
            {
                throw new InvalidArgumentException('The report is already closed');
            }

            if($report->getAssignedOperator() !== $operator->getUuid())
            {
                throw new InvalidArgumentException('Report not assigned to operator');
            }

            if($blacklistType !== null && $report->getReportingEntity() === null)
            {
                throw new InvalidArgumentException('The report concerns no entity to blacklist');
            }

            if($blacklistType === null)
            {
                $blacklistExpires = null;
            }
            elseif($blacklistExpires !== null && $blacklistExpires < time())
            {
                throw new InvalidArgumentException('Blacklist expiration must be in the future');
            }

            // Assign classifications before closing the report. The conditional database update preserves existing
            // classifications.
            $evidenceRecords = [];
            if($classificationFlag !== null)
            {
                $evidenceRecords = EvidenceManager::getEvidenceByReport($reportUuid, includeConfidential: true);
                foreach($evidenceRecords as $evidenceRecord)
                {
                    // Evidence that is already classified keeps its classification
                    EvidenceManager::updateClassificationFlag($evidenceRecord->getUuid(), $classificationFlag);
                }
            }

            self::markClosed($reportUuid);

            if($classificationFlag !== null)
            {
                self::applyReputation($report->getReportingEntity(), $classificationFlag, $evidenceRecords);
            }

            $blacklistUuid = null;
            if($blacklistType !== null)
            {
                $blacklistUuid = BlacklistManager::blacklistEntity(
                    entityUuid: $report->getReportingEntity(),
                    operatorUuid: $operator->getUuid(),
                    type: $blacklistType,
                    expires: $blacklistExpires,
                    reportUuid: $reportUuid
                );
            }

            AuditLogManager::createEntry(AuditLogType::REPORT_CLOSED, sprintf(
                'Report %s closed by operator %s',
                $reportUuid,
                $operator->getName()
            ), $operator->getUuid(), $report->getReportingEntity(), null, null, null, $reportUuid);

            return $blacklistUuid;
        }

        /**
         * Marks a report as closed
         *
         * @param string $reportUuid The UUID of the report to close.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        private static function markClosed(string $reportUuid): void
        {
            $now = date('Y-m-d H:i:s');

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE reports SET opened = 0, updated = :updated WHERE uuid = :uuid"
                );
                $stmt->bindParam(':uuid', $reportUuid);
                $stmt->bindParam(':updated', $now);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to close report: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $reportUuid));
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::REPORT_CLOSED, $reportUuid);
            }
        }

        /**
         * Applies the configured reputation adjustment for the classification flag to the reported entity and,
         * if enabled, to every existing entity mentioned within the report's evidence. The reported entity is
         * adjusted only once even if its evidence mentions it.
         *
         * @param string|null $reportingEntity The UUID of the reported entity, if any
         * @param ClassificationFlag $classificationFlag The classification flag the report was closed with
         * @param EvidenceRecord[] $evidenceRecords The evidence records linked to the report
         * @throws DatabaseOperationException Thrown if the reported entity's reputation could not be updated
         */
        private static function applyReputation(?string $reportingEntity, ClassificationFlag $classificationFlag, array $evidenceRecords): void
        {
            $scanningConfiguration = Configuration::getScanningConfiguration();

            $delta = $scanningConfiguration->getReportReputation($classificationFlag);
            if($reportingEntity !== null && $delta !== 0)
            {
                EntitiesManager::updateEntityReputation($reportingEntity, $delta);
            }

            $namedEntityDelta = $scanningConfiguration->getReportNamedEntityReputation($classificationFlag);
            if(!$scanningConfiguration->isReportNamedEntityReputationEnabled() || $namedEntityDelta === 0)
            {
                return;
            }

            $namedEntities = [];
            foreach($evidenceRecords as $evidenceRecord)
            {
                if($evidenceRecord->getTextContent() !== null)
                {
                    $namedEntities += EntitiesManager::resolveNamedEntities($evidenceRecord->getTextContent());
                }
            }

            if($reportingEntity !== null)
            {
                unset($namedEntities[$reportingEntity]);
            }

            foreach(array_keys($namedEntities) as $entityUuid)
            {
                try
                {
                    EntitiesManager::updateEntityReputation($entityUuid, $namedEntityDelta);
                }
                catch(DatabaseOperationException $e)
                {
                    // The report is already closed at this point, a failed mention adjustment must not fail the request
                    Logger::log()->warning(sprintf('Failed to update reputation of mentioned entity %s: %s', $entityUuid, $e->getMessage()), $e);
                }
            }
        }

        /**
         * Deletes a report record by its UUID.
         *
         * @param string $reportUuid The UUID of the report record to delete.
         * @throws InvalidArgumentException If the UUID is not provided or is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function deleteReport(string $reportUuid): void
        {
            if(strlen($reportUuid) < 1)
            {
                throw new InvalidArgumentException('Report UUID must be provided.');
            }

            if(!Validate::uuid($reportUuid))
            {
                throw new InvalidArgumentException('Invalid Report UUID');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM reports WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $reportUuid);
                $stmt->execute();
                $changed = $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to delete report: " . $e->getMessage(), $e->getCode(), $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::getConnection()->del(sprintf("%s%s", self::CACHE_PREFIX, $reportUuid));
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);

                    // Blacklist records keep existing with their report reference cleared by the database.
                    RedisConnection::deleteRecordsByField(BlacklistManager::CACHE_PREFIX, 'report', $reportUuid);
                    RedisConnection::clearSearchCache(BlacklistManager::CACHE_PREFIX);
                }
            }

            if($changed)
            {
                PluginManager::dispatchRecordChange(RecordChangeType::REPORT_DELETED, $reportUuid);
            }
        }

        /**
         * Retrieves report records with pagination.
         *
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @return ReportRecord[] An array of ReportRecord objects.
         * @throws InvalidArgumentException If limit or page parameters are invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getReports(int $limit=100, int $page=1, ?ReportCategory $category=null, ?string $by=null, ?OrderType $order=null): array
        {
            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new ReportRecord($data), $cached);
                }
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $categoryCondition = $category?->toCondition() ?? '';
                $sql = "SELECT * FROM reports";
                if($categoryCondition !== '')
                {
                    $sql .= " WHERE $categoryCondition";
                }
                $sql .= " " . self::buildReportSortClause($by, $order) . " LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $reportRecords = [];
                foreach ($results as $data)
                {
                    $reportRecords[] = new ReportRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve reports: " . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($reportRecords))
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(ReportRecord $r) => $r->toArray(), $reportRecords));
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $reportRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getReportCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getReportCacheTtl()
                );
            }

            return $reportRecords;
        }

        /**
         * Retrieves reports submitted by a specific operator.
         *
         * @param string $operatorUuid The UUID of the submitting operator.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @return ReportRecord[] An array of ReportRecord objects.
         * @throws InvalidArgumentException If the operator UUID is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getReportsBySubmittingOperator(string $operatorUuid, int $limit=100, int $page=1, ?ReportCategory $category=null): array
        {
            if(strlen($operatorUuid) < 1)
            {
                throw new InvalidArgumentException('Operator UUID must be provided.');
            }

            if(!Validate::uuid($operatorUuid))
            {
                throw new InvalidArgumentException('Invalid Operator UUID');
            }

            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $categoryCondition = $category?->toCondition() ?? '';
                $sql = "SELECT * FROM reports WHERE submitting_operator = :operator";
                if($categoryCondition !== '')
                {
                    $sql .= " AND $categoryCondition";
                }
                $sql .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':operator', $operatorUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $reportRecords = [];
                foreach ($results as $data)
                {
                    $reportRecords[] = new ReportRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve reports by submitting operator: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $reportRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getReportCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getReportCacheTtl()
                );
            }

            return $reportRecords;
        }

        /**
         * Retrieves reports associated with a specific reporting entity.
         *
         * @param string $entityUuid The UUID of the reporting entity.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @return ReportRecord[] An array of ReportRecord objects.
         * @throws InvalidArgumentException If the entity UUID is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getReportsByReportingEntity(string $entityUuid, int $limit=100, int $page=1, ?ReportCategory $category=null): array
        {
            if(strlen($entityUuid) < 1)
            {
                throw new InvalidArgumentException('Entity UUID must be provided.');
            }

            if(!Validate::uuid($entityUuid))
            {
                throw new InvalidArgumentException('Invalid Entity UUID');
            }

            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }
            $cacheKey = null;
            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if($cached !== null)
                {
                    return array_map(fn($data) => new ReportRecord($data), $cached);
                }
            }


            try
            {
                $offset = ($page - 1) * $limit;
                $categoryCondition = $category?->toCondition() ?? '';
                $sql = "SELECT * FROM reports WHERE reporting_entity = :entity";
                if($categoryCondition !== '')
                {
                    $sql .= " AND $categoryCondition";
                }
                $sql .= " ORDER BY created DESC, uuid DESC LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':entity', $entityUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $reportRecords = [];
                foreach ($results as $data)
                {
                    $reportRecords[] = new ReportRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve reports by reporting entity: " . $e->getMessage(), $e->getCode(), $e);
            }

            if($cacheKey !== null)
            {
                RedisConnection::cacheSearchResults($cacheKey, array_map(fn(ReportRecord $record) => $record->toArray(), $reportRecords));
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $reportRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getReportCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getReportCacheTtl()
                );
            }

            return $reportRecords;
        }

        /**
         * Retrieves reports assigned to a specific operator.
         *
         * @param string $operatorUuid The UUID of the assigned operator.
         * @param int $limit The maximum number of records to return (default is 100).
         * @param int $page The page number for pagination (default is 1).
         * @return ReportRecord[] An array of ReportRecord objects.
         * @throws InvalidArgumentException If the operator UUID is invalid.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getReportsByAssignedOperator(string $operatorUuid, int $limit=100, int $page=1, ?ReportCategory $category=null, ?string $by=null, ?OrderType $order=null): array
        {
            if(strlen($operatorUuid) < 1)
            {
                throw new InvalidArgumentException('Operator UUID must be provided.');
            }

            if(!Validate::uuid($operatorUuid))
            {
                throw new InvalidArgumentException('Invalid Operator UUID');
            }

            if($limit <= 0)
            {
                throw new InvalidArgumentException('Limit must be 1 or greater');
            }

            if($page <= 0)
            {
                throw new InvalidArgumentException('Page must be greater than 0');
            }

            try
            {
                $offset = ($page - 1) * $limit;
                $categoryCondition = $category?->toCondition() ?? '';
                $sql = "SELECT * FROM reports WHERE assigned_operator = :operator";
                if($categoryCondition !== '')
                {
                    $sql .= " AND $categoryCondition";
                }
                $sql .= " " . self::buildReportSortClause($by, $order) . " LIMIT :limit OFFSET :offset";
                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindParam(':operator', $operatorUuid);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $reportRecords = [];
                foreach ($results as $data)
                {
                    $reportRecords[] = new ReportRecord($data);
                }
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve reports by assigned operator: " . $e->getMessage(), $e->getCode(), $e);
            }

            if(self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $reportRecords, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getReportCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getReportCacheTtl()
                );
            }

            return $reportRecords;
        }

        /**
         * Counts the total number of report records in the database.
         *
         * @return int The total number of report records.
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function countRecords(): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->query("SELECT COUNT(*) FROM reports");
                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to count reports: " . $e->getMessage(), $e->getCode(), $e);
            }
        }

        /**
         * Retrieves the oldest report records that are eligible for cleanup, as raw record data.
         *
         * Only closed reports are cleaned up, a report is old when it was last changed (eg; closed) before the given
         * time.
         *
         * @param int $before The Unix timestamp before which a record is old
         * @param int $limit The maximum number of records to return
         * @return array[] An array of raw report record data, oldest first
         * @throws InvalidArgumentException If the limit is out of range
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function getOldRecords(int $before, int $limit=1000): array
        {
            if($limit < 1 || $limit > 10000)
            {
                throw new InvalidArgumentException('Limit must be between 1 and 10000.');
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM reports WHERE opened = 0 AND COALESCE(updated, created) < :before ORDER BY created ASC, uuid ASC LIMIT :limit");
                $stmt->bindValue(':before', date('Y-m-d H:i:s', $before));
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to retrieve old report records: " . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Deletes the given report records that are still eligible for cleanup (see getOldRecords()), a record that
         * changed since it was retrieved is kept. No record changes are dispatched for the deleted records.
         * The database sets the report of the evidence, blacklist and audit log records that refer to a deleted
         * report to null.
         *
         * @param string[] $uuids The UUIDs of the report records to delete
         * @param int $before The Unix timestamp that was given to getOldRecords()
         * @return int The number of deleted records
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement.
         */
        public static function purgeRecords(array $uuids, int $before): int
        {
            if(empty($uuids))
            {
                return 0;
            }

            [$placeholders, $parameters] = Utilities::getInPlaceholders($uuids);
            $parameters[':before'] = date('Y-m-d H:i:s', $before);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM reports WHERE uuid IN ($placeholders) AND opened = 0 AND COALESCE(updated, created) < :before");
                $stmt->execute($parameters);
                return $stmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to purge report records: " . $e->getMessage(), 0, $e);
            }
            finally
            {
                if(self::isCachingEnabled())
                {
                    RedisConnection::clearRecords(self::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(self::CACHE_PREFIX);
                    RedisConnection::clearRecords(EvidenceManager::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(EvidenceManager::CACHE_PREFIX);
                    RedisConnection::clearRecords(BlacklistManager::CACHE_PREFIX);
                    RedisConnection::clearSearchCache(BlacklistManager::CACHE_PREFIX);
                    RedisConnection::clearRecords(AuditLogManager::CACHE_PREFIX);
                }
            }
        }

        /**
         * Searches reports by a LIKE pattern across uuid, message, and reporting_entity columns, and with the extended
         * search also the submitting_operator, assigned_operator, and incident_type columns.
         *
         * @param string $likePattern The SQL LIKE pattern to search with.
         * @param int $limit The maximum number of results to return.
         * @param int $page The page number for pagination.
         * @param bool $extended True to also match the columns of the extended search, available to operators
         * @return ReportRecord[] An array of matching ReportRecord objects.
         * @throws DatabaseOperationException If there is an error executing the query.
         */
        public static function searchReports(string $likePattern, int $limit, int $page, ?ReportCategory $category=null, ?string $by=null, ?OrderType $order=null, bool $extended=false): array
        {
            $offset = ($page - 1) * $limit;

            $cacheKey = null;
            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                $cacheKey = RedisConnection::getSearchCacheKey(self::CACHE_PREFIX, func_get_args());
                $cached = RedisConnection::getCachedSearchResults($cacheKey);
                if ($cached !== null)
                {
                    return array_map(fn($data) => new ReportRecord($data), $cached);
                }
            }

            try
            {
                $columns = ['uuid', 'message', 'reporting_entity'];
                if ($extended)
                {
                    array_push($columns, 'submitting_operator', 'assigned_operator', 'incident_type');
                }

                $sql = "SELECT * FROM reports WHERE " . Utilities::buildLikeCondition($columns);

                $categoryCondition = $category?->toCondition() ?? '';
                if ($categoryCondition !== '')
                {
                    $sql .= " AND ($categoryCondition)";
                }

                $sortClause = self::buildReportSortClause($by, $order);
                $sql .= " $sortClause LIMIT :limit OFFSET :offset";

                $stmt = DatabaseConnection::getConnection()->prepare($sql);
                $stmt->bindValue(':q', $likePattern);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                $reports = array_map(fn($row) => new ReportRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to search reports: ' . $e->getMessage(), $e->getCode(), $e);
            }

            if ($cacheKey !== null && !empty($reports))
            {
                RedisConnection::cacheSearchResults(
                    $cacheKey,
                    array_map(fn(ReportRecord $r) => $r->toArray(), $reports)
                );
            }

            if (self::isCachingEnabled() && Configuration::getRedisConfiguration()->isPreCacheEnabled())
            {
                RedisConnection::setRecords(
                    records: $reports, prefix: self::CACHE_PREFIX, propertyName: 'getUuid',
                    limit: Configuration::getRedisConfiguration()->getReportCacheLimit(),
                    ttl: Configuration::getRedisConfiguration()->getReportCacheTtl()
                );
            }

            return $reports;
        }

        /**
         * Builds a SQL WHERE condition snippet for the given ReportCategory.
         *
         * @param ReportCategory|null $category The category to filter by, or null for no filter.
         * @return string The SQL condition string, or an empty string if no filter is needed.
         */
        /**
         * Checks if caching is enabled based on the configuration.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private static function isCachingEnabled(): bool
        {
            return Configuration::getRedisConfiguration()->isEnabled() && Configuration::getRedisConfiguration()->isReportCacheEnabled();
        }

        /**
         * Builds the Report Sort Clause
         *
         * @param string|null $by The column to sort by
         * @param OrderType|null $order The order to sort by
         * @return string Returns the SQL report sort clause
         */
        private static function buildReportSortClause(?string $by, ?OrderType $order): string
        {
            $column = 'created';
            $direction = 'DESC';

            if ($by !== null)
            {
                $filterType = ReportOrderType::tryFromCaseInsensitive($by);
                if ($filterType !== null)
                {
                    $column = $filterType->toColumn();
                }
            }

            if ($order !== null)
            {
                $direction = $order->value;
            }

            $secondaryDirection = $direction === 'ASC' ? 'ASC' : 'DESC';
            return "ORDER BY $column $direction, uuid $secondaryDirection";
        }
    }
