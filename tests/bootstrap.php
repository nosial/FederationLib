<?php

    require 'ncc';

    $buildOutputPath = __DIR__ . DIRECTORY_SEPARATOR . '../target/release/net.nosial.federation.ncc';
    if (getenv('NCC_BUILD_OUTPUT_PATH'))
    {
        $buildOutputPath = getenv('NCC_BUILD_OUTPUT_PATH');
    }

    if (!file_exists($buildOutputPath))
    {
        throw new Exception('Build output not found: ' . $buildOutputPath);
    }

    // Import the build output
    import($buildOutputPath);

    // Import helper classes
    require __DIR__ . DIRECTORY_SEPARATOR . 'FederationLib' . DIRECTORY_SEPARATOR . 'Helpers' . DIRECTORY_SEPARATOR . 'TextGenerator.php';
    require __DIR__ . DIRECTORY_SEPARATOR . 'FederationLib' . DIRECTORY_SEPARATOR . 'Helpers' . DIRECTORY_SEPARATOR . 'Logger.php';
    require __DIR__ . DIRECTORY_SEPARATOR . 'FederationLib' . DIRECTORY_SEPARATOR . 'Helpers' . DIRECTORY_SEPARATOR . 'TestHelpers.php';

    // LogLib2's handlers may interfere with tests, so we unregister them here.
    \LogLib2\Logger::unregisterHandlers();