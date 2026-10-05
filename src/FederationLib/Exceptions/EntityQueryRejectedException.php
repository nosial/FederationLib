<?php

    namespace FederationLib\Exceptions;

    /**
     * Thrown by a QUERY_ENTITY event handler to reject a query entity request, the client receives an error response
     * with the exception's HTTP status code
     */
    class EntityQueryRejectedException extends RequestException
    {
    }
