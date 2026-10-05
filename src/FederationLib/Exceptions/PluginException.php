<?php

    namespace FederationLib\Exceptions;

    use Exception;
    use Throwable;

    class PluginException extends Exception
    {
        /**
         * @inheritDoc
         */
        public function __construct(string $message="", int $code=0, ?Throwable $previous=null)
        {
            parent::__construct($message, $code, $previous);
        }
    }
