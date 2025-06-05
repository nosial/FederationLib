<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ListEntityAuditLogs extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(false);
            if(!Configuration::getServerConfiguration()->isAuditLogsPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: Public audit logs are disabled and no operator is authenticated', 403);
            }

            if(!preg_match('#^/entities/([a-fA-F0-9\-]{36,})/audit$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Bad Request: Entity UUID is required', 400);
            }

            $entityUuid = $matches[1];
            if(!$entityUuid)
            {
                throw new RequestException('Bad Request: Entity UUID is required', 400);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListAuditLogsMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListAuditLogsMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListAuditLogsMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            $results = [];

            if($authenticatedOperator === null)
            {
                // Public audit logs are enabled, filter by public entries
                $filteredEntries = Configuration::getServerConfiguration()->getPublicAuditEntries();
            }
            else
            {
                // If an operator is authenticated, we can retrieve all entries
                $filteredEntries = null;
            }

            try
            {
                if(!EntitiesManager::entityExistsByUuid($entityUuid))
                {
                    throw new RequestException('Not Found: Entity with the specified UUID does not exist', 404);
                }

                self::successResponse(array_map(fn($log) => $log->toArray(),
                    AuditLogManager::getEntriesByEntity($entityUuid, $limit, $page, $filteredEntries))
                );
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve audit logs', 500, $e);
            }
        }
    }

