<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Configuration;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Enums\HttpResponseCode;
    use Symfony\Component\Uid\Uuid;

    class UploadHandler
    {
        /**
         * Validates and processes an uploaded file, moving it to a temporary location in the storage directory.
         *
         * @return array{uuid: string, tmp_name: string, temp_destination: string, destination_path: string, mime_type: string, original_name: string, size: int}
         * @throws RequestException If the file upload is invalid or cannot be processed.
         */
        public static function validateUpload(): array
        {
            if(!isset($_FILES['file']))
            {
                throw new RequestException('File upload is required', HttpResponseCode::BAD_REQUEST);
            }

            $file = $_FILES['file'];
            if(!is_array($file) || empty($file['tmp_name']) || is_array($file['tmp_name']))
            {
                throw new RequestException('Invalid file upload or multiple files detected', HttpResponseCode::BAD_REQUEST);
            }

            if(!isset($file['size']) || $file['size'] <= 0)
            {
                throw new RequestException('Invalid file size', HttpResponseCode::BAD_REQUEST);
            }

            if($file['size'] > Configuration::getServerConfiguration()->getMaxUploadSize())
            {
                throw new RequestException(
                    sprintf("File exceeds maximum allowed size (%d bytes)", Configuration::getServerConfiguration()->getMaxUploadSize()),
                    HttpResponseCode::BAD_REQUEST
                );
            }

            if(!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK)
            {
                throw new RequestException(self::getUploadErrorMessage($file['error'] ?? -1), HttpResponseCode::BAD_REQUEST);
            }

            if(!is_file($file['tmp_name']) || !is_readable($file['tmp_name']))
            {
                throw new RequestException('Uploaded file is not accessible', HttpResponseCode::BAD_REQUEST);
            }

            $detectedMimeType = self::detectMimeType($file['tmp_name']);
            $originalName = self::getSafeFileName($file['name'] ?? '');
            if(empty($originalName) || $originalName === 'unnamed')
            {
                $originalName = Uuid::v7()->toRfc4122();
            }

            if(is_link($file['tmp_name']))
            {
                throw new RequestException('Invalid file upload (symbolic link detected)', HttpResponseCode::BAD_REQUEST);
            }

            $realpath = realpath($file['tmp_name']);
            if($realpath === false || !str_starts_with($realpath, sys_get_temp_dir()))
            {
                throw new RequestException('Path traversal attempt detected', HttpResponseCode::BAD_REQUEST);
            }

            $storagePath = rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR);
            if(!is_dir($storagePath))
            {
                if(!mkdir($storagePath, 0750, true))
                {
                    throw new RequestException('Storage directory could not be created', HttpResponseCode::INTERNAL_SERVER_ERROR);
                }
            }

            if(!is_writable($storagePath))
            {
                throw new RequestException('Storage directory is not writable', HttpResponseCode::INTERNAL_SERVER_ERROR);
            }

            $uuid = Uuid::v7()->toRfc4122();
            $destinationPath = $storagePath . DIRECTORY_SEPARATOR . $uuid;
            $tempDestination = $storagePath . DIRECTORY_SEPARATOR . uniqid('tmp_', true);

            if(!move_uploaded_file($file['tmp_name'], $tempDestination))
            {
                throw new RequestException('Failed to move uploaded file', HttpResponseCode::INTERNAL_SERVER_ERROR);
            }

            return [
                'uuid' => $uuid,
                'tmp_name' => $file['tmp_name'],
                'temp_destination' => $tempDestination,
                'destination_path' => $destinationPath,
                'mime_type' => $detectedMimeType,
                'original_name' => $originalName,
                'size' => $file['size'],
            ];
        }

        /**
         * Finalizes the upload by setting permissions and moving the file to its final destination.
         *
         * @param array{uuid: string, tmp_name: string, temp_destination: string, destination_path: string, mime_type: string, original_name: string, size: int} $uploadInfo The upload info from validateUpload()
         * @return string The final destination path of the file
         * @throws RequestException If the file cannot be finalized
         */
        public static function finalizeUpload(array $uploadInfo): string
        {
            chmod($uploadInfo['temp_destination'], 0640);

            if(!rename($uploadInfo['temp_destination'], $uploadInfo['destination_path']))
            {
                throw new RequestException('Failed to finalize file upload', HttpResponseCode::INTERNAL_SERVER_ERROR);
            }

            return $uploadInfo['destination_path'];
        }

        /**
         * Cleans up temporary files from the upload process.
         *
         * @param array{uuid: string, tmp_name: string, temp_destination: string, destination_path: string, mime_type: string, original_name: string, size: int} $uploadInfo The upload info from validateUpload()
         */
        public static function cleanupTempFiles(array $uploadInfo): void
        {
            if(file_exists($uploadInfo['temp_destination']))
            {
                @unlink($uploadInfo['temp_destination']);
            }

            if(isset($uploadInfo['tmp_name']) && file_exists($uploadInfo['tmp_name']))
            {
                @unlink($uploadInfo['tmp_name']);
            }
        }

        /**
         * Get human-readable error message for PHP upload error codes
         */
        private static function getUploadErrorMessage(int $errorCode): string
        {
            return match ($errorCode)
            {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
                default => 'Unknown upload error',
            };
        }

        /**
         * Safely detect the MIME type of a file
         */
        private static function detectMimeType(string $filePath): string
        {
            if(function_exists('finfo_open'))
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $filePath);
                if($mimeType)
                {
                    return $mimeType;
                }
            }

            if(function_exists('mime_content_type'))
            {
                $mimeType = mime_content_type($filePath);
                if($mimeType)
                {
                    return $mimeType;
                }
            }

            return 'application/octet-stream';
        }

        /**
         * Get a safe filename by removing potentially unsafe characters
         */
        private static function getSafeFileName(string $filename): string
        {
            $filename = basename($filename);
            $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

            if(strlen($filename) > 255)
            {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
                $filename = substr($baseFilename, 0, 245) . '.' . $extension;
            }

            return $filename;
        }
    }