<?php

    namespace FederationServer\Methods\Evidence;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class SubmitEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('You do not have permission to create evidence', 403);
            }

            $entityUuid = FederationServer::getParameter('entity_uuid');
            if(!$entityUuid || !Validate::uuid($entityUuid))
            {
                throw new RequestException('Entity UUID is required and must be valid', 400);
            }

            $textContent = FederationServer::getParameter('text_content');
            if(!is_null($textContent) && strlen($textContent) > 65535)
            {
                throw new RequestException('Text content must not exceed 65535 characters', 400);
            }

            $note = FederationServer::getParameter('note');
            if(!is_null($note) && strlen($note) > 65535)
            {
                throw new RequestException('Note must not exceed 65535 characters', 400);
            }

            $confidential = false;
            if(FederationServer::getParameter('confidential') === 'true')
            {
                $confidential = true;
            }

            try
            {
                if(!EntitiesManager::getEntityByUuid($entityUuid))
                {
                    throw new RequestException('Entity does not exist', 404);
                }

                $evidenceUuid = EvidenceManager::addEvidence($entityUuid, $authenticatedOperator->getUuid(), $textContent, $note, $confidential);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_SUBMITTED, sprintf(
                    'Evidence %s created for entity %s by %s (%s)',
                    $evidenceUuid,
                    $entityUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid(), $evidenceUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Failed to create evidence', 500, $e);
            }

            self::successResponse($evidenceUuid);
        }
    }

