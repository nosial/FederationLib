<?php

    namespace FederationServer\Exceptions;

    use Exception;
    use Throwable;

    class FileUploadException extends Exception
    {
        /**
         * FileUploadException constructor.
         *
         * @param string $message The error message
         * @param int $code The error code (default is 0)
         * @param Throwable|null $previous Previous exception for chaining (default is null)
         */
        public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
        {
            parent::__construct($message, $code, $previous);
        }
    }