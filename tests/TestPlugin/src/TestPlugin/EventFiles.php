<?php

    namespace TestPlugin;

    /**
     * The files the TestPlugin records events to. They are written by the web server (www-data) and by the command
     * line (`federationlib init` and the scheduled jobs, run as root), so they are kept writable by both: in a
     * directory of their own, because the kernel (fs.protected_regular) refuses to open a file of another user for
     * writing in a sticky directory such as /tmp whatever its mode, and with the mode 0666.
     */
    class EventFiles
    {
        /**
         * Returns the path of an events file, creating the directory of the events files when it is missing
         *
         * @param string $name The file name
         * @return string The file path
         */
        public static function getPath(string $name): string
        {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'net.nosial.test_plugin';
            if(!is_dir($directory))
            {
                @mkdir($directory, 0777);
                // The mode given to mkdir() is reduced by the umask
                @chmod($directory, 0777);
            }

            return $directory . DIRECTORY_SEPARATOR . $name;
        }

        /**
         * Opens an events file for reading and writing, creating it writable by every user when it is missing
         *
         * @param string $path The file path
         * @return resource|false The file handle, false if the file could not be opened
         */
        public static function open(string $path): mixed
        {
            $handle = fopen($path, 'c+');
            if($handle !== false)
            {
                self::share($path);
            }

            return $handle;
        }

        /**
         * Makes an events file writable by every user, only its owner can change its mode
         *
         * @param string $path The file path
         * @return void
         */
        public static function share(string $path): void
        {
            @chmod($path, 0666);
        }
    }
