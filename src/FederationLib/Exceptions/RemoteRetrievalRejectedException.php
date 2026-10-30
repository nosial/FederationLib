<?php

    namespace FederationLib\Exceptions;

    class RemoteRetrievalRejectedException extends RequestException
    {
        /**
         * Wraps a RequestException a REMOTE_RETRIEVAL event handler threw, keeping its status code and its message as
         * it is (a RequestException's message is already prefixed with the reason phrase of its status code)
         *
         * @param RequestException $exception The exception the handler threw
         * @return RemoteRetrievalRejectedException The rejection
         */
        public static function fromRequestException(RequestException $exception): RemoteRetrievalRejectedException
        {
            $rejection = new self('', (int)$exception->getCode(), $exception);
            $rejection->message = $exception->getMessage();
            return $rejection;
        }
    }
