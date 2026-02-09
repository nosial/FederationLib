<?PHP
        require 'ncc';

        $buildOutputPath = __DIR__ . DIRECTORY_SEPARATOR . '../target/release/net.nosial.federation.ncc';
        if(getenv('NCC_BUILD_OUTPUT_PATH'))
        {
            $buildOutputPath = getenv('NCC_BUILD_OUTPUT_PATH');
        }

        if(!file_exists($buildOutputPath))
        {
            throw new Exception('Build output not found: ' . $buildOutputPath);
        }

        // Import the build output
        import($buildOutputPath);

        // LogLib2's handlers may interfere with tests, so we unregister them here.
        \LogLib2\Logger::unregisterHandlers();