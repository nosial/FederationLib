<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum RetrievalPurpose : string implements CaseSensitiveInterface
    {
        case ATTACHMENT_UPLOAD = 'ATTACHMENT_UPLOAD';
        case SCAN_FILE = 'SCAN_FILE';
        case OTHER = 'OTHER';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?RetrievalPurpose
        {
            return self::tryFrom(strtoupper(trim($value)));
        }
    }
