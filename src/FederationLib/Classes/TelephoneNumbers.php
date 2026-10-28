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
         * The size in bytes after which a text is split into chunks for libphonenumber (see getChunks())
         */
        private const int CHUNK_SIZE = 512;

        /**
         * The characters a text is split at for libphonenumber, none of which a candidate can contain: line breaks,
         * and the ASCII letters, in either case, that are in none of the words of an extension (ext, extension, xtn,
         * int, anexo), the only words a candidate can contain.
         */
        private const string CHUNK_BOUNDARY = '/[\r\nbcdfghjklmpqruvwyzBCDFGHJKLMPQRUVWYZ]/';

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
            $region = self::normalizeRegion($region);
            foreach(self::getChunks($text) as [$chunkOffset, $chunk])
            {
                // A telephone number has at least one digit
                if(preg_match('/\p{Nd}/u', $chunk) !== 1)
                {
                    continue;
                }

                // In ASCII text a character offset is the byte offset, so the text is not measured for every number
                $ascii = preg_match('/[\x80-\xFF]/', $chunk) !== 1;
                foreach(self::getUtil()->findNumbers($chunk, $region, Leniency::VALID()) as $match)
                {
                    $localPart = self::toLocalPart($match->number());
                    if($localPart === null)
                    {
                        continue;
                    }

                    // libphonenumber reports offsets in characters, entity positions are byte offsets
                    $results[] = [
                        'offset' => $chunkOffset + ($ascii ? $match->start() : strlen(mb_substr($chunk, 0, $match->start(), 'UTF-8'))),
                        'length' => strlen($match->rawString()),
                        'number' => $localPart,
                    ];
                }
            }

            return $results;
        }

        /**
         * Splits a text into chunks of about CHUNK_SIZE bytes in which libphonenumber finds exactly the numbers it
         * finds in the whole text. libphonenumber copies the remainder of the text for every candidate it examines, so
         * the time it takes grows with the square of the length of the text, and a large text full of digits (eg; a
         * list of addresses) would take seconds to scan.
         *
         * A chunk ends with a character that no candidate of libphonenumber can contain (see CHUNK_BOUNDARY), and
         * the next chunk starts with that same character. Every candidate is therefore wholly within one chunk, and
         * the characters around it that libphonenumber examines (the previous and next character, and the text that
         * follows a time stamp) are the same as in the whole text.
         *
         * @param string $text The text
         * @return array<int, array{0: int, 1: string}> The byte offset of each chunk in the text, and the chunk
         */
        private static function getChunks(string $text): array
        {
            // Text that is not valid UTF-8 cannot be split at a character boundary safely, libphonenumber is given it
            // whole as before
            if(strlen($text) <= self::CHUNK_SIZE * 2 || !mb_check_encoding($text, 'UTF-8'))
            {
                return [[0, $text]];
            }

            $chunks = [];
            $start = 0;
            $length = strlen($text);
            while($start < $length)
            {
                // A boundary is an ASCII byte, which in UTF-8 is never part of another character
                $boundary = null;
                if($start + self::CHUNK_SIZE < $length && preg_match(self::CHUNK_BOUNDARY, $text, $matches, PREG_OFFSET_CAPTURE, $start + self::CHUNK_SIZE) === 1)
                {
                    $boundary = $matches[0][1];
                }

                if($boundary === null)
                {
                    $chunks[] = [$start, substr($text, $start)];
                    break;
                }

                $chunks[] = [$start, substr($text, $start, $boundary - $start + 1)];
                $start = $boundary;
            }

            return $chunks;
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
