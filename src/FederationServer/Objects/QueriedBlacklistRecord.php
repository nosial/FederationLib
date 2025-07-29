<?php

    namespace FederationServer\Objects;

    use FederationServer\Interfaces\SerializableInterface;

    class QueriedBlacklistRecord implements SerializableInterface
    {
        private BlacklistRecord $blacklistRecord;
        private ?EvidenceRecord $evidenceRecord;
        /**
         * @var FileAttachmentRecord[]
         */
        private array $fileAttachments;

        /**
         * Constructs a new QueriedBlacklistRecord instance.
         *
         * @param BlacklistRecord $blacklistRecord The blacklist record associated with this queried blacklist record.
         * @param EvidenceRecord|null $evidenceRecord The evidence record associated with this blacklist record. null if no evidence is available, or it's confidential.
         * @param FileAttachmentRecord[] $fileAttachments An array of file attachment records associated with this blacklist record.
         */
        public function __construct(BlacklistRecord $blacklistRecord, ?EvidenceRecord $evidenceRecord, array $fileAttachments=[])
        {
            $this->blacklistRecord = $blacklistRecord;
            $this->evidenceRecord = $evidenceRecord;
            $this->fileAttachments = $fileAttachments;
        }

        /**
         * Returns the blacklist record associated with this queried blacklist record.
         *
         * @return BlacklistRecord The blacklist record associated with this queried blacklist record.
         */
        public function getBlacklistRecord(): BlacklistRecord
        {
            return $this->blacklistRecord;
        }

        /**
         * Returns the evidence record associated with this blacklist record. Returns null if no evidence
         * is available, or it's confidential.
         *
         * @return EvidenceRecord|null The evidence record associated with this blacklist record.
         */
        public function getEvidenceRecord(): ?EvidenceRecord
        {
            return $this->evidenceRecord;
        }

        /**
         * Returns the file attachments associated with this blacklist record.
         *
         * @return FileAttachmentRecord[] The array of file attachment records.
         */
        public function getFileAttachments(): array
        {
            return $this->fileAttachments;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'blacklist_record' => $this->blacklistRecord->toArray(),
                'evidence_record' => $this->evidenceRecord?->toArray(),
                'file_attachments' => array_map(
                    fn(FileAttachmentRecord $item) => $item->toArray(),
                    $this->fileAttachments
                ),
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): QueriedBlacklistRecord
        {
            return new self(
                BlacklistRecord::fromArray($array['blacklist_record'] ?? []),
                !is_null($array['evidence_record']) ? EvidenceRecord::fromArray($array['evidence_record']) : null,
                array_map(
                    fn($item) => FileAttachmentRecord::fromArray($item),
                    $array['file_attachments'] ?? []
                )
            );
        }
    }