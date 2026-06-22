<?php

    namespace FederationLib\Enums;

    enum EntityRelationshipType : string
    {
        /**
         * Indicates the entity acts as an alternative entity for the target entity. eg; JohnDoe321@example.com is
         * an alternative account for JohnDoe123@example.com
         *
         * Unlike `PROXY` this relationship may/may not be intentionally hidden, aka not entirely confirmed.
         */
        case ALTERNATIVE = 'alternative';

        /**
         * Indicates the entity acts as a proxy for the target entity. eg; johndoe321@example.com communicates as
         * johndoe321@example.com through johndoe123@example.com
         *
         * Unlike `ALTERNATIVE` this relationship is known, aka confirmed to be true.
         */
        case PROXY = 'proxy';

        /**
         * Indicates the entity acts as a dependent entity for the target entity. eg; the target peer created a bot as
         * a dependent entity
         */
        case DEPENDENT = 'dependent';
    }
