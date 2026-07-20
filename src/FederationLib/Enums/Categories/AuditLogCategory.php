<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Enums\AuditLogType;
    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum AuditLogCategory : string implements CategorizableDatabaseInterface, CaseSensitiveInterface
    {
        case OPERATOR_EVENTS = 'OPERATOR_EVENTS';
        case ATTACHMENT_EVENTS = 'ATTACHMENT_EVENTS';
        case EVIDENCE_EVENTS = 'EVIDENCE_EVENTS';
        case REPORT_EVENTS = 'REPORT_EVENTS';
        case ENTITY_EVENTS = 'ENTITY_EVENTS';
        case BLACKLIST_EVENTS = 'BLACKLIST_EVENTS';
        case OTHER = 'OTHER';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?AuditLogCategory
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * @inheritDoc
         */
        public function toCondition(): array
        {
            $typeValues = [];
            foreach (AuditLogType::cases() as $type)
            {
                if ($type->getCategory() === $this)
                {
                    $typeValues[] = $type->value;
                }
            }

            if (empty($typeValues))
            {
                return ['', []];
            }

            $placeholders = [];
            $params = [];
            foreach ($typeValues as $i => $value)
            {
                $key = ":cat_type_$i";
                $placeholders[] = $key;
                $params[$key] = $value;
            }

            return ['type IN (' . implode(', ', $placeholders) . ')', $params];
        }
    }
