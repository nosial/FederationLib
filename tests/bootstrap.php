<?PHP
        require 'ncc';

        $buildOutputPath = __DIR__ . DIRECTORY_SEPARATOR . '../build/release/net.nosial.federation.ncc';
        if(getenv('NCC_BUILD_OUTPUT_PATH'))
        {
            $buildOutputPath = getenv('NCC_BUILD_OUTPUT_PATH');
        }

        if(!file_exists($buildOutputPath))
        {
            throw new Exception('Build output not found: ' . $buildOutputPath);
        }

        import($buildOutputPath);