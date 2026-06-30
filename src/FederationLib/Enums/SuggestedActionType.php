<?php

    namespace FederationLib\Enums;

    enum SuggestedActionType : string
    {
        /**
         * Indicates the suggested action should be to permanently block the entity from the channel/server due to
         * the entity having one or more permanent blacklist records
         */
        case PERMANENTLY_BLOCK_ENTITY = 'PERMANENTLY_BLOCK_ENTITY';

        /**
         * Indicates the suggested action should be to temporarily block the entity from the channel/server due to
         * the entity having one or more temporary blacklist records
         */
        case TEMPORARILY_BLOCK_ENTITY = 'TEMPORARILY_BLOCK_ENTITY';

        /**
         * Indicates the suggested action should be to block the content due to possibly malicious content that cannot be
         * verified to be safe
         */
        case BLOCK_CONTENT = 'BLOCK_CONTENT';

        /**
         * Indicates the suggested action should be to create an alert indicator about the offending content
         * to be possibly malicious, but not enough reason to block the content or entity entirely.
         */
        case CAUTION = 'CAUTION';

    }
