<?php

    namespace FederationServer\Methods\Attachments;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\Managers\FileAttachmentManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

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

            if(!preg_match('#^/attachment/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
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

                OperatorManager::deleteOperator($attachmentUuid);
                AuditLogManager::createEntry(AuditLogType::ATTACHMENT_DELETED, sprintf('Operator %s deleted attachment %s',
                    $authenticatedOperator->getUuid(),
                    $attachmentUuid
                ), $authenticatedOperator->getUuid(), $existingEvidence->getEntity());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to create operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }
    }