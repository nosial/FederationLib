<?php

    # This is the bootstrap file for FederationLib's HTTP API Handler, this is designed to be used with any
    # compatible web server such as Apache2/Nginx or anything that can handle HTTP requests,
    #
    # All HTTP Requests must be directed to this single file, path matching is handled by FederationLib

    use FederationLib\Classes\Logger;
    use FederationLib\FederationServer;

    // Require ncc runtime
    require 'ncc';

    // Import federation server
    import('net.nosial.federation');

    try
    {
        // Handle the request
        FederationServer::handleRequest();
    }
    catch(Exception $e)
    {
        // Handle any unhandled exceptions.
        Logger::log()->error('Failed to handle main request due to an unhandled exception', $e);
        http_response_code(500);
        print('Internal Server Error');
    }

