<?php

    namespace FederationServer\Classes;

    use Exception;
    use FederationServer\Classes\Managers\FileAttachmentManager;
    use FederationServer\Exceptions\FileUploadException;
    use Symfony\Component\Uid\Uuid;
    use InvalidArgumentException;

    class FileUploadHandler
    {
        /**
         * Handle a file upload request.
         *
         * @param array $file The $_FILES array element for the uploaded file
         * @param string $evidence The UUID of the evidence this file is attached to
         * @return void Information about the uploaded file, including UUID and filename
         * @throws FileUploadException If there's an issue with the upload
         */
        public static function handleUpload(array $file, string $evidence): void
        {
            if (!isset($file) || !is_array($file) || !isset($file['tmp_name']) || empty($file['tmp_name']))
            {
                throw new InvalidArgumentException('Invalid file upload data provided');
            }

            if (empty($evidence))
            {
                throw new InvalidArgumentException('Evidence ID is required');
            }

            // Validate the file size
            if (!isset($file['size']) || $file['size'] <= 0)
            {
                throw new FileUploadException('Invalid file size');
            }

            if ($file['size'] > Configuration::getServerConfiguration()->getMaxUploadSize())
            {
                throw new FileUploadException('File exceeds maximum allowed size');
            }

            // Validate file upload status
            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK)
            {
                $errorMessage = self::getUploadErrorMessage($file['error'] ?? -1);
                throw new FileUploadException($errorMessage);
            }

            $detectedMimeType = self::detectMimeType($file['tmp_name']);
            $uuid = Uuid::v4()->toRfc4122();
            $originalName = self::getSafeFileName($file['name'] ?? 'unnamed');

            // Get file storage path and ensure the directory exists
            $storagePath = rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR);
            if (!is_dir($storagePath))
            {
                if (!mkdir($storagePath, 0750, true))
                {
                    throw new FileUploadException('Storage directory could not be created');
                }
            }

            // Prepare destination path (UUID only, no extension as per requirements)
            $destinationPath = $storagePath . DIRECTORY_SEPARATOR . $uuid;
            if (!move_uploaded_file($file['tmp_name'], $destinationPath))
            {
                throw new FileUploadException('Failed to save uploaded file');
            }

            chmod($destinationPath, 0640);

            try
            {
                // Create a record in the database
                FileAttachmentManager::createRecord($uuid, $evidence, $detectedMimeType, $originalName, $file['size']);
            }
            catch (Exception $e)
            {
                // If database insertion fails, remove the file to maintain consistency
                @unlink($destinationPath);
                throw new FileUploadException('Failed to record file upload: ' . $e->getMessage());
            }
            finally
            {
                // Clean up temporary files if needed
                if (file_exists($file['tmp_name']))
                {
                    @unlink($file['tmp_name']);
                }
            }
        }

        /**
         * Get human-readable error message for PHP upload error codes
         *
         * @param int $errorCode PHP upload error code
         * @return string Human-readable error message
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
         *
         * @param string $filePath Path to the file
         * @return string The detected MIME type
         */
        private static function detectMimeType(string $filePath): string
        {
            // Using multiple methods for better reliability

            // First try with Fileinfo extension (most reliable)
            if (function_exists('finfo_open'))
            {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mimeType)
                {
                    return $mimeType;
                }
            }

            // Then try with mime_content_type
            if (function_exists('mime_content_type'))
            {
                $mimeType = mime_content_type($filePath);
                if ($mimeType)
                {
                    return $mimeType;
                }
            }

            // Fall back to a simple extension-based check as last resort
            return 'application/octet-stream';
        }

        /**
         * Get a safe filename by removing potentially unsafe characters
         *
         * @param string $filename Original filename
         * @return string Sanitized filename
         */
        private static function getSafeFileName(string $filename): string
        {
            // Remove any path information to avoid directory traversal
            $filename = basename($filename);

            // Remove null bytes and other control characters
            $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);

            // Limit length to avoid extremely long filenames
            if (strlen($filename) > 255)
            {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
                $filename = substr($baseFilename, 0, 245) . '.' . $extension;
            }

            return $filename;
        }
    }


