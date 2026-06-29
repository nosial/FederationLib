<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class ReportSubmission implements SerializableInterface, ObjectSpecificationInterface
    {
        private ReportRecord $report;
        private EvidenceRecord $evidence;
        private ?array $attachments;

        /**
         * Public Constructor
         *
         * @param ReportRecord $report The report record object
         * @param EvidenceRecord $evidence The evidence record object
         * @param array|null $attachments Optional array of UploadResult objects
         */
        public function __construct(ReportRecord $report, EvidenceRecord $evidence, ?array $attachments=null)
        {
            $this->report = $report;
            $this->evidence = $evidence;
            $this->attachments = $attachments;
        }

        /**
         * Returns the report record that was created with the report submission
         *
         * @return ReportRecord The created report record
         */
        public function getReport(): ReportRecord
        {
            return $this->report;
        }

        /**
         * Returns the evidence record that was created with the report submission
         *
         * @return EvidenceRecord The evidence record created with the report submission
         */
        public function getEvidence(): EvidenceRecord
        {
            return $this->evidence;
        }

        /**
         * Returns the attachments that were uploaded with the report submission
         *
         * @return array|null Array of UploadResult objects or null if none
         */
        public function getAttachments(): ?array
        {
            return $this->attachments;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            $result = [
                'report' => $this->report->toArray(),
                'evidence' => $this->evidence->toArray()
            ];

            if($this->attachments !== null)
            {
                $result['attachments'] = array_map(fn($attachment) => $attachment->toArray(), $this->attachments);
            }

            return $result;
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ReportSubmission
        {
            $attachments = null;
            if(isset($array['attachments']) && is_array($array['attachments']))
            {
                $attachments = array_map(fn($item) => UploadResult::fromArray($item), $array['attachments']);
            }

            return new self(
                ReportRecord::fromArray($array['report']),
                EvidenceRecord::fromArray($array['evidence']),
                $attachments
            );
        }

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'report' => ['$ref' => ReportRecord::getReference(), 'description' => 'The created report record'],
                'evidence' => ['$ref' => EvidenceRecord::getReference(), 'description' => 'The submitted evidence record'],
                'attachments' => [
                    'type' => 'array',
                    'items' => ['$ref' => UploadResult::getReference()],
                    'description' => 'Uploaded file attachments associated with the report',
                    'nullable' => true,
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['report', 'evidence'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ReportSubmission';
        }
    }