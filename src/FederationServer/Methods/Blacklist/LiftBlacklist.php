<?php

    namespace FederationServer\Methods\Blacklist;

    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\BlacklistManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Enums\AuditLogType;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class LiftBlacklist extends RequestHandler
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

            try
            {
                $blacklistRecord = BlacklistManager::getBlacklistEntry($blacklistUuid);

                if($blacklistRecord === null)
                {
                    throw new RequestException('Blacklist record not found', 404);
                }

                if($blacklistRecord->isLifted())
                {
                    throw new RequestException('Blacklist record is already lifted', 400);
                }

                BlacklistManager::liftBlacklistRecord($blacklistUuid, $authenticatedOperator->getUuid());
                AuditLogManager::createEntry(AuditLogType::BLACKLIST_LIFTED, sprintf(
                    'Blacklist record %s lifted by %s (%s)',
                    $blacklistUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid(), $blacklistRecord->getEntity());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve blacklist records', 500, $e);
            }

            self::successResponse();
        }
    }

