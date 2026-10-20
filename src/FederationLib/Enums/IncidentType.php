<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum IncidentType : string implements CaseSensitiveInterface
    {
        /**
         * Spam or automated spam content.
         */
        case SPAM = 'SPAM';

        /**
         * Scam content, such as fraudulent schemes or deceptive practices.
         */
        case SCAM = 'SCAM';

        /**
         * Abuse of the service, such as harassment or threats.
         */
        case SERVICE_ABUSE = 'SERVICE_ABUSE';

        /**
         * Illegal content, such as content that violates laws or regulations.
         */
        case ILLEGAL_CONTENT = 'ILLEGAL_CONTENT';

        /**
         * Maliciously intended to spread harmful software or viruses.
         */
        case MALWARE = 'MALWARE';

        /**
         * Phishing attempts, such as attempts to steal personal information.
         */
        case PHISHING = 'PHISHING';

        /**
         * Advertising content, or a domain or resource that serves advertisements. Unsolicited advertising sent as
         * messages is SPAM.
         */
        case ADVERTISING = 'ADVERTISING';

        /**
         * A domain or resource that collects information about users across services, such as a tracking or
         * analytics endpoint.
         */
        case TRACKING = 'TRACKING';

        /**
         * Other types of content that do not fit into the above categories.
         * Should rarely be used.
         */
        case OTHER = 'OTHER';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?IncidentType
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * Resolves an incident type received from a host, treating a value this version does not recognize as OTHER
         * as OFD "Versioning" requires, since a later minor version may add incident types.
         *
         * @param string $value The incident type value
         * @return IncidentType The incident type, OTHER if the value is not recognized
         */
        public static function fromResponse(string $value): IncidentType
        {
            return self::tryFromCaseInsensitive($value) ?? self::OTHER;
        }

        /**
         * Parses a list of incident types, given as an array of strings or as a comma-separated string, matching each
         * value case-insensitively.
         *
         * @param mixed $value The list of incident types
         * @return IncidentType[]|null The incident types without duplicates, an empty array if the list names none, or
         *                             null if the list is not of either form or names a value that is not an incident type
         */
        public static function parseList(mixed $value): ?array
        {
            if(is_string($value))
            {
                $value = array_filter(array_map('trim', explode(',', $value)), fn(string $item) => $item !== '');
            }
            elseif(!is_array($value) || !array_is_list($value))
            {
                return null;
            }

            $types = [];
            foreach($value as $item)
            {
                if(!is_string($item))
                {
                    return null;
                }

                $type = self::tryFromCaseInsensitive(trim($item));
                if($type === null)
                {
                    return null;
                }

                $types[$type->value] = $type;
            }

            return array_values($types);
        }
    }