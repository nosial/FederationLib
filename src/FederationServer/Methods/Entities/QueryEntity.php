<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class QueryEntity extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $id = FederationServer::getParameter('id');
            $domain = FederationServer::getParameter('domain') ?? null;

            if(!$id)
            {
                throw new RequestException('Bad Request: Entity ID is required', 400);
            }

            if(!is_null($domain) && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            {
                throw new RequestException('Bad Request: Invalid domain format', 400);
            }

            try
            {
                $entitiy = EntitiesManager::getEntity($id, $domain);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve entity', 500, $e);
            }

            self::successResponse($entitiy->toArray());
        }
    }

