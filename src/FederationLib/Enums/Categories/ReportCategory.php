<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum ReportCategory : string implements CategorizableDatabaseInterface, CaseSensitiveInterface
    {
        case OPENED = 'OPENED';
        case CLOSED = 'CLOSED';
        case AUTOMATED = 'AUTOMATED';
        case UNASSIGNED = 'UNASSIGNED';
        case ASSIGNED = 'ASSIGNED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?ReportCategory
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * @inheritDoc
         */
        public function toCondition(): string
        {
            return match($this)
            {
                self::OPENED => 'opened = 1',
                self::CLOSED => 'opened = 0',
                self::AUTOMATED => 'automated = 1',
                self::UNASSIGNED => 'assigned_operator IS NULL',
                self::ASSIGNED => 'assigned_operator IS NOT NULL',
            };
        }
    }
