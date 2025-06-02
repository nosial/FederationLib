<?php

    namespace FederationServer\Methods;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\Managers\FileAttachmentManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;
    use Throwable;

    class DownloadAttachment extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            if(!preg_match('#^/attachment/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Attachment UUID required', 405);
            }

            $uuid = $matches[1];
            if(!$uuid || !Validate::uuid($uuid))
            {
                throw new RequestException('Invalid attachment UUID', 400);
            }

            try
            {
                $attachment = FileAttachmentManager::getRecord($uuid);
                if(!$attachment)
                {
                    throw new RequestException('Attachment not found', 404);
                }

                $evidence = EvidenceManager::getEvidence($attachment->getEvidence());
                if($evidence && $evidence->isConfidential())
                {
                    // Require authentication if confidential
                    $operator = FederationServer::getAuthenticatedOperator();
                    if(!$operator->canManageBlacklist())
                    {
                        throw new RequestException('Insufficient Permissions to view confidential evidence', 401);
                    }
                }
            }
            catch(Throwable $e)
            {
                throw new RequestException('Error retrieving attachment: ' . $e->getMessage(), 500, $e);
            }

            $fileLocation = Configuration::getServerConfiguration()->getStoragePath() . DIRECTORY_SEPARATOR . $attachment->getUuid();
            if(!file_exists($fileLocation))
            {
                throw new RequestException('Attachment file not found', 404);
            }

            // Set headers for file download
            header('Content-Type: ' . $attachment->getFileMime());
            header('Content-Disposition: attachment; filename="' . $attachment->getFileName() . '"');
            header('Content-Length: ' . filesize($fileLocation));
            header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1
            header('Pragma: no-cache'); // HTTP 1.0
            header('Expires: 0'); // Proxies

            $chunkSize = 8192; // 8KB per chunk
            $handle = fopen($fileLocation, 'rb');

            if ($handle === false)
            {
                throw new RequestException('Failed to open attachment file for reading', 500);
            }

            while (!feof($handle))
            {
                $buffer = fread($handle, $chunkSize);
                if ($buffer === false)
                {
                    fclose($handle);
                    throw new RequestException('Error reading attachment file', 500);
                }

                print($buffer);

                // Flush output buffers to send data immediately
                if (ob_get_level())
                {
                    ob_flush();
                }

                flush();
            }

            fclose($handle);
        }
    }

