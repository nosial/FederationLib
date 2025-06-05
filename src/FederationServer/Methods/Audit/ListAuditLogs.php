<?php

    namespace FederationServer\Methods\Audit;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ListAuditLogs extends RequestHandler
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
                self::successResponse(array_map(fn($log) => $log->toArray(),
                    AuditLogManager::getEntries($limit, $page, $filteredEntries))
                );
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve audit logs', 500, $e);
            }

        }
    }

