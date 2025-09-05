<?php

    namespace FederationLib\Exceptions;

    use Exception;
    use Throwable;

    class DatabaseOperationException extends Exception
    {
        /**
         * DatabaseOperationException constructor.
         *
         * @param string $message The exception message.
         * @param int|string $code The exception code.
         * @param Throwable|null $previous The previous throwable used for the exception chaining.
         */
        public function __construct(string $message="", int|string $code=0, ?Throwable $previous=null)
        {
            if(is_string($code))
            {
                $code = (int)$code;
            }

            parent::__construct($message, $code, $previous);
        }
    }