<?php

    namespace FederationServer\Methods\Entities;

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
            $limit = (int) (FederationServer::getParameter('limit') ?? 100);
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1)
            {
                $limit = 100;
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
                throw new RequestException('Internal Server Error: Unable to retrieve operators', 500, $e);
            }

            $result = array_map(fn($op) => $op->toArray(), $operators);
            self::successResponse($result);
        }
    }

