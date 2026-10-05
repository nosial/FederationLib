<?php

    namespace FederationLib\Exceptions;

    /**
     * Thrown by a CONTENT_SCAN event handler to reject a content scan request, the client receives an error response
     * with the exception's HTTP status code and nothing about the scan is recorded
     */
    class ContentScanRejectedException extends RequestException
    {
    }
