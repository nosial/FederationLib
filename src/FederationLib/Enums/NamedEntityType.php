<?php

    namespace FederationLib\Enums;

    use FederationLib\Classes\TelephoneNumbers;
    use FederationLib\Classes\Utilities;
    use FederationLib\Classes\Validate;
    use FederationLib\Objects\ScannedContent\ResolvedEntityPosition;

    enum NamedEntityType: string
    {
        case DOMAIN = 'domain';
        case URL = 'url';
        case EMAIL = 'email';
        case IPv4 = 'ipv4';
        case IPv6 = 'ipv6';
        case TELEPHONE = 'telephone';

        /**
         * Get the regex pattern for this entity type
         * @return string
         */
        public function getPattern(): string
        {
            return match($this)
            {
                self::DOMAIN => '/(?<![\w\-\.])(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}(?![\w\-])/i',
                self::URL => '/https?:\/\/(?:[-\w.])+(?:\:[0-9]+)?(?:\/(?:[\w\/_.])*)?(?:\?(?:[\w&=%.])*)?(?:#(?:\w)*)?(?![\w])/i',
                self::EMAIL => '/(?<![\w\-\.])(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-._]{0,61}[a-zA-Z0-9])?@(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63})(?![\w\-])/i',
                self::IPv4 => '/(?<![\d\.])(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?![\d])/i',
                self::TELEPHONE => '/(?<![\w+])\+[1-9](?:[ .\-\/()]{0,2}[0-9]){5,14}(?![0-9])/',
                self::IPv6 => '/(?<![\w:])(?:(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,7}:|(?:[0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,5}(?::[0-9a-fA-F]{1,4}){1,2}|(?:[0-9a-fA-F]{1,4}:){1,4}(?::[0-9a-fA-F]{1,4}){1,3}|(?:[0-9a-fA-F]{1,4}:){1,3}(?::[0-9a-fA-F]{1,4}){1,4}|(?:[0-9a-fA-F]{1,4}:){1,2}(?::[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:(?:(?::[0-9a-fA-F]{1,4}){1,6})|:(?:(?::[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(?::[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(?:ffff(?::0{1,4}){0,1}:){0,1}(?:(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])|(?:[0-9a-fA-F]{1,4}:){1,4}:(?:(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9]))(?![\w:])/i',
            };
        }

        /**
         * Validate if a matched string is actually a valid entity of this type
         * @param string $value
         * @return bool
         */
        public function isValid(string $value): bool
        {
            return match($this)
            {
                self::DOMAIN => Validate::domain($value),
                self::URL => Validate::url($value),
                self::EMAIL => Validate::email($value),
                self::IPv4 => Validate::ipv4($value),
                self::IPv6 => Validate::ipv6($value),
                self::TELEPHONE => Utilities::canonicalizeTelephoneNumber($value) !== null,
            };
        }

        /**
         * Get extraction priority (higher number = higher priority)
         * @return int
         */
        public function getPriority(): int
        {
            return match($this)
            {
                self::URL => 5, // Highest priority to avoid conflicts with domains in URLs
                self::EMAIL => 4, // High priority to avoid conflicts with domains
                self::IPv4 => 3, // Medium-high priority
                self::IPv6 => 3, // Medium-high priority
                self::TELEPHONE => 3, // Medium-high priority, a number never overlaps a URL or an email address
                self::DOMAIN => 2 ,// Lower priority to avoid false positives
            };
        }

        /**
         * Extract all named entities from the given content. Telephone numbers are found with libphonenumber: numbers
         * in international format always, numbers in national format only when the region the content was received in
         * is given, and only numbers that are valid for their region (OFD "ResolvedEntity object"). They are keyed by
         * their E.164 form (eg; +16502530000), so every way of writing a number resolves to the same entity.
         *
         * @param string $content The text to scan
         * @param string|null $region Optional. The ISO 3166-1 alpha-2 code of the region the content was received in
         * @return ResolvedEntityPosition[] The positions of the named entities, keyed by the entity identifier
         */
        public static function extract(string $content, ?string $region=null): array
        {
            $cases = self::cases();

            usort($cases, fn(self $a, self $b) => $b->getPriority() <=> $a->getPriority());

            $results = [];
            $reservedRanges = [];

            foreach ($cases as $type)
            {
                foreach (self::findMatches($type, $content, $region) as [$match, $offset, $length])
                {

                    $overlaps = false;
                    foreach ($reservedRanges as [$rOffset, $rLength])
                    {
                        if ($offset < $rOffset + $rLength && $offset + $length > $rOffset)
                        {
                            $overlaps = true;
                            break;
                        }
                    }

                    if ($overlaps)
                    {
                        continue;
                    }

                    if (isset($results[$match]))
                    {
                        $reservedRanges[] = [$offset, $length];
                        continue;
                    }

                    $results[$match] = new ResolvedEntityPosition($offset, $length, $type);
                    $reservedRanges[] = [$offset, $length];
                }
            }

            return $results;
        }

        /**
         * Finds the valid mentions of one named entity type in the content
         *
         * @param NamedEntityType $type The named entity type
         * @param string $content The text to scan
         * @param string|null $region The region the content was received in, used for telephone numbers
         * @return array<int, array{0: string, 1: int, 2: int}> The entity identifier, byte offset and byte length of
         *                                                       each mention
         */
        private static function findMatches(NamedEntityType $type, string $content, ?string $region): array
        {
            if ($type === self::TELEPHONE)
            {
                return array_map(
                    fn(array $number) => ['+' . $number['number'], $number['offset'], $number['length']],
                    TelephoneNumbers::extract($content, $region)
                );
            }

            if (preg_match_all($type->getPattern(), $content, $matches, PREG_OFFSET_CAPTURE) === false)
            {
                return [];
            }

            $results = [];
            foreach ($matches[0] as [$match, $offset])
            {
                if ($type->isValid($match))
                {
                    $results[] = [$match, $offset, strlen($match)];
                }
            }

            return $results;
        }
    }
