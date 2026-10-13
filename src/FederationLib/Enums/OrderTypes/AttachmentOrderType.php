<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum AttachmentOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case FILE_NAME = 'FILE_NAME';
        case FILE_MIME = 'FILE_MIME';
        case FILE_SIZE = 'FILE_SIZE';
        case CREATED = 'CREATED';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?AttachmentOrderType
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return strtolower($this->value);
        }
    }
