<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use InvalidArgumentException;

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

            $textContent = FederationServer::getParameter('text_content') ?? null;
            $note = FederationServer::getParameter('note') ?? null;
            $tag = FederationServer::getParameter('tag') ?? null;

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

                $evidenceUuid = EvidenceManager::addEvidence($entityUuid, $authenticatedOperator->getUuid(), $textContent, $note, $tag, $confidential);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_SUBMITTED, sprintf(
                    'Evidence %s created for entity %s by %s (%s)',
                    $evidenceUuid,
                    $entityUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid(), $evidenceUuid);
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Failed to create evidence', 500, $e);
            }

            self::successResponse($evidenceUuid);
        }
    }

