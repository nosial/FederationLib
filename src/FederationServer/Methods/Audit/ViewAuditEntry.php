<?php

    namespace FederationServer\Methods\Audit;

    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;
    use FederationServer\Objects\PublicAuditRecord;

    class ViewAuditEntry extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            if(!preg_match('#^/audit/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Bad Request: Audit UUID is required', 400);
            }

            $entryUuid = $matches[1];
            if(!$entryUuid || !Validate::uuid($entryUuid))
            {
                throw new RequestException('Bad Request: Invalid Audit UUID', 400);
            }

            try
            {
                $logRecord = AuditLogManager::getEntry($entryUuid);
                if(!$logRecord)
                {
                    throw new RequestException('Audit log not found', 404);
                }

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

                $result = new PublicAuditRecord($logRecord, $operatorRecord, $entityRecord);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve audit log', 500, $e);
            }

            self::successResponse($result->toArray());
        }
    }

