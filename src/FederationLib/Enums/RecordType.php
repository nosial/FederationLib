<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum RecordType : string implements CaseSensitiveInterface
    {
        case ENTITY = 'ENTITY';
        case EVIDENCE = 'EVIDENCE';
        case BLACKLIST = 'BLACKLIST';
        case REPORT = 'REPORT';
        case ATTACHMENT = 'ATTACHMENT';
        case AUDIT_LOG = 'AUDIT_LOG';
        case OPERATOR = 'OPERATOR';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?RecordType
        {
            return self::tryFrom(strtoupper($value));
        }
    }
