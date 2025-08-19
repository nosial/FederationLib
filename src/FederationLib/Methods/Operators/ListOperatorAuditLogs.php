<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListOperatorAuditLogs extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isAuditLogsPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Public audit logs are disabled and no operator is authenticated', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})/audit$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID is required', 400);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid)
            {
                throw new RequestException('Operator UUID is required', 400);
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
                    throw new RequestException('Operator with the specified UUID does not exist', 404);
                }

                self::successResponse(array_map(fn($log) => $log->toArray(),
                        AuditLogManager::getEntriesByOperator($operatorUuid, $limit, $page, $filteredEntries))
                );
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve audit logs', 500, $e);
            }
        }
    }

