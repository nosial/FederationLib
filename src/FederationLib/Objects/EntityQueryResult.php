<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\SerializableInterface;

    class EntityQueryResult implements SerializableInterface
    {
        private EntityRecord $entityRecord;
        /**
         * @var QueriedBlacklistRecord[]
         */
        private array $queriedBlacklistRecords;
        /**
         * @var EvidenceRecord[]
         */
        private array $evidenceRecords;
        /**
         * @var AuditLog[]
         */
        private array $auditLogs;

        /**
         * EntityQueryResult constructor.
         *
         * @param EntityRecord $entityRecord The entity record associated with this query result.
         * @param QueriedBlacklistRecord[] $queriedBlacklistRecords An array of queried blacklist records associated with this entity.
         * @param EvidenceRecord[] $evidenceRecords An array of evidence records associated with this entity.
         * @param AuditLog[] $auditLogs An array of audit log records associated with this entity.
         */
        public function __construct(EntityRecord $entityRecord, array $queriedBlacklistRecords, array $evidenceRecords, array $auditLogs)
        {
            $this->entityRecord = $entityRecord;
            $this->queriedBlacklistRecords = $queriedBlacklistRecords;
            $this->evidenceRecords = $evidenceRecords;
            $this->auditLogs = $auditLogs;
        }

        /**
         * Returns the entity record associated with this query result.
         *
         * @return EntityRecord The entity record associated with this query result.
         */
        public function getEntityRecord(): EntityRecord
        {
            return $this->entityRecord;
        }

        /**
         * Checks if the entity is blacklisted based on the queried blacklist records.
         *
         * @return bool True if the entity is blacklisted, false otherwise.
         *               An entity is considered blacklisted if at least one of its queried blacklist records
         *               is not lifted (i.e., still active).
         */
        public function isBlacklisted(): bool
        {
            if(empty($this->queriedBlacklistRecords))
            {
                return false;
            }

            foreach ($this->queriedBlacklistRecords as $record)
            {
                if (!$record->getBlacklistRecord()->isLifted())
                {
                    return true; // At least one record is not lifted, hence the entity is blacklisted.
                }
            }

            return false; // All records are lifted, hence the entity is not blacklisted.
        }

        /**
         * Returns the queried blacklist records associated with this entity.
         *
         * @return QueriedBlacklistRecord[] The array of queried blacklist records.
         */
        public function getQueriedBlacklistRecords(): array
        {
            return $this->queriedBlacklistRecords;
        }

        /**
         * Returns the evidence records associated with this entity.
         *
         * @return EvidenceRecord[] The array of evidence records.
         */
        public function getEvidenceRecords(): array
        {
            return $this->evidenceRecords;
        }

        /**
         * Returns the audit logs associated with this entity.
         *
         * @return AuditLog[] The array of audit log records.
         */
        public function getAuditLogs(): array
        {
            return $this->auditLogs;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'entity_record' => $this->entityRecord->toArray(),
                'is_blacklisted' => $this->isBlacklisted(),
                'blacklist_records' => array_map(fn($record) => $record->toArray(), $this->queriedBlacklistRecords),
                'evidence_records' => array_map(fn($record) => $record->toArray(), $this->evidenceRecords),
                'audit_logs' => array_map(fn($log) => $log->toArray(), $this->auditLogs),
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): EntityQueryResult
        {
            $entityRecord = EntityRecord::fromArray($array['entity_record']);
            $queriedBlacklistRecords = array_map(fn($item) => QueriedBlacklistRecord::fromArray($item),
                $array['blacklist_records']
            );
            $evidenceRecords = array_map(fn($item) => EvidenceRecord::fromArray($item),
                $array['evidence_records']
            );
            $auditLogs = array_map(fn($item) => AuditLog::fromArray($item),
                $array['audit_logs']
            );

            return new self($entityRecord, $queriedBlacklistRecords, $evidenceRecords, $auditLogs);
        }
    }