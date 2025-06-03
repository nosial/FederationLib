<?php

    namespace FederationServer\Methods\Audit;

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

            $results = [];

            try
            {
                $auditLogs = AuditLogManager::getEntries($limit, $page);
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

