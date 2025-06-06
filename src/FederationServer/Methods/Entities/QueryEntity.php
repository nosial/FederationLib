<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\EntitiesManager;
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
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEntitiesPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to view entity records', 401);
            }

            $id = FederationServer::getParameter('id');
            $domain = FederationServer::getParameter('domain') ?? null;

            if(!$id)
            {
                throw new RequestException('Entity ID is required', 400);
            }

            if(!is_null($domain) && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            {
                throw new RequestException('Invalid domain format', 400);
            }

            try
            {
                $entity = EntitiesManager::getEntity($id, $domain);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve entity', 500, $e);
            }

            self::successResponse($entity->toArray());
        }
    }

