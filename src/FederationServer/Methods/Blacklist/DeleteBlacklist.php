<?php

    namespace FederationServer\Methods\Blacklist;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\BlacklistManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class DeleteBlacklist extends RequestHandler
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
                if(!BlacklistManager::blacklistExists($blacklistUuid))
                {
                    throw new RequestException('Blacklist record not found', 404);
                }

                BlacklistManager::deleteBlacklistRecord($blacklistUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve blacklist records', 500, $e);
            }

            self::successResponse();
        }
    }

