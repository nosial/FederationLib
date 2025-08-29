<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use Throwable;

    class DownloadAttachment extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: You must be authenticated to download attachments', 401);
            }

            if(!preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Attachment UUID required', 405);
            }

            $attachmentUuid  = $matches[1];
            if(!$attachmentUuid || !Validate::uuid($attachmentUuid))
            {
                throw new RequestException('Invalid attachment UUID', 400);
            }

            try
            {
                $attachment = FileAttachmentManager::getRecord($attachmentUuid);
                if(!$attachment)
                {
                    throw new RequestException('Attachment not found', 404);
                }

                $evidence = EvidenceManager::getEvidence($attachment->getEvidence());

                if($evidence === null)
                {
                    throw new RequestException('Associated evidence not found', 404);
                }

                if($evidence->isConfidential())
                {
                    if($authenticatedOperator === null)
                    {
                        throw new RequestException('You must be authenticated to view confidential evidence', 401);
                    }

                    if(!$authenticatedOperator->canManageBlacklist())
                    {
                        throw new RequestException('Insufficient Permissions to view confidential evidence', 401);
                    }
                }
            }
            catch(Throwable $e)
            {
                throw new RequestException('Internal server error while retrieving attachment', 500, $e);
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

