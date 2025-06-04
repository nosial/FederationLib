<?php

    namespace FederationServer\Methods\Audit;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;
    use FederationServer\Objects\PublicAuditRecord;

    class ListAuditLogs extends RequestHandler
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
                $auditLogs = AuditLogManager::getEntries($limit, $page, $filteredEntries);
                foreach($auditLogs as $logRecord)
                {
                    $operatorRecord = null;
                    $entityRecord = null;

                    if($logRecord->getOperator() !== null)
                    {
                        $operatorRecord = OperatorManager::getOperator($logRecord->getOperator());
                    }

                    if($logRecord->getEntity() !== null)
                    {
                        $entityRecord = EntitiesManager::getEntityByUuid($logRecord->getEntity());
                    }

                    $results[] = new PublicAuditRecord($logRecord, $operatorRecord, $entityRecord);
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve audit logs', 500, $e);
            }

            self::successResponse(array_map(fn($log) => $log->toArray(), $results));
        }
    }

