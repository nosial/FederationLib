<?php

    namespace FederationServer\Methods\Blacklist;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\BlacklistManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class BlacklistAttachEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('Insufficient permissions to manage the blacklist', 401);
            }

            if(!preg_match('#^/blacklist/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Blacklist UUID required', 405);
            }

            $blacklistUuid = $matches[1];
            if(!$blacklistUuid || !Validate::uuid($blacklistUuid))
            {
                throw new RequestException('Invalid blacklist UUID', 400);
            }

            $evidenceUuid = FederationServer::getParameter('evidence');
            if($evidenceUuid !== null && !Validate::uuid($evidenceUuid))
            {
                throw new RequestException('Evidence must be a valid UUID', 400);
            }

            try
            {
                $blacklistRecord = BlacklistManager::getBlacklistEntry($blacklistUuid);
                if($blacklistRecord === null)
                {
                    throw new RequestException('Blacklist record not found', 404);
                }

                $evidenceRecord = EvidenceManager::getEvidence($blacklistUuid);
                if($evidenceRecord !== null)
                {
                    throw new RequestException('Blacklist record already has evidence attached', 400);
                }

                BlacklistManager::attachEvidence($blacklistUuid, $evidenceUuid);
                AuditLogManager::createEntry(AuditLogType::BLACKLIST_ATTACHMENT_ADDED, sprintf(
                    'Evidence %s attached to blacklist record %s by %s (%s)',
                    $evidenceUuid ?? 'none',
                    $blacklistUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid(), $blacklistUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve blacklist records', 500, $e);
            }

            self::successResponse();
        }
    }

