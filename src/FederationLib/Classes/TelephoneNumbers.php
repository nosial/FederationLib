<?php

    namespace FederationLib\Classes;

    use libphonenumber\Leniency;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumber;
    use libphonenumber\PhoneNumberFormat;
    use libphonenumber\PhoneNumberUtil;

    class TelephoneNumbers
    {
        /**
         * The region libphonenumber uses when none is known, only numbers in international format can be parsed
         */
        private const string UNKNOWN_REGION = 'ZZ';

        /**
         * Converts a telephone number to the canonical local part of its telephone entity, the E.164 number without
         * the leading '+' (eg; "+1 650-253-0000" becomes "16502530000"). A number in national format is interpreted
         * with the given region, without a region only numbers in international format are accepted.
         *
         * @param string $number The telephone number as written
         * @param string|null $region Optional. The ISO 3166-1 alpha-2 code of the region the number was received in
         * @return string|null The canonical local part, or null if the number cannot be parsed or is not a valid
         *                     number of its region
         */
        public static function canonicalize(string $number, ?string $region=null): ?string
        {
            try
            {
                $phoneNumber = self::getUtil()->parse($number, self::normalizeRegion($region));
            }
            catch(NumberParseException)
            {
                return null;
            }

            return self::toLocalPart($phoneNumber);
        }

        /**
         * Finds the valid telephone numbers in a text. Numbers in international format are always found, numbers in
         * national format only when the region the text was received in is given. Only numbers that are valid for
         * their region are returned, so that digit sequences that are not telephone numbers, such as one-time codes
         * or order numbers, are not mistaken for one.
         *
         * @param string $text The text to search
         * @param string|null $region Optional. The ISO 3166-1 alpha-2 code of the region the text was received in
         * @return array<int, array{offset: int, length: int, number: string}> The numbers found, with the byte offset
         *                                                                      and length of each mention and its
         *                                                                      canonical local part
         */
        public static function extract(string $text, ?string $region=null): array
        {
            $results = [];
            foreach(self::getUtil()->findNumbers($text, self::normalizeRegion($region), Leniency::VALID()) as $match)
            {
                $localPart = self::toLocalPart($match->number());
                if($localPart === null)
                {
                    continue;
                }

                // libphonenumber reports offsets in characters, entity positions are byte offsets
                $results[] = [
                    'offset' => strlen(mb_substr($text, 0, $match->start(), 'UTF-8')),
                    'length' => strlen($match->rawString()),
                    'number' => $localPart,
                ];
            }

            return $results;
        }

        /**
         * Returns the canonical local part of a parsed number, the E.164 format without the leading '+'
         *
         * @param PhoneNumber $phoneNumber The parsed number
         * @return string|null The canonical local part, or null if the number is not valid for its region
         */
        private static function toLocalPart(PhoneNumber $phoneNumber): ?string
        {
            if(!self::getUtil()->isValidNumber($phoneNumber))
            {
                return null;
            }

            $localPart = ltrim(self::getUtil()->format($phoneNumber, PhoneNumberFormat::E164), '+');
            return Validate::telephoneNumber($localPart) ? $localPart : null;
        }

        /**
         * Normalizes a region code for libphonenumber, which expects an uppercase ISO 3166-1 alpha-2 code
         *
         * @param string|null $region The region code
         * @return string The region code, or the unknown region if none or an invalid one was given
         */
        private static function normalizeRegion(?string $region): string
        {
            if($region === null || preg_match('/^[A-Za-z]{2}$/', $region) !== 1)
            {
                return self::UNKNOWN_REGION;
            }

            return strtoupper($region);
        }

        /**
         * Returns the libphonenumber instance
         *
         * @return PhoneNumberUtil The instance
         */
        private static function getUtil(): PhoneNumberUtil
        {
            return PhoneNumberUtil::getInstance();
        }
    }
