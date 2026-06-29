<?php

    namespace FederationLib\Objects;

    use DateTime;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class ReportRecord implements SerializableInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private string $submittingOperator;
        private ?string $reportingEntity;
        private ?string $assignedOperator;
        private bool $automated;
        private IncidentType $incidentType;
        private bool $opened;
        private ?string $message;
        private int $created;
        private ?int $updated;

        /**
         * ReportRecord Public Constructor
         *
         * @param array $data The array data of ReportRecord
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'];
            $this->submittingOperator = $data['submitting_operator'];
            $this->reportingEntity = $data['reporting_entity'] ?? null;
            $this->assignedOperator = $data['assigned_operator'] ?? null;
            $this->automated = (bool)$data['automated'] ?? false;
            $this->incidentType = IncidentType::tryFrom($data['incident_type']) ?? IncidentType::OTHER;
            $this->opened = (bool)$data['opened'] ?? false;
            $this->message = $data['message'] ?? null;

            // Parse SQL datetime string to timestamp if necessary for created
            if (isset($data['created']) && is_string($data['created']))
            {
                // Numeric strings come from the Redis cache (hGetAll returns all hash values as strings)
                $data['created'] = is_numeric($data['created']) ? (int)$data['created'] : strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $data['created'] = $data['created']->getTimestamp();
            }
            else
            {
                $data['created'] = $data['created'] ?? time();
            }

            // Parse SQL datetime string to timestamp if necessary for updated
            if (isset($data['updated']) && is_string($data['updated']))
            {
                // Numeric strings come from the Redis cache (hGetAll returns all hash values as strings)
                $data['updated'] = is_numeric($data['updated']) ? (int)$data['updated'] : strtotime($data['updated']);
            }
            elseif (isset($data['updated']) && $data['updated'] instanceof DateTime)
            {
                $data['updated'] = $data['updated']->getTimestamp();
            }
            else
            {
                $data['updated'] = $data['updated'] ?? time();
            }

            $this->created = (int)($data['created'] ?? time());
            $this->updated = (int)$data['updated'] ?? null;
        }

        /**
         * Returns the Unique Universal Identifier of the Report record
         *
         * @return string The UUID of the report record
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Returns the Unique Universal Identifier of the operator that submitted this report
         *
         * @return string The UUID of the operator that submitted the report record
         */
        public function getSubmittingOperator(): string
        {
            return $this->submittingOperator;
        }

        /**
         * Optional. Returns the Unique Universal Identifier of the entity that submitted the report. null=Anonymous
         *
         * @return string|null The UUID of the reporting entity, null=Anonyomous
         */
        public function getReportingEntity(): ?string
        {
            return $this->reportingEntity;
        }

        /**
         * Optional. Returns the Unique Universal Identifier of the operator that was assigned to manage the report
         *
         * @return string|null
         */
        public function getAssignedOperator(): ?string
        {
            return $this->assignedOperator;
        }

        /**
         * Returns True if the report was automatically generated -- eg; machine learning detection
         *
         * @return bool True if automated, False otherwise.
         */
        public function isAutomated(): bool
        {
            return $this->automated;
        }

        /**
         * Returns the incident type of the report
         *
         * @return IncidentType The report incident type
         */
        public function getIncidentType(): IncidentType
        {
            return $this->incidentType;
        }

        /**
         * Returns True if the report is opened and unresolved, False otherwise.
         *
         * @return bool True if the report is opened, False otherwise.
         */
        public function isOpened(): bool
        {
            return $this->opened;
        }

        /**
         * Optional. Returns the message attached with the report
         *
         * @return string|null The report message, null if no message was included
         */
        public function getMessage(): ?string
        {
            return $this->message;
        }

        /**
         * Returns the Unix Timestamp for when the record was created
         *
         * @return int The Unix Timestamp of the record's creation date/time
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * Returns the Unix Timestamp for when the record was updated, null if it was never updated.
         *
         * @return int|null The Unix Timestamp of the record's update date/time, null if it was never updated
         */
        public function getUpdated(): ?int
        {
            return $this->updated;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'submitting_operator' => $this->submittingOperator,
                'reporting_entity' => $this->reportingEntity,
                'assigned_operator' => $this->assignedOperator,
                'automated' => $this->automated,
                'incident_type' => $this->incidentType->value,
                'opened' => $this->opened,
                'message' => $this->message,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ReportRecord
        {
            if(isset($array['created']) )
            {
                if(is_string($array['created']))
                {
                    $array['created'] = is_numeric($array['created']) ? (int)$array['created'] : strtotime($array['created']);
                }
                if($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }

            if(isset($array['updated']) )
            {
                if(is_string($array['updated']))
                {
                    $array['updated'] = is_numeric($array['updated']) ? (int)$array['updated'] : strtotime($array['updated']);
                }
                if($array['updated'] instanceof DateTime)
                {
                    $array['updated'] = $array['updated']->getTimestamp();
                }
            }

            return new self($array);
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
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Unique identifier for the report'],
                'submitting_operator' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the operator who submitted the report'],
                'reporting_entity' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the entity being reported', 'nullable' => true],
                'assigned_operator' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the operator assigned to the report', 'nullable' => true],
                'automated' => ['type' => 'boolean', 'description' => 'Whether the report was created automatically'],
                'incident_type' => ['type' => 'string', 'description' => 'Type of incident being reported'],
                'opened' => ['type' => 'boolean', 'description' => 'Whether the report is still open'],
                'message' => ['type' => 'string', 'description' => 'Message or description for the report', 'nullable' => true],
                'created' => ['type' => 'integer', 'description' => 'Unix timestamp when the report was created'],
                'updated' => ['type' => 'integer', 'description' => 'Unix timestamp when the report was last updated', 'nullable' => true],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'submitting_operator', 'automated', 'incident_type', 'opened', 'created'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ReportRecord';
        }
    }