<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

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

