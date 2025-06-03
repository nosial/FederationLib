<?php

    namespace FederationServer\Methods\Attachments;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\Managers\FileAttachmentManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;
    use FilesystemIterator;
    use Symfony\Component\Uid\Uuid;
    use Throwable;

    class UploadAttachment extends RequestHandler
    {
        // Maximum number of files allowed in the storage directory
        private const MAX_FILES = 10000;

        /**
         * @inheritDoc
         * @throws RequestException
         */
        public static function handleRequest(): void
        {
            $evidenceUuid = FederationServer::getParameter('evidence');
            if($evidenceUuid === null)
            {
                throw new RequestException('Evidence UUID is required', 400);
            }

            // Validate evidence UUID exists
            if(!Validate::uuid($evidenceUuid) || !EvidenceManager::evidenceExists($evidenceUuid))
            {
                throw new RequestException('Invalid Evidence UUID', 400);
            }

            try
            {
                $evidence = EvidenceManager::getEvidence($evidenceUuid);
                if($evidence === null)
                {
                    throw new RequestException('Evidence not found', 404);
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Evidence not found or database error', 404, $e);
            }

            $operator = FederationServer::getAuthenticatedOperator();
            if(!$operator->canManageBlacklist())
            {
                throw new RequestException('Insufficient Permissions to upload attachments', 403);
            }

            // Verify the file upload field exists
            if(!isset($_FILES['file']))
            {
                throw new RequestException('File upload is required', 400);
            }

            // Ensure only a single file is uploaded
            $file = $_FILES['file'];
            if (!is_array($file) || !isset($file['tmp_name']) || empty($file['tmp_name']) || is_array($file['tmp_name']))
            {
                throw new RequestException('Invalid file upload or multiple files detected', 400);
            }

            // Validate the file size
            if (!isset($file['size']) || $file['size'] <= 0)
            {
                throw new RequestException('Invalid file size');
            }

            $maxUploadSize = Configuration::getServerConfiguration()->getMaxUploadSize();
            if ($file['size'] > $maxUploadSize)
            {
                throw new RequestException("File exceeds maximum allowed size ({$maxUploadSize} bytes)", 401);
            }

            // Validate file upload status
            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK)
            {
                $errorMessage = self::getUploadErrorMessage($file['error'] ?? -1);
                throw new RequestException($errorMessage);
            }

            // Validate file exists and is readable
            if (!is_file($file['tmp_name']) || !is_readable($file['tmp_name']))
            {
                throw new RequestException('Uploaded file is not accessible');
            }

            $detectedMimeType = self::detectMimeType($file['tmp_name']);
            $originalName = self::getSafeFileName($file['name'] ?? 'unnamed');

            // Check for symlinks/hardlinks in tmp_name
            if (is_link($file['tmp_name']))
            {
                throw new RequestException('Invalid file upload (symbolic link detected)');
            }

            // Additional check for path traversal attempts
            $realpath = realpath($file['tmp_name']);
            if ($realpath === false || strpos($realpath, sys_get_temp_dir()) !== 0)
            {
                throw new RequestException('Path traversal attempt detected');
            }

            // Get file storage path and ensure the directory exists
            $storagePath = rtrim(Configuration::getServerConfiguration()->getStoragePath(), DIRECTORY_SEPARATOR);
            if (!is_dir($storagePath))
            {
                if (!mkdir($storagePath, 0750, true))
                {
                    throw new RequestException('Storage directory could not be created');
                }
            }

            // Verify storage directory permissions
            if (!is_writable($storagePath))
            {
                throw new RequestException('Storage directory is not writable');
            }

            // Limit number of files in storage directory (prevent DoS)
            $fileCount = iterator_count(new FilesystemIterator($storagePath, FilesystemIterator::SKIP_DOTS));
            if ($fileCount >= self::MAX_FILES)
            {
                throw new RequestException('Storage limit reached');
            }

            // Generate a strong random UUID for the file
            $uuid = Uuid::v4()->toRfc4122();

            // Prepare destination path (UUID only, no extension as per requirements)
            $destinationPath = $storagePath . DIRECTORY_SEPARATOR . $uuid;

            // Use atomic operations where possible
            $tempDestination = $storagePath . DIRECTORY_SEPARATOR . uniqid('tmp_', true);

            if (!move_uploaded_file($file['tmp_name'], $tempDestination))
            {
                throw new RequestException('Failed to move uploaded file');
            }

            try
            {
                // Set restrictive permissions before moving to final destination
                chmod($tempDestination, 0640);

                // Move to final destination
                if (!rename($tempDestination, $destinationPath))
                {
                    throw new RequestException('Failed to finalize file upload');
                }

                // Create a record in the database
                FileAttachmentManager::createRecord($uuid, $evidenceUuid, $detectedMimeType, $originalName, $file['size']);

                // Log upload success
                AuditLogManager::createEntry(AuditLogType::ATTACHMENT_UPLOADED, sprintf('Operator %s uploaded file %s (%s %s) Type %s | For Evidence %s',
                    $operator->getName(), $uuid, $originalName, $file['size'], $detectedMimeType, $evidenceUuid
                ), $operator->getUuid(), $evidence->getEntity());

                self::successResponse([
                    'uuid' => $uuid,
                    'url' => Configuration::getServerConfiguration()->getBaseUrl() . '/attachment/' . $uuid
                ]);
            }
            catch (DatabaseOperationException $e)
            {
                // If database insertion fails, remove the file to maintain consistency
                @unlink($destinationPath);

                Logger::log()->error(sprintf('Failed to record file upload for evidence %s: %s', $evidenceUuid, $e->getMessage()), $e);
                throw new RequestException('Failed to record file upload: ' . $e->getMessage());
            }
            catch (Throwable $e) {
                // Handle any other unexpected errors
                @unlink($destinationPath);
                Logger::log()->error(sprintf('Unexpected error during file upload for evidence %s: %s', $evidenceUuid, $e->getMessage()));
                throw new RequestException('Unexpected error during file upload: ' . $e->getMessage());
            }
            finally
            {
                // Clean up temporary files
                if (file_exists($tempDestination))
                {
                    @unlink($tempDestination);
                }

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

            // Remove potentially dangerous characters
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

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

