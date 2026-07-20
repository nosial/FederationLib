<?php

    namespace FederationLib\Enums\OrderTypes;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\SortableDatabaseInterface;

    enum AttachmentOrderType : string implements SortableDatabaseInterface, CaseSensitiveInterface
    {
        case FILE_NAME = 'file_name';
        case FILE_MIME = 'file_mime';
        case FILE_SIZE = 'file_size';
        case CREATED = 'created';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?AttachmentOrderType
        {
            return self::tryFrom(strtolower($value));
        }

        /**
         * @inheritDoc
         */
        public function toColumn(): string
        {
            return $this->value;
        }
    }
