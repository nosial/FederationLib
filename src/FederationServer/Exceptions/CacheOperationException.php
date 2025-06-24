<?php

    namespace FederationServer\Exceptions;

    use Exception;
    use Throwable;

    class CacheOperationException extends Exception
    {
        /**
         * @inheritDoc
         */
        public function __construct(string $message="", int $code=0, ?Throwable $previous=null)
        {
            parent::__construct($message, $code, $previous);
        }
    }