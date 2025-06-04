<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;
    use FederationServer\Objects\PublicAuditRecord;

    class ListOperatorAuditLogs extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(false);
            if(!Configuration::getServerConfiguration()->isPublicAuditLogs() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: Public audit logs are disabled and no operator is authenticated', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})/audit$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Bad Request: Operator UUID is required', 400);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid)
            {
                throw new RequestException('Bad Request: Operator UUID is required', 400);
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
                if(!OperatorManager::operatorExists($operatorUuid))
                {
                    throw new RequestException('Not Found: Operator with the specified UUID does not exist', 404);
                }

                $results = array_map(fn($log) => AuditLogManager::toPublicAuditRecord($log),
                    AuditLogManager::getEntriesByOperator($operatorUuid, $limit, $page, $filteredEntries)
                );
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve audit logs', 500, $e);
            }

            self::successResponse(array_map(fn($log) => $log->toArray(), $results));
        }
    }

