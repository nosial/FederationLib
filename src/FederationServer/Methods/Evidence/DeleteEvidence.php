<?php

    namespace FederationServer\Methods\Evidence;

    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Enums\AuditLogType;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class DeleteEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('You do not have permission to delete evidence', 403);
            }

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Evidence UUID required', 405);
            }

            $evidenceUuid = $matches[1];
            if(!$evidenceUuid || !Validate::uuid($evidenceUuid))
            {
                throw new RequestException('Invalid evidence UUID', 400);
            }

            try
            {
                if(!EvidenceManager::evidenceExists($evidenceUuid))
                {
                    throw new RequestException('Evidence Not Found', 404);
                }

                EvidenceManager::deleteEvidence($evidenceUuid);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_DELETED, sprintf(
                    'Evidence %s deleted by %s (%s)',
                    $evidenceUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid(), $evidenceUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to delete evidence', 500, $e);
            }

            self::successResponse();
        }
    }

