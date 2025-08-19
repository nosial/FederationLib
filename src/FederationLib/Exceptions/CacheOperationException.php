<?php

    namespace FederationLib\Exceptions;

    use Throwable;

    class CacheOperationException extends DatabaseOperationException
    {
        /**
         * @inheritDoc
         */
        public function __construct(string $message="", int $code=0, ?Throwable $previous=null)
        {
            parent::__construct($message, $code, $previous);
        }
    }