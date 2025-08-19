<?php

    namespace FederationLib\Enums;

    enum BlacklistType : string
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
         * Child Sexual Abuse Material (CSAM).
         */
        case CSAM = 'CSAM';

        /**
         * Other types of content that do not fit into the above categories.
         * Should rarely be used.
         */
        case OTHER = 'OTHER';
    }