<?php

    namespace FederationLib\Enums;

    enum ScanningRules
    {
        // Author rules
        case AUTHOR_BLACKLISTED;
        case AUTHOR_PERMANENTLY_BLACKLISTED;
        case AUTHOR_WHITELISTED;
        case AUTHOR_GOOD_REPUTATION;
        case AUTHOR_BAD_REPUTATION;
        // Author parent rules
        case AUTHOR_PARENT_BLACKLISTED;
        case AUTHOR_PARENT_PERMANENTLY_BLACKLISTED;
        case AUTHOR_PARENT_WHITELISTED;
        case AUTHOR_PARENT_GOOD_REPUTATION;
        case AUTHOR_PARENT_BAD_REPUTATION;
        // Named entity rules
        case NAMED_ENTITY_BLACKLISTED;
        case NAMED_ENTITY_PERMANENTLY_BLACKLISTED;
        case NAMED_ENTITY_WHITELISTED;
        case NAMED_ENTITY_GOOD_REPUTATION;
        case NAMED_ENTITY_BAD_REPUTATION;
        // Named entity parent rules
        case NAMED_ENTITY_PARENT_BLACKLISTED;
        case NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED;
        case NAMED_ENTITY_PARENT_WHITELISTED;
        case NAMED_ENTITY_PARENT_GOOD_REPUTATION;
        case NAMED_ENTITY_PARENT_BAD_REPUTATION;
        // Classification rules
        case CLASSIFICATION_NORMAL;
        case CLASSIFICATION_SUSPICIOUS;
        case CLASSIFICATION_MALICIOUS;

        /**
         * Returns the default point modifier of the scanning rules, positive values
         *
         * @return float The scanningA rule's point modifier
         */
        public function getModifier(): float
        {
            return match ($this)
            {
                self::AUTHOR_BLACKLISTED => -20.0,
                self::AUTHOR_PERMANENTLY_BLACKLISTED => -35.0,
                self::AUTHOR_WHITELISTED => 20.0,
                self::AUTHOR_GOOD_REPUTATION => 1.5,
                self::AUTHOR_BAD_REPUTATION => -2.5,
                self::AUTHOR_PARENT_BLACKLISTED => -15.0,
                self::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED => -25.0,
                self::AUTHOR_PARENT_WHITELISTED => 12.0,
                self::AUTHOR_PARENT_GOOD_REPUTATION => 1.0,
                self::AUTHOR_PARENT_BAD_REPUTATION => -1.5,
                self::NAMED_ENTITY_BLACKLISTED => -8.0,
                self::NAMED_ENTITY_PERMANENTLY_BLACKLISTED => -13.0,
                self::NAMED_ENTITY_WHITELISTED => 8.0,
                self::NAMED_ENTITY_GOOD_REPUTATION => 0.8,
                self::NAMED_ENTITY_BAD_REPUTATION => -1.8,
                self::NAMED_ENTITY_PARENT_BLACKLISTED => -5.0,
                self::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED => -8.0,
                self::NAMED_ENTITY_PARENT_WHITELISTED => 5.0,
                self::NAMED_ENTITY_PARENT_GOOD_REPUTATION => 0.5,
                self::NAMED_ENTITY_PARENT_BAD_REPUTATION => -1.0,
                self::CLASSIFICATION_NORMAL => 0.3,
                self::CLASSIFICATION_SUSPICIOUS => -0.3,
                self::CLASSIFICATION_MALICIOUS => -0.4
            };
        }

        /**
         * Returns an array mapping all enum case names to a default float score of 0.0
         *
         * @return array<string, float>
         */
        public static function newTable(): array
        {
            return array_fill_keys(array_column(self::cases(), 'name'), 0.0);
        }

        /**
         * Checks if this rule applies to author-based scanning
         *
         * @return bool True if this is an author rule, False otherwise
         */
        public function isAuthorRule(): bool
        {
            return match ($this)
            {
                self::AUTHOR_BLACKLISTED,
                self::AUTHOR_PERMANENTLY_BLACKLISTED,
                self::AUTHOR_WHITELISTED,
                self::AUTHOR_GOOD_REPUTATION,
                self::AUTHOR_BAD_REPUTATION,
                self::AUTHOR_PARENT_BLACKLISTED,
                self::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED,
                self::AUTHOR_PARENT_WHITELISTED,
                self::AUTHOR_PARENT_GOOD_REPUTATION,
                self::AUTHOR_PARENT_BAD_REPUTATION => true,
                default => false,
            };
        }

        /**
         * Checks if this rule applies to classification-based scoring
         *
         * @return bool True if this is a classification rule, False otherwise
         */
        public function isClassificationRule(): bool
        {
            return match ($this)
            {
                self::CLASSIFICATION_NORMAL, self::CLASSIFICATION_SUSPICIOUS, self::CLASSIFICATION_MALICIOUS => true,
                default => false,
            };
        }
    }
