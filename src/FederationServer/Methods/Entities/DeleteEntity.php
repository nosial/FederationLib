<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class DeleteEntity extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('Insufficient permissions to manage entities', 401);
            }

            if(!preg_match('#^/entities/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Entity UUID is required', 400);
            }

            $entityUuid = $matches[1];
            if(!$entityUuid || !Validate::uuid($entityUuid))
            {
                throw new RequestException('Entity UUID is required', 400);
            }

            try
            {
                if(!EntitiesManager::entityExistsByUuid($entityUuid))
                {
                    throw new RequestException('Entity does not exist', 404);
                }

                EntitiesManager::deleteEntity($entityUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to delete entity', 500, $e);
            }

            self::successResponse();
        }
    }

