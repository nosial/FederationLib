<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class GetAttachmentInfo extends RequestHandler
    {
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Authentication is required to view evidence attachment records.', HttpResponseCode::FORBIDDEN);
            }

            if(!preg_match('#^/attachments/([a-fA-F0-9\-]{36})/info$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Attachment UUID required', HttpResponseCode::BAD_REQUEST);
            }

            $attachmentUuid  = $matches[1];
            if(!$attachmentUuid || !Validate::uuid($attachmentUuid))
            {
                throw new RequestException('Invalid attachment UUID', HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                // Fetch the attachment record
                $attachmentRecord = FileAttachmentManager::getRecord($attachmentUuid);
                if($attachmentRecord === null)
                {
                    throw new RequestException('Attachment not found', HttpResponseCode::NOT_FOUND);
                }

                // Get the associated evidence record with the attachment
                $evidenceRecord = EvidenceManager::getEvidence($attachmentRecord->getEvidenceUuid());
                if($evidenceRecord !== null && $evidenceRecord->isConfidential())
                {
                    if($authenticatedOperator === null)
                    {
                        throw new RequestException('You must be authenticated to view confidential evidence attachments.', HttpResponseCode::FORBIDDEN);
                    }

                    if(!$authenticatedOperator->canManageBlacklist())
                    {
                        throw new RequestException('Insufficient permissions to view confidential evidence attachments.', HttpResponseCode::FORBIDDEN);
                    }
                }
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('There was an error while fetching the attachment record: ' . $e->getMessage(), HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse($attachmentRecord);
        }
    }