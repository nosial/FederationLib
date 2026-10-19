<?php

    namespace FederationLib\Objects\OperatorRecord;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    /**
     * The settings of an operator that are not permissions (OFD "OperatorConfiguration object")
     */
    class OperatorConfiguration implements SerializableInterface, ObjectSpecificationInterface
    {
        private bool $autoAssignReports;
        private bool $autoAssignAutomatedReports;
        private bool $isBot;

        /**
         * OperatorConfiguration constructor
         *
         * @param bool $autoAssignReports Whether the operator opts in to the automatic assignment of reports that are not automated
         * @param bool $autoAssignAutomatedReports Whether the operator opts in to the automatic assignment of automated reports
         * @param bool $isBot Whether the operator is a bot, whose evidence and reports are automated
         */
        public function __construct(bool $autoAssignReports, bool $autoAssignAutomatedReports, bool $isBot)
        {
            $this->autoAssignReports = $autoAssignReports;
            $this->autoAssignAutomatedReports = $autoAssignAutomatedReports;
            $this->isBot = $isBot;
        }

        /**
         * Returns whether the operator opts in to the automatic assignment of reports that are not automated
         *
         * @return bool True if the operator opts in
         */
        public function isAutoAssignReports(): bool
        {
            return $this->autoAssignReports;
        }

        /**
         * Returns whether the operator opts in to the automatic assignment of automated reports
         *
         * @return bool True if the operator opts in
         */
        public function isAutoAssignAutomatedReports(): bool
        {
            return $this->autoAssignAutomatedReports;
        }

        /**
         * Returns whether the operator is a bot, the evidence and reports it submits are automated and its evidence is
         * created confidential
         *
         * @return bool True if the operator is a bot
         */
        public function isBot(): bool
        {
            return $this->isBot;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'auto_assign_reports' => $this->autoAssignReports,
                'auto_assign_automated_reports' => $this->autoAssignAutomatedReports,
                'is_bot' => $this->isBot,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): OperatorConfiguration
        {
            return new self(
                (bool)($array['auto_assign_reports'] ?? false),
                (bool)($array['auto_assign_automated_reports'] ?? false),
                (bool)($array['is_bot'] ?? false)
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
                'auto_assign_reports' => ['type' => 'boolean', 'description' => 'Whether the operator opts in to the automatic assignment of reports that are not automated'],
                'auto_assign_automated_reports' => ['type' => 'boolean', 'description' => 'Whether the operator opts in to the automatic assignment of automated reports'],
                'is_bot' => ['type' => 'boolean', 'description' => 'Whether the operator is a bot, the evidence and reports it submits are automated and its evidence is created confidential'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['auto_assign_reports', 'auto_assign_automated_reports', 'is_bot'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/OperatorConfiguration';
        }
    }
