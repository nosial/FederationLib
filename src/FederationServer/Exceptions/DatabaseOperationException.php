<?php

    namespace FederationServer\Exceptions;

    use Throwable;

    class DatabaseOperationException extends \Exception
    {
        /**
         * DatabaseOperationException constructor.
         *
         * @param string $message The exception message.
         * @param int $code The exception code.
         * @param Throwable|null $previous The previous throwable used for the exception chaining.
         */
        public function __construct(string $message="", int $code=0, ?Throwable $previous=null)
        {
            parent::__construct($message, $code, $previous);
        }
    }