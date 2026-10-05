<?php

    namespace FederationLib\Enums;

    use FederationLib\Interfaces\CaseSensitiveInterface;

    enum ExecutionPriority : string implements CaseSensitiveInterface
    {
        /**
         * Executed before the request handler, may respond (or throw) to prevent the request handler from executing
         */
        case PRE_REQUEST = 'PRE_REQUEST';

        /**
         * Executed after the request handler has responded
         */
        case POST_REQUEST = 'POST_REQUEST';

        /**
         * Executed instead of the request handler, the original request handler is never executed
         */
        case OVERRIDE = 'OVERRIDE';

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?ExecutionPriority
        {
            return self::tryFrom(strtoupper(trim($value)));
        }
    }
