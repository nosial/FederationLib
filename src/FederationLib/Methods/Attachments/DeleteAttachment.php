<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use InvalidArgumentException;

    class DeleteAttachment extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            // Ensure the authenticated operator has permission to delete operators.
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('Insufficient permissions to delete attachments', 403);
            }

            if(!preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Attachment UUID required', 400);
            }

            $attachmentUuid = $matches[1];
            if(!$attachmentUuid | !Validate::uuid($attachmentUuid))
            {
                throw new RequestException('Invalid attachment UUID', 400);
            }

            try
            {
                $existingAttachment = FileAttachmentManager::getRecord($attachmentUuid);
                if($existingAttachment === null)
                {
                    throw new RequestException('Attachment not found', 404);
                }

                $existingEvidence = EvidenceManager::getEvidence($existingAttachment->getEvidence());
                if($existingEvidence === null)
                {
                    throw new RequestException('Associated evidence not found', 404);
                }

                FileAttachmentManager::deleteRecord($attachmentUuid); // This will delete the file automatically
                AuditLogManager::createEntry(AuditLogType::ATTACHMENT_DELETED, sprintf('Operator %s deleted attachment %s',
                    $authenticatedOperator->getUuid(),
                    $attachmentUuid
                ), $authenticatedOperator->getUuid(), $existingEvidence->getEntity());
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to delete file attachment', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }
    }
