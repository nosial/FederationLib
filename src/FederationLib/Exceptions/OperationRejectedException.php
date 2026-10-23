<?php

    namespace FederationLib\Exceptions;

    use Exception;
    use Throwable;

    class OperationRejectedException extends Exception
    {
        /**
         * Thrown if the operation was rejected.
         *
         * @param string $message The message describing the reason for the rejection.
         * @param int $code The error code associated with the rejection.
         * @param Throwable|null $previous The previous exception, if any.
         */
        public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
        {
            parent::__construct($message, $code, $previous);
        }
    }