<?php

    namespace FederationServer\Methods\Blacklist;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\BlacklistManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ListBlacklist extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(false);
            if(!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: You must be authenticated to list blacklist records', 401);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListBlacklistMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListBlacklistMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListBlacklistMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $blacklistRecords = BlacklistManager::getEntries($limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve blacklist records', 500, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $blacklistRecords));
        }
    }

