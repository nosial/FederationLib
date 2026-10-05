<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\CacheOperationException;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ReportRecord;

    class RecordChange
    {
        private RecordChangeType $type;
        private string $uuid;
        private EntityRecord|EvidenceRecord|ReportRecord|BlacklistRecord|OperatorRecord|FileAttachmentRecord|null $record;
        private bool $recordRetrieved;

        /**
         * RecordChange constructor.
         *
         * @param RecordChangeType $type The type of the change
         * @param string $uuid The UUID of the changed record
         * @param EntityRecord|EvidenceRecord|ReportRecord|BlacklistRecord|OperatorRecord|FileAttachmentRecord|null $record
         *        Optional. The record if it is already known, otherwise it is retrieved when it is first requested
         */
        public function __construct(RecordChangeType $type, string $uuid, EntityRecord|EvidenceRecord|ReportRecord|BlacklistRecord|OperatorRecord|FileAttachmentRecord|null $record=null)
        {
            $this->type = $type;
            $this->uuid = $uuid;
            $this->record = $record;
            $this->recordRetrieved = $record !== null || $type->isDeletion();
        }

        /**
         * Returns the type of the change
         *
         * @return RecordChangeType The change type, eg; REPORT_CLOSED
         */
        public function getType(): RecordChangeType
        {
            return $this->type;
        }

        /**
         * Returns the type of the changed record
         *
         * @return RecordType The record type, eg; REPORT
         */
        public function getRecordType(): RecordType
        {
            return $this->type->getRecordType();
        }

        /**
         * Returns the UUID of the changed record
         *
         * @return string The record's UUID
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Returns the current state of the changed record, the record is retrieved when this is first called and
         * shared with the other event handlers of the change. The type of the record depends on the record type:
         * OPERATOR returns an OperatorRecord, ENTITY an EntityRecord, EVIDENCE an EvidenceRecord, ATTACHMENT a
         * FileAttachmentRecord, REPORT a ReportRecord and BLACKLIST a BlacklistRecord.
         *
         * @return EntityRecord|EvidenceRecord|ReportRecord|BlacklistRecord|OperatorRecord|FileAttachmentRecord|null
         *         The record, null if the change deleted the record or the record no longer exists
         * @throws DatabaseOperationException If the record could not be retrieved
         * @throws CacheOperationException If the record could not be retrieved from the cache
         */
        public function getRecord(): EntityRecord|EvidenceRecord|ReportRecord|BlacklistRecord|OperatorRecord|FileAttachmentRecord|null
        {
            if($this->recordRetrieved)
            {
                return $this->record;
            }

            $this->record = match($this->getRecordType())
            {
                RecordType::OPERATOR => OperatorManager::getOperator($this->uuid),
                RecordType::ENTITY => EntitiesManager::getEntityByUuid($this->uuid),
                RecordType::EVIDENCE => EvidenceManager::getEvidence($this->uuid),
                RecordType::ATTACHMENT => FileAttachmentManager::getRecord($this->uuid),
                RecordType::REPORT => ReportManager::getReport($this->uuid),
                RecordType::BLACKLIST => BlacklistManager::getBlacklistEntry($this->uuid),
                default => null
            };

            $this->recordRetrieved = true;
            return $this->record;
        }
    }
