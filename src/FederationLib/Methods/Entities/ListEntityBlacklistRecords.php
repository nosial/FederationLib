<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListEntityBlacklistRecords extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to list blacklist records', 401);
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

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/blacklist$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/blacklist$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException('Entity identifier is required', 400);
            }

            $entityIdentifier = $matches[1];
            if(!$entityIdentifier)
            {
                throw new RequestException('Entity Identifier SHA-256/UUID is required', 400);
            }

            try
            {
                if(Utilities::isUuid($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByUuid($entityIdentifier);
                }
                elseif(Utilities::isSha256($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByHash($entityIdentifier);
                }
                else
                {
                    throw new RequestException('Given identifier is not a valid UUID or SHA-256 input', 400);
                }

                if($entityRecord === null)
                {
                    throw new RequestException('Entity not found', 404);
                }

                $blacklistRecords = BlacklistManager::getEntriesByEntity($entityRecord->getUuid(), $limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve blacklist records from the entity', 500, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $blacklistRecords));
        }
    }

