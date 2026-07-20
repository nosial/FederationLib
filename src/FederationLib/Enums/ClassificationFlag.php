<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum ClassificationFlag : string implements CaseSensitiveInterface
    {
        case MALICIOUS = 'MALICIOUS'; // red flag
        case SUSPICIOUS = 'SUSPICIOUS'; // yellow flag
        case NORMAL = 'NORMAL'; // green flag

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?ClassificationFlag
        {
            return self::tryFrom(strtoupper($value));
        }
    }
