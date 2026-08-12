<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class ReportSubmission implements SerializableInterface, ObjectSpecificationInterface
    {
        private ReportRecord $report;
        private array $evidence;

        /**
         * Public Constructor
         *
         * @param ReportRecord $report The report record object
         * @param EvidenceRecord[] $evidence The evidence record objects created with the report
         */
        public function __construct(ReportRecord $report, array $evidence)
        {
            $this->report = $report;
            $this->evidence = $evidence;
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
         * Returns the evidence records that were created with the report submission
         *
         * @return EvidenceRecord[] The evidence records created with the report submission
         */
        public function getEvidence(): array
        {
            return $this->evidence;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'report' => $this->report->toArray(),
                'evidence' => array_map(fn($evidence) => $evidence->toArray(), $this->evidence),
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ReportSubmission
        {
            $evidence = [];
            if(isset($array['evidence']) && is_array($array['evidence']))
            {
                $evidence = array_map(fn($item) => EvidenceRecord::fromArray($item), $array['evidence']);
            }

            return new self(
                ReportRecord::fromArray($array['report']),
                $evidence
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
                'evidence' => [
                    'type' => 'array',
                    'items' => ['$ref' => EvidenceRecord::getReference()],
                    'description' => 'Evidence records created with the report submission',
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