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
            if(!preg_match('#^/entities/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Bad Request: Entity UUID is required', 400);
            }

            $entityUuid = $matches[1];
            if(!$entityUuid || !Validate::uuid($entityUuid))
            {
                throw new RequestException('Bad Request: Entity UUID is required', 400);
            }

            try
            {
                if(!EntitiesManager::entityExistsByUuid($entityUuid))
                {
                    throw new RequestException('Not Found: Entity does not exist', 404);
                }

                EntitiesManager::deleteEntity($entityUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to delete entity', 500, $e);
            }

            self::successResponse();
        }
    }

