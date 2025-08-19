<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListEntities extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEntitiesPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to view entity records', 401);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListEntitiesMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListEntitiesMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListEntitiesMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $operators = EntitiesManager::getEntities($limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve operators', 500, $e);
            }

            $result = array_map(fn($op) => $op->toArray(), $operators);
            self::successResponse($result);
        }
    }

