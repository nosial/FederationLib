<?php

    namespace FederationLib\Enums\Categories;

    use FederationLib\Interfaces\CaseSensitiveInterface;
    use FederationLib\Interfaces\CategorizableDatabaseInterface;

    enum AttachmentCategory : string implements CategorizableDatabaseInterface, CaseSensitiveInterface
    {
        case IMAGE = 'IMAGE';
        case DOCUMENT = 'DOCUMENT';
        case ARCHIVE = 'ARCHIVE';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?AttachmentCategory
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
                self::IMAGE => "file_mime LIKE 'image/%'",
                self::DOCUMENT => "(file_mime LIKE 'text/%' OR file_mime = 'application/pdf')",
                self::ARCHIVE => "file_mime IN ('application/zip','application/gzip','application/x-tar','application/x-rar-compressed','application/x-7z-compressed')",
            };
        }
    }
