<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class GetBlacklistRecord extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration() ->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to view blacklist records', 401);
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

                $blacklistRecord = BlacklistManager::getBlacklistEntry($blacklistUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve blacklist records', 500, $e);
            }

            self::successResponse($blacklistRecord->toArray());
        }
    }

