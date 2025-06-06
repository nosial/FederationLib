<?php

    namespace FederationServer\Exceptions;

    use Exception;
    use FederationServer\Classes\Enums\HttpResponseCode;
    use Throwable;

    class RequestException extends Exception
    {
        /**
         * The HTTP status code for the error.
         *
         * @param string $message The error message.
         * @param int|HttpResponseCode $code The HTTP status code (default is 500 Internal Server Error).
         * @param Throwable|null $previous
         */
        public function __construct(string $message = "", int|HttpResponseCode $code=HttpResponseCode::INTERNAL_SERVER_ERROR, ?Throwable $previous = null)
        {
            // Construct with error code '0' always, as it will be set later.
            parent::__construct($message, 0, $previous);

            // If the code is an integer, convert it to HttpResponseCode if possible.
            if(is_int($code))
            {
                $code = HttpResponseCode::tryFrom($code);
                if($code === null)
                {
                    $code = HttpResponseCode::INTERNAL_SERVER_ERROR;
                }
            }

            $this->code = $code->value;
            $this->message = sprintf('%s: %s', $code->getErrorPrefix(), $message);
        }
    }